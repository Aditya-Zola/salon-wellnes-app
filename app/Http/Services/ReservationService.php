<?php

namespace App\Http\Services;

use App\Http\Exceptions\ReservationConflictException;
use App\Http\Support\FixedPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    private const PREPARATION_MINUTES = 15;

    private const REST_MINUTES = 45;

    public function __construct(private readonly ActivityLogger $logger) {}

    public function create(array $data, Request $request): array
    {
        return DB::transaction(function () use ($data, $request): array {
            $treatmentIds = collect($data['items'])->pluck('treatment_id')->map(fn ($id) => (int) $id)->unique()->values();
            $employeeIds = collect($data['items'])
                ->flatMap(fn (array $item) => collect($item['staff'])->pluck('employee_id'))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values();

            $treatments = DB::table('treatments')
                ->whereIn('id', $treatmentIds)
                ->where('is_active', true)
                ->get()
                ->keyBy('id');

            if ($treatments->count() !== $treatmentIds->count()) {
                throw ValidationException::withMessages([
                    'items' => ['Semua treatment harus tersedia dan aktif.'],
                ]);
            }
            $commissionProfiles = $this->commissionProfilesForTreatments($treatmentIds);

            // Lock every selected employee in a stable order. Reservation writers that
            // involve the same employee are serialized, including when no schedule row exists yet.
            $employees = DB::table('employees')
                ->whereIn('id', $employeeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($employees->count() !== $employeeIds->count() || $employees->contains(
                fn (object $employee): bool => ! $employee->active || ! $employee->is_service_provider
            )) {
                throw ValidationException::withMessages([
                    'items' => ['Semua pegawai yang ditugaskan harus aktif sebagai penyedia layanan.'],
                ]);
            }

            $this->ensureStaffAreWorking($employeeIds, $data['date']);

            $candidates = $this->buildCandidates($data, $treatments);
            $this->validateStaffAssignments($candidates);
            $this->authorizePriceOverrides($candidates, $request);
            [$conflicts, $conflictAssignments] = $this->findConflicts($candidates, $employees);

            $override = (bool) ($data['override_conflict'] ?? false);
            $canOverride = (bool) $request->user()?->can('reservations.override_conflict');

            if ($conflicts !== [] && ! $override) {
                throw new ReservationConflictException($conflicts, $canOverride);
            }

            if ($conflicts !== [] && (! $canOverride || trim((string) ($data['override_reason'] ?? '')) === '')) {
                throw ValidationException::withMessages([
                    'override_reason' => ['Override konflik memerlukan izin khusus dan alasan.'],
                ]);
            }

            $customer = $this->resolveCustomer($data);
            $customerId = (int) $customer->id;
            $bookingCode = 'RSV-'.Str::upper((string) Str::ulid());
            $temporaryQueue = 'TMP-'.Str::upper(Str::random(12));
            $firstStart = collect($candidates)->min(fn (array $item) => $item['start']->getTimestamp());
            $reservationTime = CarbonImmutable::createFromTimestamp($firstStart, config('app.timezone'))->format('H:i:s');
            $now = now();

            $reservationId = DB::table('reservations')->insertGetId([
                'booking_code' => $bookingCode,
                'queue_number' => $temporaryQueue,
                'customer_id' => $customerId,
                'reservation_date' => $data['date'],
                'reservation_time' => $reservationTime,
                'source' => $data['source'],
                'status' => 'scheduled',
                'general_notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // The primary key makes this display number concurrency-safe; it does not
            // depend on COUNT(*) or a read-then-increment sequence.
            $queueNumber = 'A'.str_pad((string) $reservationId, 6, '0', STR_PAD_LEFT);
            DB::table('reservations')->where('id', $reservationId)->update([
                'queue_number' => $queueNumber,
                'updated_at' => $now,
            ]);

            $createdItems = [];

            foreach ($candidates as $itemIndex => $candidate) {
                $treatment = $candidate['treatment'];
                $unitPrice = array_key_exists('actual_price', $candidate['input']) && $candidate['input']['actual_price'] !== null
                    ? (int) $candidate['input']['actual_price']
                    : (int) $treatment->normal_price;
                $commission = $this->commissionAllocation(
                    $treatment,
                    $unitPrice,
                    $candidate['input']['staff'],
                    $commissionProfiles->get($treatment->id, collect()),
                );

                $itemId = DB::table('reservation_items')->insertGetId([
                    'reservation_id' => $reservationId,
                    'treatment_id' => $treatment->id,
                    'treatment_name' => $treatment->name,
                    'duration_minutes' => $treatment->duration_minutes,
                    'normal_price' => $treatment->normal_price,
                    'unit_price' => $unitPrice,
                    'discount_percent' => FixedPoint::normalizePercent(0),
                    'discount_amount' => 0,
                    'net_price' => $unitPrice,
                    'commission_percent' => $commission['total_percent'],
                    'commission_amount' => $commission['total_amount'],
                    'scheduled_start_at' => $candidate['start'],
                    'scheduled_end_at' => $candidate['end'],
                    'scheduled_ready_at' => $candidate['ready'],
                    'work_status' => 'waiting',
                    'notes' => $candidate['input']['notes'] ?? null,
                    'sort_order' => $itemIndex,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $createdStaff = [];

                foreach ($candidate['input']['staff'] as $staff) {
                    $employeeId = (int) $staff['employee_id'];
                    $employee = $employees->get($employeeId);
                    $assignmentKey = $itemIndex.':'.$employeeId;
                    $wasOverridden = isset($conflictAssignments[$assignmentKey]);
                    $staffCommission = $commission['by_employee'][$employeeId];

                    DB::table('reservation_item_staff')->insert([
                        'reservation_item_id' => $itemId,
                        'employee_id' => $employeeId,
                        'role' => $staff['role'],
                        'commission_percent' => $staffCommission['percent'],
                        'commission_amount' => $staffCommission['amount'],
                        'conflict_override_reason' => $wasOverridden ? $data['override_reason'] : null,
                        'conflict_overridden_by' => $wasOverridden ? $request->user()?->id : null,
                        'conflict_overridden_at' => $wasOverridden ? $now : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $createdStaff[] = [
                        'employee_id' => $employeeId,
                        'employee_code' => $employee->code,
                        'employee_name' => $employee->name,
                        'position' => $employee->position,
                        'specialty' => $employee->specialty,
                        'role' => $staff['role'],
                        'commission_percent' => $staffCommission['percent'],
                        'commission_amount' => $staffCommission['amount'],
                        'conflict_overridden' => $wasOverridden,
                        ...($wasOverridden ? [
                            'conflict_override_reason' => $data['override_reason'],
                            'conflict_overridden_at' => $now->toDateTimeString(),
                        ] : []),
                    ];
                }

                $createdItems[] = [
                    'id' => $itemId,
                    'treatment_id' => (int) $treatment->id,
                    'treatment_name' => $treatment->name,
                    'duration_minutes' => (int) $treatment->duration_minutes,
                    'normal_price' => (int) $treatment->normal_price,
                    'unit_price' => $unitPrice,
                    'discount_percent' => FixedPoint::normalizePercent(0),
                    'discount_amount' => 0,
                    'net_price' => $unitPrice,
                    'commission_percent' => $commission['total_percent'],
                    'commission_amount' => $commission['total_amount'],
                    'scheduled_start_at' => $candidate['start']->format('Y-m-d H:i:s'),
                    'scheduled_end_at' => $candidate['end']->format('Y-m-d H:i:s'),
                    'scheduled_ready_at' => $candidate['ready']->format('Y-m-d H:i:s'),
                    'start_at' => $candidate['start']->toIso8601String(),
                    'end_at' => $candidate['end']->toIso8601String(),
                    'work_status' => 'waiting',
                    'notes' => $candidate['input']['notes'] ?? null,
                    'sort_order' => $itemIndex,
                    'staff' => $createdStaff,
                ];
            }

            $this->logger->log(
                $request,
                'reservation.created',
                'reservation',
                $reservationId,
                "Membuat reservasi {$bookingCode} untuk {$customer->name}",
                [
                    'booking_code' => $bookingCode,
                    'item_count' => count($createdItems),
                    'employee_ids' => $employeeIds->all(),
                    'price_overrides' => collect($candidates)
                        ->map(function (array $candidate, int $index): ?array {
                            $actualPrice = $candidate['input']['actual_price'] ?? null;

                            if ($actualPrice === null || (int) $actualPrice === (int) $candidate['treatment']->normal_price) {
                                return null;
                            }

                            return [
                                'item_index' => $index,
                                'treatment_id' => (int) $candidate['treatment']->id,
                                'normal_price' => (int) $candidate['treatment']->normal_price,
                                'actual_price' => (int) $actualPrice,
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all(),
                    'conflict_overridden' => $conflicts !== [],
                    'override_reason' => $conflicts !== [] ? $data['override_reason'] : null,
                    'conflicts' => $conflicts,
                ],
            );

            $firstItem = $createdItems[0] ?? null;
            $primaryStaff = collect($firstItem['staff'] ?? [])->firstWhere('role', 'primary')
                ?? collect($firstItem['staff'] ?? [])->first();
            $createdReservation = [
                'id' => $reservationId,
                'booking_code' => $bookingCode,
                'queue_number' => $queueNumber,
                'customer_id' => $customerId,
                'customer_name' => $customer->name,
                'is_member' => (bool) $customer->is_member,
                'reservation_date' => $data['date'],
                'reservation_time' => $reservationTime,
                'source' => $data['source'],
                'status' => 'scheduled',
                'notes' => $data['notes'] ?? null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
                'is_paid' => false,
                'items' => $createdItems,
                'product_items' => [],
                'treatment_id' => $firstItem['treatment_id'] ?? null,
                'treatment_name' => $firstItem['treatment_name'] ?? null,
                'price' => $firstItem['unit_price'] ?? 0,
                'therapist_id' => $primaryStaff['employee_id'] ?? null,
                'therapist_name' => $primaryStaff['employee_name'] ?? null,
            ];

            if ($request->user()?->can('memberships.view') || $request->user()?->can('memberships.manage')) {
                $createdReservation['phone'] = $customer->phone;
            }

            if ($request->user()?->can('cashier.view')
                || $request->user()?->can('cashier.process')
                || $request->user()?->can('finance.view')) {
                $createdReservation['transaction_id'] = null;
                $createdReservation['transaction_status'] = null;
            }

            return [
                'id' => $reservationId,
                'booking_code' => $bookingCode,
                'queue_number' => $queueNumber,
                'status' => 'scheduled',
                'items' => $createdItems,
                'reservation' => $createdReservation,
            ];
        }, 3);
    }

    /**
     * Menambahkan layanan dari kasir sebelum invoice dibayar.
     * Layanan tetap menjadi reservation item agar jadwal, therapist, resep,
     * stok, dan komisi mengikuti alur yang sama dengan reservasi awal.
     */
    public function addItem(int $reservationId, array $data, Request $request): array
    {
        return DB::transaction(function () use ($reservationId, $data, $request): array {
            $reservation = DB::table('reservations')->where('id', $reservationId)->lockForUpdate()->first();
            abort_unless($reservation, 404, 'Reservasi tidak ditemukan.');
            abort_if($reservation->status === 'cancelled', 422, 'Reservasi yang dibatalkan tidak dapat ditambah treatment.');
            abort_if(
                DB::table('transactions')->where('reservation_id', $reservationId)->where('status', 'paid')->exists(),
                422,
                'Treatment tambahan hanya dapat dimasukkan sebelum pembayaran.',
            );

            $treatment = DB::table('treatments')
                ->where('id', (int) $data['treatment_id'])
                ->where('is_active', true)
                ->first();
            abort_unless($treatment, 422, 'Treatment tidak ditemukan atau tidak aktif.');

            $employeeIds = collect($data['staff'])
                ->pluck('employee_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();
            $employees = DB::table('employees')
                ->whereIn('id', $employeeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($employees->count() !== $employeeIds->count() || $employees->contains(
                fn (object $employee): bool => ! $employee->active || ! $employee->is_service_provider,
            )) {
                throw ValidationException::withMessages([
                    'staff' => ['Semua therapist harus aktif dan dapat menangani layanan.'],
                ]);
            }

            $this->ensureStaffAreWorking($employeeIds, $reservation->reservation_date);

            $candidate = $this->buildCandidates([
                'date' => $reservation->reservation_date,
                'items' => [$data],
            ], collect([$treatment])->keyBy('id'))[0];
            $this->validateStaffAssignments([$candidate]);
            [$conflicts] = $this->findConflicts([$candidate], $employees);
            if ($conflicts !== []) {
                throw new ReservationConflictException($conflicts, false);
            }

            $now = now();
            $commissionProfiles = $this->commissionProfilesForTreatments(collect([(int) $treatment->id]));
            $commission = $this->commissionAllocation(
                $treatment,
                (int) $treatment->normal_price,
                $data['staff'],
                $commissionProfiles->get($treatment->id, collect()),
            );
            $sortOrder = ((int) DB::table('reservation_items')->where('reservation_id', $reservationId)->max('sort_order')) + 1;
            $itemId = DB::table('reservation_items')->insertGetId([
                'reservation_id' => $reservationId,
                'treatment_id' => $treatment->id,
                'treatment_name' => $treatment->name,
                'duration_minutes' => $treatment->duration_minutes,
                'normal_price' => $treatment->normal_price,
                'unit_price' => $treatment->normal_price,
                'discount_percent' => FixedPoint::normalizePercent(0),
                'discount_amount' => 0,
                'net_price' => $treatment->normal_price,
                'commission_percent' => $commission['total_percent'],
                'commission_amount' => $commission['total_amount'],
                'scheduled_start_at' => $candidate['start'],
                'scheduled_end_at' => $candidate['end'],
                'scheduled_ready_at' => $candidate['ready'],
                'work_status' => 'waiting',
                'notes' => $data['notes'] ?? null,
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($data['staff'] as $staff) {
                $staffCommission = $commission['by_employee'][(int) $staff['employee_id']];
                DB::table('reservation_item_staff')->insert([
                    'reservation_item_id' => $itemId,
                    'employee_id' => (int) $staff['employee_id'],
                    'role' => $staff['role'],
                    'commission_percent' => $staffCommission['percent'],
                    'commission_amount' => $staffCommission['amount'],
                    'conflict_override_reason' => null,
                    'conflict_overridden_by' => null,
                    'conflict_overridden_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->syncHeaderStatus($reservationId, $request->user()?->id, null);
            $this->logger->log(
                $request,
                'reservation_item.added_from_cashier',
                'reservation_item',
                $itemId,
                "Menambahkan treatment {$treatment->name} dari kasir",
                [
                    'reservation_id' => $reservationId,
                    'treatment_id' => (int) $treatment->id,
                    'commission_percent' => $commission['total_percent'],
                    'commission_amount' => $commission['total_amount'],
                ],
            );

            return [
                'id' => $itemId,
                'reservation_id' => $reservationId,
                'treatment_name' => $treatment->name,
                'start_at' => $candidate['start']->toIso8601String(),
                'end_at' => $candidate['end']->toIso8601String(),
            ];
        }, 3);
    }

    /** @param array{product_id: int, quantity: string} $data */
    public function addProductItem(int $reservationId, array $data, Request $request): array
    {
        return DB::transaction(function () use ($reservationId, $data, $request): array {
            $reservation = $this->editableReservation($reservationId);
            $product = DB::table('products')->where('id', (int) $data['product_id'])->lockForUpdate()->first();

            if (! $product || ! $product->is_active) {
                throw ValidationException::withMessages(['product_id' => ['Produk tidak tersedia.']]);
            }

            $quantity = FixedPoint::parse((string) $data['quantity'], FixedPoint::STOCK_SCALE);
            $stock = FixedPoint::parse((string) $product->current_stock, FixedPoint::STOCK_SCALE);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => ['Jumlah produk harus lebih dari nol.']]);
            }
            if ((int) $product->selling_price <= 0) {
                throw ValidationException::withMessages(['product_id' => ["Harga jual {$product->name} belum diatur."]]);
            }

            $existing = DB::table('reservation_product_items')
                ->where('reservation_id', $reservationId)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();
            $newQuantity = $quantity + ($existing
                ? FixedPoint::parse((string) $existing->quantity, FixedPoint::STOCK_SCALE)
                : 0);
            if ($newQuantity > $stock) {
                throw ValidationException::withMessages(['quantity' => ["Stok {$product->name} tidak mencukupi."]]);
            }

            $now = now();
            DB::table('reservation_product_items')->updateOrInsert(
                ['reservation_id' => $reservationId, 'product_id' => $product->id],
                [
                    'product_name' => $product->name,
                    'unit_code' => DB::table('units')->where('id', $product->usage_unit_id)->value('code'),
                    'quantity' => FixedPoint::format($newQuantity, FixedPoint::STOCK_SCALE),
                    'unit_price' => $product->selling_price,
                    'updated_at' => $now,
                    'created_at' => $existing?->created_at ?? $now,
                ],
            );

            $this->logger->log(
                $request,
                'reservation_product.added_from_cashier',
                'reservation',
                $reservationId,
                "Menambahkan produk {$product->name} ke pesanan {$reservation->queue_number}",
                ['product_id' => (int) $product->id, 'quantity' => FixedPoint::format($newQuantity, FixedPoint::STOCK_SCALE)],
            );

            return ['reservation_id' => $reservationId, 'product_id' => (int) $product->id, 'quantity' => FixedPoint::format($newQuantity, FixedPoint::STOCK_SCALE)];
        }, 3);
    }

    public function removeProductItem(int $reservationId, int $productId, Request $request): void
    {
        DB::transaction(function () use ($reservationId, $productId, $request): void {
            $reservation = $this->editableReservation($reservationId);
            $item = DB::table('reservation_product_items')
                ->where('reservation_id', $reservationId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();
            abort_unless($item, 404, 'Produk tidak ditemukan dalam pesanan ini.');

            DB::table('reservation_product_items')->where('id', $item->id)->delete();
            $this->logger->log(
                $request,
                'reservation_product.removed_from_cashier',
                'reservation',
                $reservationId,
                "Menghapus produk {$item->product_name} dari pesanan {$reservation->queue_number}",
                ['product_id' => $productId],
            );
        }, 3);
    }

    private function editableReservation(int $reservationId): object
    {
        $reservation = DB::table('reservations')->where('id', $reservationId)->lockForUpdate()->first();
        abort_unless($reservation, 404, 'Reservasi tidak ditemukan.');
        abort_if($reservation->status === 'cancelled', 422, 'Reservasi yang dibatalkan tidak dapat diubah.');
        abort_if(
            DB::table('transactions')->where('reservation_id', $reservationId)->where('status', 'paid')->exists(),
            422,
            'Pesanan yang sudah dibayar tidak dapat diubah.',
        );

        return $reservation;
    }

    public function updateHeaderStatus(int $reservationId, string $status, ?string $reason, Request $request): array
    {
        return DB::transaction(function () use ($reservationId, $status, $reason, $request): array {
            $reservation = DB::table('reservations')->where('id', $reservationId)->lockForUpdate()->first();

            abort_unless($reservation, 404, 'Reservasi tidak ditemukan.');
            if ($status === 'cancelled') {
                $this->ensureNotPaid($reservationId);
            }

            if ($reservation->status === $status) {
                return ['id' => $reservationId, 'status' => $status, 'idempotent_replay' => true];
            }

            $transitions = [
                'scheduled' => ['arrived', 'cancelled'],
                'arrived' => ['cancelled'],
                'in_service' => ['cancelled'],
                'completed' => [],
                'cancelled' => [],
            ];

            if (! in_array($status, $transitions[$reservation->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Status tidak dapat diubah dari {$reservation->status} menjadi {$status}."],
                ]);
            }

            $now = now();

            if ($status === 'completed') {
                $unfinished = DB::table('reservation_items')
                    ->where('reservation_id', $reservationId)
                    ->whereNotIn('work_status', ['finished', 'cancelled'])
                    ->exists();

                if ($unfinished) {
                    throw ValidationException::withMessages([
                        'status' => ['Reservasi hanya dapat diselesaikan setelah semua item selesai atau dibatalkan.'],
                    ]);
                }
            }

            if ($status === 'cancelled') {
                $hasFinishedItem = DB::table('reservation_items')
                    ->where('reservation_id', $reservationId)
                    ->where('work_status', 'finished')
                    ->exists();

                if ($hasFinishedItem) {
                    throw ValidationException::withMessages([
                        'status' => ['Reservasi dengan layanan yang sudah selesai tidak boleh dibatalkan seluruhnya. Batalkan hanya item yang belum dikerjakan.'],
                    ]);
                }

                DB::table('reservation_items')
                    ->where('reservation_id', $reservationId)
                    ->whereNotIn('work_status', ['finished', 'cancelled'])
                    ->update(['work_status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);
            }

            $updates = [
                'status' => $status,
                'updated_by' => $request->user()?->id,
                'updated_at' => $now,
            ];

            if ($status === 'cancelled') {
                $updates += [
                    'cancelled_by' => $request->user()?->id,
                    'cancelled_at' => $now,
                    'cancellation_reason' => $reason ?: 'Dibatalkan dari status reservasi',
                ];
            }

            DB::table('reservations')->where('id', $reservationId)->update($updates);
            $this->logger->log(
                $request,
                'reservation.status_changed',
                'reservation',
                $reservationId,
                "Mengubah status reservasi dari {$reservation->status} menjadi {$status}",
                ['from' => $reservation->status, 'to' => $status, 'reason' => $reason],
            );

            return ['id' => $reservationId, 'status' => $status, 'idempotent_replay' => false];
        }, 3);
    }

    public function updateItemStatus(
        int $reservationId,
        int $itemId,
        string $status,
        ?string $reason,
        Request $request,
    ): array {
        return DB::transaction(function () use ($reservationId, $itemId, $status, $reason, $request): array {
            $reservation = DB::table('reservations')->where('id', $reservationId)->lockForUpdate()->first();
            abort_unless($reservation, 404, 'Reservasi tidak ditemukan.');
            abort_if($reservation->status === 'cancelled', 422, 'Reservasi yang dibatalkan tidak dapat diubah.');
            if ($status === 'cancelled') {
                $this->ensureNotPaid($reservationId);
            }

            $item = DB::table('reservation_items')
                ->where('id', $itemId)
                ->where('reservation_id', $reservationId)
                ->lockForUpdate()
                ->first();
            abort_unless($item, 404, 'Item reservasi tidak ditemukan.');

            if ($item->work_status === $status) {
                return [
                    'id' => $itemId,
                    'reservation_id' => $reservationId,
                    'work_status' => $status,
                    'reservation_status' => $reservation->status,
                    'idempotent_replay' => true,
                ];
            }

            $transitions = [
                'waiting' => ['in_progress', 'cancelled'],
                'in_progress' => ['continue', 'ready', 'finished', 'overtime', 'cancelled'],
                'continue' => ['in_progress', 'ready', 'finished', 'overtime', 'cancelled'],
                'ready' => ['in_progress', 'finished', 'overtime', 'cancelled'],
                'overtime' => ['continue', 'ready', 'finished', 'cancelled'],
                'finished' => [],
                'cancelled' => [],
            ];

            if (! in_array($status, $transitions[$item->work_status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Status item tidak dapat diubah dari {$item->work_status} menjadi {$status}."],
                ]);
            }

            $now = now();
            $timestampColumns = [
                'in_progress' => 'started_at',
                'continue' => 'continued_at',
                'ready' => 'ready_at',
                'overtime' => 'overtime_at',
                'finished' => 'finished_at',
                'cancelled' => 'cancelled_at',
            ];
            $updates = ['work_status' => $status, 'updated_at' => $now];

            if (isset($timestampColumns[$status])) {
                $column = $timestampColumns[$status];
                if ($column !== 'started_at' || $item->started_at === null) {
                    $updates[$column] = $now;
                }
            }

            DB::table('reservation_items')->where('id', $itemId)->update($updates);
            $reservationStatus = $this->syncHeaderStatus($reservationId, $request->user()?->id, $reason);
            $this->logger->log(
                $request,
                'reservation_item.status_changed',
                'reservation_item',
                $itemId,
                "Mengubah status item reservasi dari {$item->work_status} menjadi {$status}",
                [
                    'reservation_id' => $reservationId,
                    'from' => $item->work_status,
                    'to' => $status,
                    'reason' => $reason,
                ],
            );

            return [
                'id' => $itemId,
                'reservation_id' => $reservationId,
                'work_status' => $status,
                'reservation_status' => $reservationStatus,
                'idempotent_replay' => false,
            ];
        }, 3);
    }

    public function availability(string $date, string $time, int $treatmentId): array
    {
        $treatment = DB::table('treatments')->where('id', $treatmentId)->where('is_active', true)->first();
        abort_unless($treatment, 404, 'Treatment tidak ditemukan atau tidak aktif.');

        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", config('app.timezone'));
        $end = $start->addMinutes((int) $treatment->duration_minutes + self::PREPARATION_MINUTES);
        $ready = $end->addMinutes(self::REST_MINUTES);

        $offEmployeeIds = DB::table('employee_attendances')
            ->where('attendance_date', $date)
            ->where('status', 'off')
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return DB::table('employees')
            ->where('active', true)
            ->where('is_service_provider', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'position', 'specialty'])
            ->map(function (object $employee) use ($start, $end, $ready, $offEmployeeIds): array {
                $conflicts = $this->existingConflicts((int) $employee->id, $start, $ready);
                $isOff = in_array((int) $employee->id, $offEmployeeIds, true);

                return [
                    'id' => (int) $employee->id,
                    'code' => $employee->code,
                    'name' => $employee->name,
                    'position' => $employee->position,
                    'specialty' => $employee->specialty,
                    'available' => ! $isOff && $conflicts->isEmpty(),
                    'attendance_status' => $isOff ? 'off' : 'present',
                    'scheduled_end_at' => $end->toIso8601String(),
                    'ready_at' => $ready->toIso8601String(),
                    'conflicts' => $conflicts->map(fn (object $row): array => [
                        'reservation_id' => (int) $row->reservation_id,
                        'reservation_item_id' => (int) $row->reservation_item_id,
                        'booking_code' => $row->booking_code,
                        'start_at' => $row->scheduled_start_at,
                        'end_at' => $row->scheduled_end_at,
                        'ready_at' => $row->scheduled_ready_at,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** @param Collection<int, int> $employeeIds */
    private function ensureStaffAreWorking(Collection $employeeIds, string $date): void
    {
        $off = DB::table('employee_attendances')
            ->join('employees', 'employees.id', '=', 'employee_attendances.employee_id')
            ->whereIn('employee_attendances.employee_id', $employeeIds->all())
            ->where('employee_attendances.attendance_date', $date)
            ->where('employee_attendances.status', 'off')
            ->orderBy('employees.name')
            ->pluck('employees.name')
            ->all();

        if ($off !== []) {
            throw ValidationException::withMessages([
                'items' => ['Therapist libur pada tanggal tersebut: '.implode(', ', $off).'.'],
            ]);
        }
    }

    /** @param Collection<int, int> $treatmentIds */
    private function commissionProfilesForTreatments(Collection $treatmentIds): Collection
    {
        if ($treatmentIds->isEmpty()) {
            return collect();
        }

        return DB::table('treatment_commission_splits')
            ->whereIn('treatment_id', $treatmentIds->all())
            ->orderBy('therapist_count')
            ->orderBy('therapist_position')
            ->get([
                'treatment_id',
                'therapist_count',
                'therapist_position',
                'commission_percent',
            ])
            ->groupBy('treatment_id');
    }

    /**
     * Membagi satu komisi treatment ke therapist yang terpasang pada item.
     * Posisi pertama selalu therapist primary, lalu therapist pendamping sesuai
     * urutan pada reservasi. Bila belum ada profil khusus, komisi dibagi rata.
     *
     * @param  array<int, array{employee_id: int|string, role: string}>  $staff
     * @param  Collection<int, object>  $profiles
     * @return array{total_percent: string, total_amount: int, by_employee: array<int, array{percent: string, amount: int}>}
     */
    private function commissionAllocation(object $treatment, int $unitPrice, array $staff, Collection $profiles): array
    {
        $totalPercent = FixedPoint::normalizePercent((string) $treatment->default_commission_percent);
        $totalPercentScaled = FixedPoint::parse($totalPercent, FixedPoint::PERCENT_SCALE);
        $staffCount = count($staff);
        $orderedStaff = collect($staff)
            ->sortBy(fn (array $assignment): int => $assignment['role'] === 'primary' ? 0 : 1)
            ->values();
        $customPercents = $profiles
            ->where('therapist_count', $staffCount)
            ->sortBy('therapist_position')
            ->pluck('commission_percent')
            ->values()
            ->all();
        $customScaled = array_map(
            fn ($percent): int => FixedPoint::parse((string) $percent, FixedPoint::PERCENT_SCALE),
            $customPercents,
        );

        if (count($customScaled) === $staffCount && array_sum($customScaled) === $totalPercentScaled) {
            $percentages = $customScaled;
        } else {
            $basePercent = intdiv($totalPercentScaled, $staffCount);
            $remainder = $totalPercentScaled % $staffCount;
            $percentages = array_map(
                fn (int $position): int => $basePercent + ($position < $remainder ? 1 : 0),
                range(0, $staffCount - 1),
            );
        }

        $totalAmount = FixedPoint::percentOf($unitPrice, $totalPercent);
        $remainingAmount = $totalAmount;
        $remainingPercent = $totalPercentScaled;
        $amounts = [];

        foreach ($percentages as $position => $percent) {
            if ($position === array_key_last($percentages)) {
                $amount = $remainingAmount;
            } elseif ($remainingPercent === 0) {
                $amount = 0;
            } else {
                $amount = intdiv(
                    ($remainingAmount * $percent) + intdiv($remainingPercent, 2),
                    $remainingPercent,
                );
            }

            $amounts[] = $amount;
            $remainingAmount -= $amount;
            $remainingPercent -= $percent;
        }

        $byEmployee = [];
        foreach ($orderedStaff as $position => $assignment) {
            $byEmployee[(int) $assignment['employee_id']] = [
                'percent' => FixedPoint::format($percentages[$position], FixedPoint::PERCENT_SCALE),
                'amount' => $amounts[$position],
            ];
        }

        return [
            'total_percent' => $totalPercent,
            'total_amount' => $totalAmount,
            'by_employee' => $byEmployee,
        ];
    }

    private function buildCandidates(array $data, Collection $treatments): array
    {
        return collect($data['items'])->map(function (array $item) use ($data, $treatments): array {
            $treatment = $treatments->get((int) $item['treatment_id']);
            $start = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i',
                "{$data['date']} {$item['start_time']}",
                config('app.timezone'),
            );

            $end = $start->addMinutes((int) $treatment->duration_minutes + self::PREPARATION_MINUTES);

            return [
                'input' => $item,
                'treatment' => $treatment,
                'start' => $start,
                // Waktu selesai mencakup 15 menit persiapan/beres-beres.
                'end' => $end,
                // Therapist baru dapat menerima layanan berikutnya setelah istirahat.
                'ready' => $end->addMinutes(self::REST_MINUTES),
            ];
        })->all();
    }

    private function validateStaffAssignments(array $candidates): void
    {
        foreach ($candidates as $itemIndex => $candidate) {
            $staff = collect($candidate['input']['staff']);
            $employeeIds = $staff->pluck('employee_id')->map(fn ($id) => (int) $id);

            if ($employeeIds->unique()->count() !== $employeeIds->count()) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.staff" => ['Pegawai yang sama tidak boleh ditambahkan dua kali pada satu item.'],
                ]);
            }

            if ($staff->where('role', 'primary')->count() !== 1) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.staff" => ['Setiap item harus memiliki tepat satu pegawai primary.'],
                ]);
            }
        }
    }

    private function authorizePriceOverrides(array $candidates, Request $request): void
    {
        foreach ($candidates as $itemIndex => $candidate) {
            $actualPrice = $candidate['input']['actual_price'] ?? null;

            if ($actualPrice === null || (int) $actualPrice === (int) $candidate['treatment']->normal_price) {
                continue;
            }

            if (! $request->user()?->can('reservations.override_price')) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.actual_price" => ['Anda tidak memiliki izin untuk mengubah harga normal treatment.'],
                ]);
            }
        }
    }

    private function findConflicts(array $candidates, Collection $employees): array
    {
        $conflicts = [];
        $assignments = [];

        foreach ($candidates as $itemIndex => $candidate) {
            foreach ($candidate['input']['staff'] as $staff) {
                $employeeId = (int) $staff['employee_id'];
                $employee = $employees->get($employeeId);

                foreach ($this->existingConflicts($employeeId, $candidate['start'], $candidate['ready'], true) as $existing) {
                    $conflicts[] = [
                        'type' => 'existing_reservation',
                        'item_index' => $itemIndex,
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->name,
                        'requested_start_at' => $candidate['start']->toIso8601String(),
                        'requested_end_at' => $candidate['end']->toIso8601String(),
                        'requested_ready_at' => $candidate['ready']->toIso8601String(),
                        'reservation_id' => (int) $existing->reservation_id,
                        'reservation_item_id' => (int) $existing->reservation_item_id,
                        'booking_code' => $existing->booking_code,
                        'conflicting_start_at' => $existing->scheduled_start_at,
                        'conflicting_end_at' => $existing->scheduled_end_at,
                    ];
                    $assignments[$itemIndex.':'.$employeeId] = true;
                }

                foreach ($candidates as $otherIndex => $other) {
                    if ($otherIndex >= $itemIndex || ! collect($other['input']['staff'])->contains(
                        fn (array $otherStaff): bool => (int) $otherStaff['employee_id'] === $employeeId
                    )) {
                        continue;
                    }

                    if ($other['start']->lt($candidate['ready']) && $other['ready']->gt($candidate['start'])) {
                        $conflicts[] = [
                            'type' => 'request_item',
                            'item_index' => $itemIndex,
                            'conflicting_item_index' => $otherIndex,
                            'employee_id' => $employeeId,
                            'employee_name' => $employee->name,
                            'requested_start_at' => $candidate['start']->toIso8601String(),
                            'requested_end_at' => $candidate['end']->toIso8601String(),
                            'requested_ready_at' => $candidate['ready']->toIso8601String(),
                            'conflicting_start_at' => $other['start']->toIso8601String(),
                            'conflicting_end_at' => $other['end']->toIso8601String(),
                        ];
                        $assignments[$itemIndex.':'.$employeeId] = true;
                        $assignments[$otherIndex.':'.$employeeId] = true;
                    }
                }
            }
        }

        return [$conflicts, $assignments];
    }

    private function existingConflicts(
        int $employeeId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $lockForUpdate = false,
    ): Collection {
        $query = DB::table('reservation_item_staff as staff')
            ->join('reservation_items as item', 'item.id', '=', 'staff.reservation_item_id')
            ->join('reservations as reservation', 'reservation.id', '=', 'item.reservation_id')
            ->where('staff.employee_id', $employeeId)
            ->whereNotIn('reservation.status', ['cancelled', 'completed'])
            ->whereNotIn('item.work_status', ['cancelled', 'finished'])
            ->where('item.scheduled_start_at', '<', $end)
            // scheduled_ready_at includes the rest period. It is intentionally
            // used for capacity even though the treatment card ends earlier.
            ->where('item.scheduled_ready_at', '>', $start)
            ->orderBy('item.scheduled_start_at');

        if ($lockForUpdate) {
            // A locking read is a current read in InnoDB. This is required even
            // after the employee lock because an earlier consistent read may have
            // established a REPEATABLE READ snapshot before another writer committed.
            $query->lockForUpdate();
        }

        return $query->get([
            'reservation.id as reservation_id',
            'reservation.booking_code',
            'item.id as reservation_item_id',
            'item.scheduled_start_at',
            'item.scheduled_end_at',
            'item.scheduled_ready_at',
        ]);
    }

    private function resolveCustomer(array $data): object
    {
        if (($data['customer_type'] ?? 'guest') === 'member') {
            $member = DB::table('customers')
                ->where('id', (int) $data['member_id'])
                ->where('is_member', true)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $member) {
                throw ValidationException::withMessages([
                    'member_id' => ['Member tidak ditemukan atau sudah tidak aktif.'],
                ]);
            }

            return $member;
        }

        $id = $this->upsertCustomer((string) $data['name'], (string) $data['phone']);

        return DB::table('customers')->where('id', $id)->firstOrFail();
    }

    private function upsertCustomer(string $name, string $phone): int
    {
        $now = now();
        DB::table('customers')->upsert([[
            'code' => 'CUS-'.Str::upper((string) Str::ulid()),
            'name' => $name,
            'phone' => $phone,
            'is_member' => false,
            'visit_count' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['phone'], ['name', 'is_active', 'updated_at']);

        return (int) DB::table('customers')->where('phone', $phone)->value('id');
    }

    private function ensureNotPaid(int $reservationId): void
    {
        abort_if(
            DB::table('transactions')->where('reservation_id', $reservationId)->where('status', 'paid')->exists(),
            422,
            'Reservasi yang sudah dibayar tidak dapat diubah.',
        );
    }

    private function syncHeaderStatus(int $reservationId, ?int $userId, ?string $reason): string
    {
        $statuses = DB::table('reservation_items')->where('reservation_id', $reservationId)->pluck('work_status');
        $reservation = DB::table('reservations')->where('id', $reservationId)->first();
        $now = now();

        if ($statuses->every(fn (string $status): bool => $status === 'cancelled')) {
            DB::table('reservations')->where('id', $reservationId)->update([
                'status' => 'cancelled',
                'updated_by' => $userId,
                'cancelled_by' => $userId,
                'cancelled_at' => $now,
                'cancellation_reason' => $reason ?: 'Semua item reservasi dibatalkan',
                'updated_at' => $now,
            ]);

            return 'cancelled';
        }

        $active = $statuses->reject(fn (string $status): bool => $status === 'cancelled');
        $status = $active->every(fn (string $itemStatus): bool => $itemStatus === 'finished')
            ? 'completed'
            : ($active->contains(fn (string $itemStatus): bool => $itemStatus !== 'waiting')
                ? 'in_service'
                : ($reservation->status === 'arrived' ? 'arrived' : 'scheduled'));

        DB::table('reservations')->where('id', $reservationId)->update([
            'status' => $status,
            'updated_by' => $userId,
            'updated_at' => $now,
        ]);

        if (
            $status === 'completed'
            && $reservation->status !== 'completed'
            && DB::table('transactions')->where('reservation_id', $reservationId)->where('status', 'paid')->exists()
        ) {
            DB::table('customers')->where('id', $reservation->customer_id)->increment('visit_count');
        }

        return $status;
    }
}
