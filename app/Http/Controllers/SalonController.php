<?php

namespace App\Http\Controllers;

use App\Http\Exceptions\ReservationConflictException;
use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationItemStatusRequest;
use App\Http\Requests\UpdateReservationStatusRequest;
use App\Http\Services\ActivityLogger;
use App\Http\Services\CheckoutService;
use App\Http\Services\ReservationService;
use App\Http\Services\SalonSnapshotService;
use App\Http\Support\FixedPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalonController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly CheckoutService $checkout,
        private readonly SalonSnapshotService $snapshots,
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

    public function updateRecipe(Request $request, int $id): JsonResponse
    {
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

    public function updatePayroll(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'base_salary' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'bonus' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'overtime' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'commission' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'late_deduction' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'other_deduction' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'late_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        DB::transaction(function () use ($id, $data, $request): void {
            $payroll = DB::table('payrolls')->where('id', $id)->lockForUpdate()->first();
            abort_unless($payroll, 404, 'Data penggajian tidak ditemukan.');
            abort_if($payroll->status !== 'draft', 422, 'Penggajian yang sudah difinalisasi tidak dapat diubah.');

            $overtime = (int) ($data['overtime'] ?? $payroll->overtime);
            $commission = (int) ($data['commission'] ?? $payroll->commission);
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
