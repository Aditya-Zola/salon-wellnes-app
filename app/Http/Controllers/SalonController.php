<?php

namespace App\Http\Controllers;

use App\Http\Exceptions\ReservationConflictException;
use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\StoreReservationItemRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\StoreSalesReturnRequest;
use App\Http\Requests\UpdateReservationItemStatusRequest;
use App\Http\Requests\UpdateReservationStatusRequest;
use App\Http\Services\ActivityLogger;
use App\Http\Services\CheckoutService;
use App\Http\Services\ReservationService;
use App\Http\Services\SalesReturnService;
use App\Http\Services\SalonSnapshotService;
use App\Http\Services\SpreadsheetExportService;
use App\Http\Support\FixedPoint;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalonController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly CheckoutService $checkout,
        private readonly SalesReturnService $salesReturns,
        private readonly SalonSnapshotService $snapshots,
        private readonly SpreadsheetExportService $spreadsheets,
        private readonly ActivityLogger $logger,
    ) {}

    public function dashboard(Request $request)
    {
        $salonData = Schema::hasTable('reservations')
            ? $this->snapshots->forUser($request->user())
            : [];

        return view('dashboard', compact('salonData'));
    }

    public function data(Request $request): JsonResponse
    {
        if (! Schema::hasTable('reservations')) {
            return response()->json([]);
        }

        return response()->json($this->snapshots->forUser($request->user()));
    }

    public function salesPage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->snapshots->salesPage(
            $request->user(),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 20),
            $data['search'] ?? null,
            $data['payment_method'] ?? null,
        ));
    }

    public function salesReturnsPage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->snapshots->salesReturnsPage(
            $request->user(),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 20),
            $data['search'] ?? null,
            $data['payment_method'] ?? null,
        ));
    }

    public function membersPage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->snapshots->membersPage(
            $request->user(),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 10),
            $data['search'] ?? null,
        ));
    }

    public function exportSchedule(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $date = $data['date'] ?? today()->toDateString();
        $items = DB::table('reservation_items as item')
            ->join('reservations as reservation', 'reservation.id', '=', 'item.reservation_id')
            ->join('customers as customer', 'customer.id', '=', 'reservation.customer_id')
            ->leftJoin('transactions as transaction', 'transaction.reservation_id', '=', 'reservation.id')
            ->where('reservation.reservation_date', $date)
            ->where('reservation.status', '!=', 'cancelled')
            ->where('item.work_status', '!=', 'cancelled')
            ->orderBy('item.scheduled_start_at')
            ->orderBy('item.id')
            ->get([
                'item.id',
                'item.reservation_id',
                'item.treatment_name',
                'item.sort_order',
                'item.scheduled_start_at',
                'item.scheduled_end_at',
                'item.scheduled_ready_at',
                'item.unit_price',
                'item.commission_amount',
                'customer.name as customer_name',
                'transaction.status as payment_status',
            ]);
        $assignments = DB::table('reservation_item_staff as assignment')
            ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
            ->whereIn('assignment.reservation_item_id', $items->pluck('id'))
            ->orderByRaw("CASE WHEN assignment.role = 'primary' THEN 0 ELSE 1 END")
            ->orderBy('employee.name')
            ->get([
                'assignment.reservation_item_id',
                'assignment.employee_id',
                'assignment.role',
                'assignment.commission_amount',
                'employee.name',
            ]);
        $staffByItem = $assignments->groupBy('reservation_item_id');
        $payments = DB::table('transaction_payments as payment')
            ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
            ->join('payment_methods as method', 'method.id', '=', 'payment.payment_method_id')
            ->where('payment.status', 'confirmed')
            ->where('transaction.status', 'paid')
            ->whereIn('transaction.reservation_id', $items->pluck('reservation_id')->unique())
            ->get([
                'transaction.reservation_id',
                'method.name as payment_method_name',
                'payment.amount',
            ]);
        $paymentsByReservation = $payments->groupBy('reservation_id');
        $reservationTotals = $items->groupBy('reservation_id')->map(
            fn ($reservationItems): int => (int) $reservationItems->sum('unit_price')
        );
        $orderedItems = $items
            ->groupBy('reservation_id')
            ->sortBy(fn ($reservationItems) => $reservationItems->min('scheduled_start_at'))
            ->flatMap(fn ($reservationItems) => $reservationItems->sortBy(
                fn (object $item): string => str_pad((string) $item->sort_order, 6, '0', STR_PAD_LEFT)
                    .'-'.str_pad((string) $item->id, 20, '0', STR_PAD_LEFT)
            ))
            ->values();

        $rows = $orderedItems->map(function (object $item) use ($staffByItem, $paymentsByReservation, $reservationTotals): array {
            $therapists = collect($staffByItem->get($item->id, []))->pluck('name')->unique()->join(', ');
            $paymentMethods = collect($paymentsByReservation->get($item->reservation_id, []))
                ->pluck('payment_method_name')
                ->unique()
                ->join(' + ');

            return [
                'reservation_id' => (int) $item->reservation_id,
                'customer_name' => $item->customer_name,
                'treatment_name' => $item->treatment_name,
                'start_time' => $item->scheduled_start_at,
                'end_time' => $item->scheduled_end_at,
                'ready_time' => $item->scheduled_ready_at,
                'therapists' => $therapists ?: '-',
                'payment' => $paymentMethods ?: ($item->payment_status === 'paid' ? 'LUNAS' : 'BELUM DIBAYAR'),
                'reservation_total' => (int) $reservationTotals->get($item->reservation_id, 0),
                'unit_price' => (int) $item->unit_price,
                'commission_amount' => (int) $item->commission_amount,
            ];
        })->all();
        $staffSummary = $assignments
            ->groupBy('employee_id')
            ->map(fn ($employeeAssignments): array => [
                'name' => $employeeAssignments->first()->name,
                'commission' => (int) $employeeAssignments->sum('commission_amount'),
                'overtime' => 0,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
        $paymentSummary = $payments
            ->groupBy('payment_method_name')
            ->map(fn ($methodPayments, string $method): array => [
                'method' => mb_strtoupper($method),
                'amount' => (int) $methodPayments->sum('amount'),
            ])
            ->sortBy('method', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $filename = 'jadwal-selesa-'.str_replace('-', '', $date).'.xlsx';
        $scheduleDate = CarbonImmutable::parse($date);

        return response()->streamDownload(function () use ($scheduleDate, $rows, $staffSummary, $paymentSummary): void {
            echo $this->spreadsheets->makeDailySchedule(
                $scheduleDate->translatedFormat('j F Y'),
                ucfirst($scheduleDate->translatedFormat('l, j F Y')),
                $rows,
                $staffSummary,
                $paymentSummary,
            );
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportStockHistory(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? today()->toDateString();
        $movements = DB::table('stock_movements as movement')
            ->join('products as product', 'product.id', '=', 'movement.product_id')
            ->join('units as movementUnit', 'movementUnit.id', '=', 'movement.unit_id')
            ->join('units as purchaseUnit', 'purchaseUnit.id', '=', 'product.purchase_unit_id')
            ->join('units as usageUnit', 'usageUnit.id', '=', 'product.usage_unit_id')
            ->leftJoin('transactions as transaction', function ($join): void {
                $join->on('transaction.id', '=', 'movement.source_id')
                    ->whereIn('movement.source_type', ['transaction', 'transaction_sale']);
            })
            ->leftJoin('reservations as reservation', 'reservation.id', '=', 'transaction.reservation_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'reservation.customer_id')
            ->whereBetween('movement.occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderBy('movement.occurred_at')
            ->orderBy('movement.id')
            ->get([
                'movement.id',
                'movement.product_id',
                'movement.type',
                'movement.quantity',
                'movement.stock_before',
                'movement.stock_after',
                'movement.source_type',
                'movement.reference',
                'movement.notes',
                'movement.occurred_at',
                'product.name as product_name',
                'product.purchase_to_usage_factor',
                'movementUnit.code as movement_unit_code',
                'purchaseUnit.code as purchase_unit_code',
                'usageUnit.code as usage_unit_code',
                'reservation.id as reservation_id',
                'customer.name as customer_name',
            ]);
        $recipeDosesByProduct = DB::table('treatment_product_recipes as recipe')
            ->join('units as unit', 'unit.id', '=', 'recipe.unit_id')
            ->whereIn('recipe.product_id', $movements->pluck('product_id')->unique())
            ->get(['recipe.product_id', 'recipe.quantity', 'unit.code as unit_code'])
            ->groupBy('product_id');
        $reservationIds = $movements->pluck('reservation_id')->filter()->unique()->values();
        $therapistsByReservation = $reservationIds->isEmpty()
            ? collect()
            : DB::table('reservation_item_staff as assignment')
                ->join('reservation_items as item', 'item.id', '=', 'assignment.reservation_item_id')
                ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
                ->whereIn('item.reservation_id', $reservationIds)
                ->where('assignment.role', 'primary')
                ->orderBy('employee.name')
                ->get(['item.reservation_id', 'employee.name'])
                ->groupBy('reservation_id');

        $rows = $movements->values()->map(function (object $movement, int $index) use ($recipeDosesByProduct, $therapistsByReservation): array {
            $quantity = (float) $movement->quantity;
            $stockBefore = (float) $movement->stock_before;
            $stockAfter = (float) $movement->stock_after;
            $incoming = $movement->type === 'in'
                || ($movement->type === 'adjustment' && $stockAfter >= $stockBefore);
            $outgoing = $movement->type === 'out'
                || ($movement->type === 'adjustment' && $stockAfter < $stockBefore);
            $factor = max(0.0001, (float) $movement->purchase_to_usage_factor);
            $recipeDoses = collect($recipeDosesByProduct->get($movement->product_id, []))
                ->map(fn (object $recipe): float => (float) $recipe->quantity)
                ->unique(fn (float $dose): string => number_format($dose, 4, '.', ''))
                ->values();
            $canonicalDose = $recipeDoses->count() === 1 ? (float) $recipeDoses->first() : null;
            $dose = $outgoing && $movement->customer_name
                ? $quantity
                : $canonicalDose;
            $capacityBase = $incoming ? $stockAfter : $stockBefore;
            $capacity = $dose && $dose > 0 ? $capacityBase / $dose : null;
            $customersServed = $outgoing && $dose && $dose > 0 ? $quantity / $dose : null;
            $remainingCapacity = $dose && $dose > 0 ? $stockAfter / $dose : null;
            $occurredAt = CarbonImmutable::parse($movement->occurred_at);

            return [
                'number' => $index + 1,
                'product' => mb_strtoupper($movement->product_name),
                'incoming_date' => $incoming ? $occurredAt->format('Y-m-d') : null,
                'incoming_quantity' => $incoming ? $quantity / $factor : null,
                'purchase_unit' => $incoming ? mb_strtoupper($movement->purchase_unit_code) : null,
                'gross_quantity' => $incoming ? $factor : null,
                'gross_unit' => $incoming ? mb_strtoupper($movement->usage_unit_code) : null,
                'dose' => $dose,
                'dose_unit' => $dose ? mb_strtoupper($movement->usage_unit_code) : null,
                'capacity' => $capacity,
                'outgoing_date' => $outgoing ? $occurredAt->format('Y-m-d') : null,
                'outgoing_time' => $outgoing ? $occurredAt->format('H:i') : null,
                'customers_served' => $customersServed,
                'outgoing_quantity' => $outgoing ? $quantity : null,
                'outgoing_unit' => $outgoing ? mb_strtoupper($movement->movement_unit_code) : null,
                'remaining_capacity' => $remainingCapacity,
                'stock_after' => $stockAfter,
                'stock_unit' => mb_strtoupper($movement->movement_unit_code),
                'customer' => $outgoing ? ($movement->customer_name ?: null) : null,
                'therapists' => $outgoing
                    ? (collect($therapistsByReservation->get($movement->reservation_id, []))->pluck('name')->unique()->join(', ') ?: null)
                    : null,
            ];
        })->all();

        return response()->streamDownload(function () use ($rows): void {
            echo $this->spreadsheets->makeStockInOut($rows);
        }, "rekap-stok-in-out-{$from}-{$to}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function storeReservation(StoreReservationRequest $request): JsonResponse
    {
        try {
            $reservation = $this->reservations->create($request->validated(), $request);
        } catch (ReservationConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'schedule_conflict',
                'can_override' => $exception->canOverride,
                'requires_reason' => true,
                'override_permission' => 'reservations.override_conflict',
                'conflicts' => $exception->conflicts,
            ], 409);
        }

        return response()->json([
            'message' => 'Reservasi berhasil dibuat.',
            ...$reservation,
        ], 201);
    }

    public function availableTherapists(Request $request): JsonResponse
    {
        if (! $request->filled('start_time') && $request->filled('time')) {
            $request->merge(['start_time' => $request->input('time')]);
        }

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'treatment_id' => ['required', 'integer', 'exists:treatments,id'],
        ]);
        $employees = $this->reservations->availability($data['date'], $data['start_time'], (int) $data['treatment_id']);

        return response()->json([
            'employees' => $employees,
            'therapists' => $employees,
        ]);
    }

    public function therapistAttendance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $data['month'] ?? substr($data['date'], 0, 7);
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $attendance = DB::table('employees as employee')
            ->leftJoin('employee_attendances as attendance', function ($join) use ($data): void {
                $join->on('attendance.employee_id', '=', 'employee.id')
                    ->where('attendance.attendance_date', '=', $data['date']);
            })
            ->where('employee.active', true)
            ->where('employee.is_service_provider', true)
            ->orderBy('employee.name')
            ->get([
                'employee.id as employee_id',
                'employee.name',
                'employee.specialty',
                'attendance.status',
                'attendance.notes',
            ])
            ->map(fn (object $employee): array => [
                'employee_id' => (int) $employee->employee_id,
                'name' => $employee->name,
                'specialty' => $employee->specialty,
                // Belum diatur berarti dianggap masuk, sehingga tidak mengubah
                // alur reservasi yang sudah berjalan.
                'status' => $employee->status ?: 'present',
                'notes' => $employee->notes,
            ])
            ->values();

        $offByDate = DB::table('employee_attendances as attendance')
            ->join('employees as employee', 'employee.id', '=', 'attendance.employee_id')
            ->where('employee.active', true)
            ->where('employee.is_service_provider', true)
            ->where('attendance.status', 'off')
            ->whereBetween('attendance.attendance_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderBy('attendance.attendance_date')
            ->orderBy('employee.name')
            ->get([
                'attendance.attendance_date',
                'employee.id as employee_id',
                'employee.name',
            ])
            ->groupBy('attendance_date')
            ->map(fn ($attendances) => $attendances->map(fn (object $attendance): array => [
                'employee_id' => (int) $attendance->employee_id,
                'name' => $attendance->name,
            ])->values())
            ->all();

        return response()->json([
            'date' => $data['date'],
            'month' => $month,
            'therapists' => $attendance,
            'present' => $attendance->where('status', 'present')->values(),
            'off' => $attendance->where('status', 'off')->values(),
            'off_by_date' => $offByDate,
        ]);
    }

    public function updateTherapistAttendance(Request $request, int $employee): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['present', 'off'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $therapist = DB::table('employees')
            ->where('id', $employee)
            ->where('active', true)
            ->where('is_service_provider', true)
            ->first(['id', 'name']);
        abort_unless($therapist, 404, 'Therapist aktif tidak ditemukan.');

        if ($data['status'] === 'off') {
            $hasSchedule = DB::table('reservation_item_staff as staff')
                ->join('reservation_items as item', 'item.id', '=', 'staff.reservation_item_id')
                ->join('reservations as reservation', 'reservation.id', '=', 'item.reservation_id')
                ->where('staff.employee_id', $employee)
                ->where('reservation.reservation_date', $data['date'])
                ->whereNotIn('reservation.status', ['cancelled', 'completed'])
                ->whereNotIn('item.work_status', ['cancelled', 'finished'])
                ->exists();
            abort_if($hasSchedule, 422, 'Therapist masih memiliki jadwal aktif; pindahkan atau batalkan jadwal terlebih dahulu.');
        }

        $now = now();
        DB::table('employee_attendances')->updateOrInsert(
            ['employee_id' => $employee, 'attendance_date' => $data['date']],
            [
                'status' => $data['status'],
                'notes' => ($data['notes'] ?? null) ? trim($data['notes']) : null,
                'updated_by' => $request->user()?->id,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
        $this->logger->log(
            $request,
            'therapist.attendance_updated',
            'employee',
            $employee,
            "Menandai {$therapist->name} sebagai ".($data['status'] === 'off' ? 'libur' : 'masuk'),
            ['date' => $data['date'], 'status' => $data['status']],
        );

        return response()->json(['message' => 'Status kehadiran therapist diperbarui.']);
    }

    public function storeReservationItem(StoreReservationItemRequest $request, int $reservation): JsonResponse
    {
        try {
            $item = $this->reservations->addItem($reservation, $request->validated(), $request);
        } catch (ReservationConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'schedule_conflict',
                'can_override' => false,
                'conflicts' => $exception->conflicts,
            ], 409);
        }

        return response()->json([
            'message' => 'Treatment tambahan masuk ke reservasi dan invoice.',
            ...$item,
        ], 201);
    }

    public function storeReservationProduct(Request $request, int $reservation): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
        ]);

        $item = $this->reservations->addProductItem($reservation, $data, $request);

        return response()->json([
            'message' => 'Produk ditambahkan ke pesanan pelanggan.',
            ...$item,
        ], 201);
    }

    public function destroyReservationProduct(Request $request, int $reservation, int $product): JsonResponse
    {
        $this->reservations->removeProductItem($reservation, $product, $request);

        return response()->json(['message' => 'Produk dihapus dari pesanan pelanggan.']);
    }

    public function updateReservation(UpdateReservationStatusRequest $request, int $id): JsonResponse
    {
        $result = $this->reservations->updateHeaderStatus(
            $id,
            $request->validated('status'),
            $request->validated('reason'),
            $request,
        );

        return response()->json(['message' => 'Status reservasi diperbarui.', ...$result]);
    }

    public function updateReservationItemStatus(
        UpdateReservationItemStatusRequest $request,
        int $reservation,
        int $item,
    ): JsonResponse {
        $result = $this->reservations->updateItemStatus(
            $reservation,
            $item,
            $request->validated('status'),
            $request->validated('reason'),
            $request,
        );

        return response()->json(['message' => 'Status pengerjaan diperbarui.', ...$result]);
    }

    public function storeEmployee(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:employees,code'],
            'name' => ['required', 'string', 'max:150'],
            'position' => ['nullable', 'string', 'max:100'],
            'specialty' => ['nullable', 'string', 'max:150'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'unique:employees,user_id'],
            'is_service_provider' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $now = now();
        $id = DB::table('employees')->insertGetId([
            'code' => $data['code'] ?? 'EMP-'.Str::upper(Str::random(8)),
            'name' => $data['name'],
            'position' => $data['position'] ?? null,
            'specialty' => $data['specialty'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'is_service_provider' => (bool) ($data['is_service_provider'] ?? true),
            'active' => (bool) ($data['active'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->logger->log($request, 'employee.created', 'employee', $id, "Menambahkan pegawai {$data['name']}");

        return response()->json(['message' => 'Pegawai berhasil ditambahkan.', 'id' => $id], 201);
    }

    public function updateEmployee(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('employees')->where('id', $id)->exists(), 404, 'Pegawai tidak ditemukan.');
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('employees', 'code')->ignore($id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'position' => ['sometimes', 'nullable', 'string', 'max:100'],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:150'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id', Rule::unique('employees', 'user_id')->ignore($id)],
            'is_service_provider' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($data === []) {
            throw ValidationException::withMessages(['employee' => ['Tidak ada perubahan yang dikirim.']]);
        }

        DB::table('employees')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        $this->logger->log($request, 'employee.updated', 'employee', $id, 'Memperbarui data pegawai', ['fields' => array_keys($data)]);

        return response()->json(['message' => 'Pegawai berhasil diperbarui.', 'id' => $id]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        if (! $request->has('current_stock') && $request->has('stock')) {
            $request->merge(['current_stock' => $request->input('stock')]);
        }

        if ($request->filled('unit') && ! $request->filled('usage_unit_id')) {
            $unitId = DB::table('units')
                ->whereRaw('LOWER(code) = ?', [mb_strtolower((string) $request->input('unit'))])
                ->orWhereRaw('LOWER(name) = ?', [mb_strtolower((string) $request->input('unit'))])
                ->value('id');
            if ($unitId) {
                $request->merge(['usage_unit_id' => $unitId, 'purchase_unit_id' => $unitId]);
            }
        }

        if (! $request->filled('purchase_unit_id') && $request->filled('usage_unit_id')) {
            $request->merge(['purchase_unit_id' => $request->input('usage_unit_id')]);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:products,code'],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'purchase_unit_id' => ['required', 'integer', 'exists:units,id'],
            'usage_unit_id' => ['required', 'integer', 'exists:units,id'],
            'purchase_to_usage_factor' => ['nullable', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'current_stock' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'minimum_stock' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'selling_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $stock = FixedPoint::parse($data['current_stock'], FixedPoint::STOCK_SCALE);
        $conversionFactor = FixedPoint::parse($data['purchase_to_usage_factor'] ?? '1', FixedPoint::STOCK_SCALE);
        abort_if($conversionFactor === 0, 422, 'Faktor konversi satuan harus lebih dari nol.');
        $now = now();

        $id = DB::transaction(function () use ($data, $stock, $conversionFactor, $request, $now): int {
            $id = DB::table('products')->insertGetId([
                'code' => $data['code'] ?? 'PRD-'.Str::upper(Str::random(8)),
                'name' => $data['name'],
                'category' => $data['category'] ?? null,
                'purchase_unit_id' => $data['purchase_unit_id'],
                'usage_unit_id' => $data['usage_unit_id'],
                'purchase_to_usage_factor' => FixedPoint::format($conversionFactor, FixedPoint::STOCK_SCALE),
                'current_stock' => FixedPoint::format($stock, FixedPoint::STOCK_SCALE),
                'minimum_stock' => FixedPoint::format(
                    FixedPoint::parse($data['minimum_stock'], FixedPoint::STOCK_SCALE),
                    FixedPoint::STOCK_SCALE,
                ),
                'selling_price' => $data['selling_price'],
                'is_active' => true,
                'description' => $data['description'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($stock > 0) {
                DB::table('stock_movements')->insert([
                    'product_id' => $id,
                    'unit_id' => $data['usage_unit_id'],
                    'type' => 'in',
                    'quantity' => FixedPoint::format($stock, FixedPoint::STOCK_SCALE),
                    'stock_before' => FixedPoint::format(0, FixedPoint::STOCK_SCALE),
                    'stock_after' => FixedPoint::format($stock, FixedPoint::STOCK_SCALE),
                    'source_type' => 'opening_stock',
                    'reference' => null,
                    'notes' => 'Stok awal',
                    'occurred_at' => $now,
                    'created_by' => $request->user()?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->logger->log($request, 'product.created', 'product', $id, "Menambahkan produk {$data['name']}");

            return $id;
        }, 3);

        return response()->json(['message' => 'Produk berhasil ditambahkan.', 'id' => $id], 201);
    }

    public function updateProductPrice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'selling_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ]);

        $price = (int) $data['selling_price'];
        DB::transaction(function () use ($id, $price, $request): void {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_unless($product, 404, 'Produk tidak ditemukan.');

            $before = (int) $product->selling_price;
            DB::table('products')->where('id', $id)->update([
                'selling_price' => $price,
                'updated_at' => now(),
            ]);

            $this->logger->log(
                $request,
                'product.price_updated',
                'product',
                $id,
                "Harga jual {$product->name} diperbarui",
                ['before' => $before, 'after' => $price],
            );
        }, 3);

        return response()->json(['message' => 'Harga jual berhasil diperbarui.', 'selling_price' => $price]);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'minimum_stock' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'selling_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $id, $request): void {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_unless($product, 404, 'Produk tidak ditemukan.');

            $unitId = (int) $data['unit_id'];
            $now = now();
            $before = [
                'name' => $product->name,
                'category' => $product->category,
                'unit_id' => (int) $product->usage_unit_id,
                'minimum_stock' => $product->minimum_stock,
                'selling_price' => (int) $product->selling_price,
                'is_active' => (bool) $product->is_active,
            ];

            DB::table('products')->where('id', $id)->update([
                'name' => $data['name'],
                'category' => $data['category'] ?: null,
                // Form edit ini memperbaiki satuan master tunggal produk. Jumlah stok
                // tidak dikonversi otomatis agar tidak mengubah saldo tanpa persetujuan.
                'purchase_unit_id' => $unitId,
                'usage_unit_id' => $unitId,
                'purchase_to_usage_factor' => FixedPoint::format(FixedPoint::parse('1', FixedPoint::STOCK_SCALE), FixedPoint::STOCK_SCALE),
                'minimum_stock' => FixedPoint::format(
                    FixedPoint::parse($data['minimum_stock'], FixedPoint::STOCK_SCALE),
                    FixedPoint::STOCK_SCALE,
                ),
                'selling_price' => (int) $data['selling_price'],
                'description' => $data['description'] ?: null,
                'is_active' => (bool) $data['is_active'],
                'updated_at' => $now,
            ]);

            if ((int) $product->usage_unit_id !== $unitId || (int) $product->purchase_unit_id !== $unitId) {
                // Resep aktif harus selalu memakai satuan yang valid untuk produk.
                // Riwayat pergerakan stok sengaja tidak diubah sebagai jejak audit.
                DB::table('treatment_product_recipes')
                    ->where('product_id', $id)
                    ->update(['unit_id' => $unitId, 'updated_at' => $now]);
            }

            $this->logger->log(
                $request,
                'product.updated',
                'product',
                $id,
                "Data produk {$product->name} diperbarui",
                ['before' => $before, 'after' => ['name' => $data['name'], 'unit_id' => $unitId]],
            );
        }, 3);

        return response()->json(['message' => 'Data produk berhasil diperbarui.', 'id' => $id]);
    }

    public function adjustStock(Request $request, int $id): JsonResponse
    {
        $aliases = ['masuk' => 'in', 'keluar' => 'out', 'opname' => 'adjustment'];
        $request->merge(['type' => $aliases[$request->input('type')] ?? $request->input('type')]);
        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out', 'adjustment'])],
            'quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'source' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($data, $id, $request): void {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_unless($product, 404, 'Produk tidak ditemukan.');
            $before = FixedPoint::parse((string) $product->current_stock, FixedPoint::STOCK_SCALE);
            $quantity = FixedPoint::parse($data['quantity'], FixedPoint::STOCK_SCALE);
            abort_if($quantity === 0, 422, 'Jumlah perubahan stok harus lebih dari nol.');

            $after = match ($data['type']) {
                'in' => $before + $quantity,
                'out' => $before - $quantity,
                'adjustment' => $quantity,
            };
            abort_if($after < 0, 422, 'Stok tidak mencukupi.');
            $movementQuantity = $data['type'] === 'adjustment' ? abs($after - $before) : $quantity;
            $now = now();

            DB::table('products')->where('id', $id)->update([
                'current_stock' => FixedPoint::format($after, FixedPoint::STOCK_SCALE),
                'updated_at' => $now,
            ]);
            DB::table('stock_movements')->insert([
                'product_id' => $id,
                'unit_id' => $product->usage_unit_id,
                'type' => $data['type'],
                'quantity' => FixedPoint::format($movementQuantity, FixedPoint::STOCK_SCALE),
                'stock_before' => FixedPoint::format($before, FixedPoint::STOCK_SCALE),
                'stock_after' => FixedPoint::format($after, FixedPoint::STOCK_SCALE),
                'source_type' => 'manual_adjustment',
                'reference' => null,
                'notes' => trim($data['source'].($data['notes'] ?? '' ? ' · '.$data['notes'] : '')),
                'occurred_at' => $now,
                'created_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->logger->log(
                $request,
                'stock.adjusted',
                'product',
                $id,
                "Stok {$product->name} diperbarui",
                ['type' => $data['type'], 'before' => FixedPoint::format($before, 4), 'after' => FixedPoint::format($after, 4)],
            );
        }, 3);

        return response()->json(['message' => 'Stok berhasil diperbarui.']);
    }

    public function storeTreatment(Request $request): JsonResponse
    {
        if (! $request->has('normal_price') && $request->has('price')) {
            $request->merge(['normal_price' => $request->input('price')]);
        }
        if (! $request->has('default_commission_percent') && $request->has('commission_percent')) {
            $request->merge(['default_commission_percent' => $request->input('commission_percent')]);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:treatments,code'],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:treatment_categories,id'],
            'category' => ['required_without:category_id', 'string', 'max:100'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'normal_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'default_commission_percent' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $commission = FixedPoint::parse($data['default_commission_percent'], FixedPoint::PERCENT_SCALE);
        abort_if($commission > 100 * (10 ** FixedPoint::PERCENT_SCALE), 422, 'Persentase komisi tidak boleh lebih dari 100.');

        $id = DB::transaction(function () use ($data, $request): int {
            $categoryId = $data['category_id'] ?? $this->resolveOrCreateTreatmentCategory($data['category']);
            $id = DB::table('treatments')->insertGetId([
                'category_id' => $categoryId,
                'code' => $data['code'] ?? 'TRT-'.Str::upper(Str::random(8)),
                'name' => $data['name'],
                'duration_minutes' => $data['duration_minutes'],
                'normal_price' => $data['normal_price'],
                'default_commission_percent' => FixedPoint::normalizePercent($data['default_commission_percent']),
                'is_active' => true,
                'description' => $data['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->logger->log($request, 'treatment.created', 'treatment', $id, "Menambahkan treatment {$data['name']}");

            return $id;
        }, 3);

        return response()->json(['message' => 'Treatment berhasil ditambahkan.', 'id' => $id], 201);
    }

    public function updateTreatmentCommission(Request $request, int $id): JsonResponse
    {
        $treatment = DB::table('treatments')->where('id', $id)->first();
        abort_unless($treatment, 404, 'Treatment tidak ditemukan.');
        $data = $request->validate([
            'default_commission_percent' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
        ]);
        $commission = FixedPoint::parse($data['default_commission_percent'], FixedPoint::PERCENT_SCALE);
        abort_if($commission > 100 * (10 ** FixedPoint::PERCENT_SCALE), 422, 'Persentase komisi tidak boleh lebih dari 100.');

        DB::table('treatments')->where('id', $id)->update([
            'default_commission_percent' => FixedPoint::normalizePercent($data['default_commission_percent']),
            'updated_at' => now(),
        ]);
        $this->logger->log(
            $request,
            'treatment.commission_updated',
            'treatment',
            $id,
            "Memperbarui komisi treatment {$treatment->name}",
            ['default_commission_percent' => $data['default_commission_percent']],
        );

        return response()->json(['message' => 'Komisi treatment berhasil diperbarui.']);
    }

    public function updateRecipe(Request $request, int $id): JsonResponse
    {
        if ($request->has('items')) {
            return $this->replaceRecipe($request, $id);
        }

        abort_unless(DB::table('treatments')->where('id', $id)->exists(), 404, 'Treatment tidak ditemukan.');
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
        ]);
        abort_if(FixedPoint::parse($data['quantity'], FixedPoint::STOCK_SCALE) === 0, 422, 'Jumlah resep harus lebih dari nol.');
        $product = DB::table('products')->where('id', $data['product_id'])->first();
        $unitId = (int) ($data['unit_id'] ?? $product->usage_unit_id);

        if (! in_array($unitId, [(int) $product->purchase_unit_id, (int) $product->usage_unit_id], true)) {
            throw ValidationException::withMessages([
                'unit_id' => ['Satuan resep harus sama dengan satuan pembelian atau satuan pemakaian produk.'],
            ]);
        }
        $identity = ['treatment_id' => $id, 'product_id' => $data['product_id']];
        $values = [
            'unit_id' => $unitId,
            'quantity' => FixedPoint::format(FixedPoint::parse($data['quantity'], 4), 4),
            'updated_at' => now(),
        ];
        DB::table('treatment_product_recipes')->upsert(
            [[...$identity, ...$values, 'created_at' => now()]],
            ['treatment_id', 'product_id'],
            ['unit_id', 'quantity', 'updated_at'],
        );
        $this->logger->log($request, 'treatment.recipe_updated', 'treatment', $id, 'Memperbarui komposisi produk treatment');

        return response()->json(['message' => 'Resep treatment berhasil diperbarui.']);
    }

    public function storeMember(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $id = DB::transaction(function () use ($data, $request): int {
            if (! empty($data['email'])) {
                $emailOwner = DB::table('customers')
                    ->where('email', $data['email'])
                    ->where('phone', '!=', $data['phone'])
                    ->exists();

                if ($emailOwner) {
                    throw ValidationException::withMessages(['email' => ['Email sudah digunakan pelanggan lain.']]);
                }
            }

            $now = now();
            $updateColumns = ['name', 'is_member', 'is_active', 'updated_at'];
            if (array_key_exists('email', $data)) {
                $updateColumns[] = 'email';
            }
            DB::table('customers')->upsert([[
                'code' => 'CUS-'.Str::upper((string) Str::ulid()),
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'is_member' => true,
                'member_since' => today(),
                'visit_count' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['phone'], $updateColumns);
            $id = (int) DB::table('customers')->where('phone', $data['phone'])->lockForUpdate()->value('id');

            $this->logger->log($request, 'membership.activated', 'customer', $id, "Mengaktifkan membership {$data['name']}");

            return $id;
        }, 3);

        return response()->json(['message' => 'Membership berhasil diaktifkan.', 'id' => $id], 201);
    }

    public function updateMember(Request $request, int $id): JsonResponse
    {
        abort_unless(
            DB::table('customers')->where('id', $id)->where('is_member', true)->exists(),
            404,
            'Member tidak ditemukan.',
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('customers', 'phone')->ignore($id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($id)],
        ]);

        DB::table('customers')->where('id', $id)->update([
            ...$data,
            'updated_at' => now(),
        ]);
        $this->logger->log($request, 'membership.updated', 'customer', $id, "Memperbarui member {$data['name']}");

        return response()->json(['message' => 'Data member berhasil diperbarui.', 'id' => $id]);
    }

    public function destroyMember(Request $request, int $id): JsonResponse
    {
        $member = DB::table('customers')->where('id', $id)->where('is_member', true)->first(['id', 'name']);
        abort_unless($member, 404, 'Member tidak ditemukan.');

        // Riwayat reservasi dan transaksi tetap terhubung ke pelanggan yang sama.
        DB::table('customers')->where('id', $id)->update([
            'is_member' => false,
            'updated_at' => now(),
        ]);
        $this->logger->log($request, 'membership.deactivated', 'customer', $id, "Mencabut status member {$member->name}");

        return response()->json(['message' => 'Status membership berhasil dicabut.']);
    }

    public function storePromotion(Request $request): JsonResponse
    {
        $data = $this->validatedPromotion($request);
        $id = DB::table('promotions')->insertGetId([
            'code' => 'PRM-'.Str::upper(Str::random(8)),
            ...$data,
            'discount_type' => 'percent',
            'discount_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->logger->log($request, 'promotion.created', 'promotion', $id, "Menambahkan event {$data['name']}");

        return response()->json(['message' => 'Event membership berhasil ditambahkan.', 'id' => $id], 201);
    }

    public function updatePromotion(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('promotions')->where('id', $id)->exists(), 404, 'Event tidak ditemukan.');

        $data = $this->validatedPromotion($request);
        DB::table('promotions')->where('id', $id)->update([
            ...$data,
            'discount_type' => 'percent',
            'discount_amount' => 0,
            'updated_at' => now(),
        ]);
        $this->logger->log($request, 'promotion.updated', 'promotion', $id, "Memperbarui event {$data['name']}");

        return response()->json(['message' => 'Event membership berhasil diperbarui.', 'id' => $id]);
    }

    public function destroyPromotion(Request $request, int $id): JsonResponse
    {
        $promotion = DB::table('promotions')->where('id', $id)->first(['id', 'name']);
        abort_unless($promotion, 404, 'Event tidak ditemukan.');

        DB::table('promotions')->where('id', $id)->delete();
        $this->logger->log($request, 'promotion.deleted', 'promotion', $id, "Menghapus event {$promotion->name}");

        return response()->json(['message' => 'Event membership berhasil dihapus.']);
    }

    /** @return array{name: string, discount_percent: string, starts_at: string, ends_at: string, members_only: bool, is_active: bool, description: string|null} */
    private function validatedPromotion(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'discount_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'ends_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_at'],
            'members_only' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        return [
            ...$data,
            'discount_percent' => FixedPoint::normalizePercent((string) $data['discount_percent']),
            'members_only' => (bool) $data['members_only'],
            'is_active' => (bool) $data['is_active'],
        ];
    }

    public function storePayment(CheckoutRequest $request): JsonResponse
    {
        $transaction = $this->checkout->checkout($request->validated(), $request);
        $status = $transaction['idempotent_replay'] ? 200 : 201;

        return response()->json([
            'message' => $transaction['idempotent_replay']
                ? 'Pembayaran sudah pernah diproses.'
                : 'Pembayaran berhasil diproses.',
            ...$transaction,
        ], $status);
    }

    public function invoicePdf(int $transaction): Response
    {
        $invoice = DB::table('transactions as transaction')
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->leftJoin('users as cashier', 'cashier.id', '=', 'transaction.finalized_by')
            ->where('transaction.id', $transaction)
            ->where('transaction.status', 'paid')
            ->first([
                'transaction.*',
                'customer.name as customer_name',
                'cashier.name as cashier_name',
            ]);
        abort_unless($invoice, 404, 'Nota transaksi tidak ditemukan.');

        $items = DB::table('transaction_items')
            ->where('transaction_id', $invoice->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $returnedQuantities = DB::table('sales_return_items as item')
            ->join('sales_returns as sales_return', 'sales_return.id', '=', 'item.sales_return_id')
            ->where('sales_return.transaction_id', $invoice->id)
            ->where('sales_return.status', 'posted')
            ->select('item.transaction_item_id', DB::raw('SUM(item.quantity) as quantity'))
            ->groupBy('item.transaction_item_id')
            ->pluck('quantity', 'transaction_item_id');
        $items->each(function (object $item) use ($returnedQuantities): void {
            $item->returned_quantity = (string) ($returnedQuantities->get($item->id) ?? '0.0000');
        });
        $payments = DB::table('transaction_payments as payment')
            ->join('payment_methods as method', 'method.id', '=', 'payment.payment_method_id')
            ->where('payment.transaction_id', $invoice->id)
            ->where('payment.status', 'confirmed')
            ->orderBy('payment.id')
            ->get(['payment.*', 'method.name as method_name', 'method.is_cash']);
        $therapists = DB::table('transaction_items as transaction_item')
            ->join('reservation_item_staff as assignment', 'assignment.reservation_item_id', '=', 'transaction_item.reservation_item_id')
            ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
            ->where('transaction_item.transaction_id', $invoice->id)
            ->orderBy('employee.name')
            ->pluck('employee.name')
            ->unique()
            ->values();
        $logoPath = public_path('images/selesa-logo.png');
        $logoDataUri = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        return Pdf::loadView('pdf.invoice', compact('invoice', 'items', 'payments', 'therapists', 'logoDataUri'))
            ->setPaper('a4')
            ->stream($invoice->number.'.pdf');
    }

    public function storeSalesReturn(StoreSalesReturnRequest $request, int $transaction): JsonResponse
    {
        $salesReturn = $this->salesReturns->create($transaction, $request->validated(), $request);

        return response()->json([
            'message' => $salesReturn['idempotent_replay']
                ? 'Retur sudah pernah diproses.'
                : 'Retur dan pengembalian dana berhasil diproses.',
            ...$salesReturn,
        ], $salesReturn['idempotent_replay'] ? 200 : 201);
    }

    public function salesReturnPdf(int $salesReturn): Response
    {
        $return = DB::table('sales_returns as sales_return')
            ->join('transactions as transaction', 'transaction.id', '=', 'sales_return.transaction_id')
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->join('payment_methods as method', 'method.id', '=', 'sales_return.refund_payment_method_id')
            ->leftJoin('users as user', 'user.id', '=', 'sales_return.created_by')
            ->where('sales_return.id', $salesReturn)
            ->where('sales_return.status', 'posted')
            ->first([
                'sales_return.*',
                'transaction.number as transaction_number',
                'transaction.transacted_at',
                'customer.name as customer_name',
                'method.name as payment_method_name',
                'user.name as created_by_name',
            ]);
        abort_unless($return, 404, 'Struk retur tidak ditemukan.');

        $items = DB::table('sales_return_items')
            ->where('sales_return_id', $return->id)
            ->orderBy('id')
            ->get();
        $logoPath = public_path('images/selesa-logo.png');
        $logoDataUri = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $reasonLines = max(1, (int) ceil(strlen((string) $return->reason) / 42));
        $receiptHeight = max(440, 370 + ($items->count() * 38) + ($reasonLines * 12));

        return Pdf::loadView('pdf.sales-return', compact('return', 'items', 'logoDataUri'))
            ->setPaper([0, 0, 164.41, $receiptHeight])
            ->stream($return->number.'.pdf');
    }

    public function storeCashEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $id = DB::transaction(function () use ($data, $request): int {
            $now = now();
            $id = DB::table('cash_entries')->insertGetId([
                'type' => $data['type'],
                'category' => trim($data['category']),
                'description' => trim($data['description']),
                'amount' => $data['amount'],
                'entry_date' => $data['entry_date'],
                'status' => 'posted',
                'created_by' => $request->user()?->id,
                'approved_by' => $request->user()?->id,
                'approved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $typeLabel = $data['type'] === 'income' ? 'pemasukan' : 'pengeluaran';
            $this->logger->log(
                $request,
                'finance.cash_entry_created',
                'cash_entry',
                $id,
                "Mencatat {$typeLabel}: {$data['category']}",
                ['type' => $data['type'], 'amount' => $data['amount'], 'entry_date' => $data['entry_date']],
            );

            return $id;
        }, 3);

        return response()->json([
            'message' => 'Arus kas berhasil dicatat.',
            'id' => $id,
        ], 201);
    }

    public function storePayroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'period' => ['required', 'date_format:Y-m'],
            'base_salary' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'bonus' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'overtime' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'late_deduction' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'other_deduction' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'late_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        $id = DB::transaction(function () use ($data, $request): int {
            $employee = DB::table('employees')
                ->where('id', $data['employee_id'])
                ->where('active', true)
                ->lockForUpdate()
                ->first();
            abort_unless($employee, 422, 'Karyawan tidak aktif atau tidak ditemukan.');

            if (DB::table('payrolls')
                ->where('employee_id', $employee->id)
                ->where('period', $data['period'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Penggajian karyawan ini untuk periode tersebut sudah dibuat.'],
                ]);
            }

            $bonus = (int) ($data['bonus'] ?? 0);
            $overtime = (int) ($data['overtime'] ?? 0);
            $lateDeduction = (int) ($data['late_deduction'] ?? 0);
            $otherDeduction = (int) ($data['other_deduction'] ?? 0);
            $commission = $this->payrollCommission((int) $employee->id, $data['period']);
            $gross = (int) $data['base_salary'] + $bonus + $overtime + $commission;
            $deductions = $lateDeduction + $otherDeduction;
            abort_if($deductions > $gross, 422, 'Total potongan tidak boleh melebihi pendapatan.');

            $now = now();
            $id = DB::table('payrolls')->insertGetId([
                'employee_id' => $employee->id,
                'period' => $data['period'],
                'employee_name' => $employee->name,
                'position' => $employee->position,
                'base_salary' => $data['base_salary'],
                'bonus' => $bonus,
                'overtime' => $overtime,
                'commission' => $commission,
                'late_deduction' => $lateDeduction,
                'other_deduction' => $otherDeduction,
                'net_salary' => $gross - $deductions,
                'late_duration_minutes' => (int) ($data['late_duration_minutes'] ?? 0),
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->logger->log(
                $request,
                'payroll.created',
                'payroll',
                $id,
                "Membuat penggajian {$employee->name} periode {$data['period']}",
                ['employee_id' => (int) $employee->id, 'period' => $data['period']],
            );

            return $id;
        }, 3);

        return response()->json([
            'message' => 'Data penggajian berhasil ditambahkan.',
            'id' => $id,
        ], 201);
    }

    public function updatePayroll(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'base_salary' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'bonus' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'overtime' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'late_deduction' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'other_deduction' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'late_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        DB::transaction(function () use ($id, $data, $request): void {
            $payroll = DB::table('payrolls')->where('id', $id)->lockForUpdate()->first();
            abort_unless($payroll, 404, 'Data penggajian tidak ditemukan.');
            abort_if($payroll->status !== 'draft', 422, 'Penggajian yang sudah difinalisasi tidak dapat diubah.');

            $overtime = (int) ($data['overtime'] ?? $payroll->overtime);
            // Komisi adalah hasil transaksi layanan, bukan nilai yang diinput manual.
            $commission = $this->payrollCommission((int) $payroll->employee_id, $payroll->period);
            $otherDeduction = (int) ($data['other_deduction'] ?? $payroll->other_deduction);
            $gross = (int) $data['base_salary'] + (int) $data['bonus'] + $overtime + $commission;
            $deductions = (int) $data['late_deduction'] + $otherDeduction;
            abort_if($deductions > $gross, 422, 'Total potongan tidak boleh melebihi pendapatan.');

            DB::table('payrolls')->where('id', $id)->update([
                'base_salary' => $data['base_salary'],
                'bonus' => $data['bonus'],
                'overtime' => $overtime,
                'commission' => $commission,
                'late_deduction' => $data['late_deduction'],
                'other_deduction' => $otherDeduction,
                'net_salary' => $gross - $deductions,
                'late_duration_minutes' => $data['late_duration_minutes'] ?? $payroll->late_duration_minutes,
                'updated_at' => now(),
            ]);
            $this->logger->log($request, 'payroll.updated', 'payroll', $id, 'Memperbarui komponen gaji pegawai');
        }, 3);

        return response()->json(['message' => 'Data gaji berhasil diperbarui.']);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $currencyColumns
     */
    private function spreadsheetResponse(
        string $filename,
        string $title,
        string $sheetName,
        array $headers,
        array $rows,
        array $currencyColumns = [],
    ): StreamedResponse {
        return response()->streamDownload(function () use ($sheetName, $title, $headers, $rows, $currencyColumns): void {
            echo $this->spreadsheets->make($sheetName, $title, $headers, $rows, $currencyColumns);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function stockSourceLabel(?string $source): string
    {
        return match ($source) {
            'opening_stock' => 'Stok awal',
            'manual_adjustment' => 'Penyesuaian manual',
            'transaction' => 'Pemakaian resep treatment',
            'transaction_sale' => 'Penjualan produk',
            default => $source ?: '-',
        };
    }

    private function payrollCommission(int $employeeId, string $period): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period)->startOfMonth();
        $end = $start->addMonth();

        return (int) DB::table('reservation_item_staff as assignment')
            ->join('reservation_items as item', 'item.id', '=', 'assignment.reservation_item_id')
            ->join('transactions as transaction', 'transaction.reservation_id', '=', 'item.reservation_id')
            ->join('transaction_items as transaction_item', function ($join): void {
                $join->on('transaction_item.transaction_id', '=', 'transaction.id')
                    ->on('transaction_item.reservation_item_id', '=', 'item.id');
            })
            ->where('assignment.employee_id', $employeeId)
            ->where('transaction.status', 'paid')
            ->where('transaction.transacted_at', '>=', $start)
            ->where('transaction.transacted_at', '<', $end)
            ->sum('assignment.commission_amount');
    }

    private function replaceRecipe(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('treatments')->where('id', $id)->exists(), 404, 'Treatment tidak ditemukan.');

        $data = $request->validate([
            'items' => ['present', 'array', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
        ]);
        $items = collect($data['items']);
        $productIds = $items->pluck('product_id')->map(fn ($productId) => (int) $productId);

        if ($productIds->unique()->count() !== $productIds->count()) {
            throw ValidationException::withMessages([
                'items' => ['Setiap produk hanya boleh dipilih satu kali dalam resep.'],
            ]);
        }

        $now = now();

        DB::transaction(function () use ($id, $items, $productIds, $now): void {
            DB::table('treatments')->where('id', $id)->lockForUpdate()->firstOrFail();
            $products = DB::table('products')
                ->whereIn('id', $productIds)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get(['id', 'usage_unit_id'])
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => ['Satu atau lebih produk tidak aktif atau tidak ditemukan.'],
                ]);
            }

            $rows = $items->map(function (array $item) use ($id, $products, $now): array {
                $quantity = FixedPoint::parse($item['quantity'], FixedPoint::STOCK_SCALE);
                abort_if($quantity === 0, 422, 'Jumlah pemakaian resep harus lebih dari nol.');
                $product = $products->get((int) $item['product_id']);

                return [
                    'treatment_id' => $id,
                    'product_id' => (int) $item['product_id'],
                    'unit_id' => $product->usage_unit_id,
                    'quantity' => FixedPoint::format($quantity, FixedPoint::STOCK_SCALE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all();

            DB::table('treatment_product_recipes')->where('treatment_id', $id)->delete();

            if ($rows !== []) {
                DB::table('treatment_product_recipes')->insert($rows);
            }
        }, 3);

        $this->logger->log(
            $request,
            'treatment.recipe_updated',
            'treatment',
            $id,
            'Memperbarui komposisi produk treatment',
            ['product_count' => $items->count()],
        );

        return response()->json(['message' => 'Resep treatment berhasil diperbarui.']);
    }

    private function resolveOrCreateTreatmentCategory(string $name): int
    {
        $now = now();
        $existing = DB::table('treatment_categories')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');

        if ($existing) {
            DB::table('treatment_categories')->where('id', $existing)->update(['is_active' => true, 'updated_at' => $now]);

            return (int) $existing;
        }

        DB::table('treatment_categories')->upsert([[
            'code' => 'CAT-'.Str::upper(Str::random(8)),
            'name' => $name,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['name'], ['is_active', 'updated_at']);

        return (int) DB::table('treatment_categories')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');
    }
}
