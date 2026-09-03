<?php

namespace App\Http\Services;

use App\Http\Support\FixedPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function checkout(array $data, Request $request): array
    {
        return DB::transaction(function () use ($data, $request): array {
            $reservationId = (int) $data['reservation_id'];
            $reservation = DB::table('reservations')->where('id', $reservationId)->lockForUpdate()->first();
            abort_unless($reservation, 404, 'Reservasi tidak ditemukan.');

            $existing = DB::table('transactions')->where('reservation_id', $reservationId)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->status === 'paid') {
                    return $this->transactionResult($existing, true);
                }

                abort(409, 'Reservasi ini sudah memiliki transaksi yang belum dapat diproses ulang.');
            }

            abort_if($reservation->status === 'cancelled', 422, 'Reservasi yang dibatalkan tidak dapat dibayar.');

            $items = DB::table('reservation_items')
                ->where('reservation_id', $reservationId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $billableItems = $items->where('work_status', '!=', 'cancelled')->values();

            abort_if($billableItems->isEmpty(), 422, 'Reservasi tidak memiliki item yang dapat ditagihkan.');
            $allBillableItemsFinished = $billableItems->every(
                fn (object $item): bool => $item->work_status === 'finished',
            );

            $customer = DB::table('customers')->where('id', $reservation->customer_id)->lockForUpdate()->first();
            abort_unless($customer, 422, 'Data pelanggan reservasi tidak ditemukan.');

            $serviceSubtotal = $this->sumMoney($billableItems->map(fn (object $item): int => (int) $item->unit_price));
            $productLines = $this->resolveProductItems($reservationId, $data);
            $treatmentCosts = $this->treatmentCosts($billableItems);
            $productSubtotal = $this->sumMoney($productLines->map(fn (array $line): int => $line['gross_amount']));
            $subtotal = $this->safeAdd($serviceSubtotal, $productSubtotal);
            [$discountPercent, $discountAmount, $promotionId, $discountType] = $this->resolveDiscount($data, $customer, $serviceSubtotal);
            $baseTotal = $subtotal - $discountAmount;

            abort_if($baseTotal <= 0, 422, 'Transaksi tanpa nilai pembayaran belum didukung.');

            [$payments, $paymentChargeAmount] = $this->resolvePayments($data, $baseTotal);
            $total = $this->safeAdd($baseTotal, $paymentChargeAmount);
            $changeAmount = $this->sumMoney(collect($payments)->map(
                fn (array $payment): int => $payment['tendered_amount'],
            )) - $total;
            $idempotencyKey = trim((string) ($data['idempotency_key'] ?? '')) ?: "checkout:reservation:{$reservationId}";
            $reusedKey = DB::table('transactions')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($reusedKey) {
                abort(409, 'Idempotency key sudah digunakan oleh transaksi lain.');
            }

            $now = now();
            $number = $this->nextInvoiceNumber($now->toDateString(), $now->format('Ymd'));

            $transactionId = DB::table('transactions')->insertGetId([
                'number' => $number,
                'reservation_id' => $reservationId,
                'customer_id' => $reservation->customer_id,
                'status' => 'paid',
                'transacted_at' => $now,
                'subtotal' => $subtotal,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
                'payment_charge_amount' => $paymentChargeAmount,
                'total' => $total,
                'paid_amount' => $total,
                'change_amount' => $changeAmount,
                'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
                'finalized_by' => $request->user()?->id,
                'finalized_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $lineDiscounts = $this->allocateDiscounts($billableItems, $discountPercent, $discountAmount, $discountType);

            foreach ($billableItems as $index => $item) {
                $gross = (int) $item->unit_price;
                $lineDiscount = $lineDiscounts[$index];
                $lineTotal = $gross - $lineDiscount;
                $costAmount = (int) $treatmentCosts->get((int) $item->id, 0);

                DB::table('transaction_items')->insert([
                    'transaction_id' => $transactionId,
                    'reservation_item_id' => $item->id,
                    'item_type' => 'treatment',
                    'item_id' => $item->treatment_id,
                    'name' => $item->treatment_name,
                    'quantity' => FixedPoint::format(10 ** FixedPoint::STOCK_SCALE, FixedPoint::STOCK_SCALE),
                    'unit_price' => $gross,
                    'unit_cost' => $costAmount,
                    'gross_amount' => $gross,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $lineDiscount,
                    'total_amount' => $lineTotal,
                    'cost_amount' => $costAmount,
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('reservation_items')->where('id', $item->id)->update([
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $lineDiscount,
                    'net_price' => $lineTotal,
                    'updated_at' => $now,
                ]);
            }

            foreach ($productLines as $index => $line) {
                DB::table('transaction_items')->insert([
                    'transaction_id' => $transactionId,
                    'reservation_item_id' => null,
                    'item_type' => 'product',
                    'item_id' => $line['product']->id,
                    'name' => $line['product']->name,
                    'quantity' => FixedPoint::format($line['quantity'], FixedPoint::STOCK_SCALE),
                    'unit_price' => $line['unit_price'],
                    'unit_cost' => $line['unit_cost'],
                    'gross_amount' => $line['gross_amount'],
                    'discount_percent' => FixedPoint::normalizePercent(0),
                    'discount_amount' => 0,
                    'total_amount' => $line['gross_amount'],
                    'cost_amount' => $line['cost_amount'],
                    'sort_order' => $billableItems->count() + $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->deductRecipeStock($billableItems, $transactionId, $number, $request, $now);
            $this->deductSoldProductStock($productLines, $transactionId, $number, $request, $now);
            DB::table('reservation_product_items')->where('reservation_id', $reservationId)->delete();

            foreach ($payments as $payment) {
                $paymentId = DB::table('transaction_payments')->insertGetId([
                    'transaction_id' => $transactionId,
                    'payment_method_id' => $payment['method']->id,
                    'amount' => $payment['amount'],
                    'base_amount' => $payment['base_amount'],
                    'charge_percent' => $payment['charge_percent'],
                    'charge_amount' => $payment['charge_amount'],
                    'charge_enabled' => $payment['charge_enabled'],
                    'tendered_amount' => $payment['tendered_amount'],
                    'reference_number' => $payment['reference_number'],
                    'paid_at' => $now,
                    'status' => 'confirmed',
                    'notes' => $payment['notes'],
                    'received_by' => $request->user()?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Penjualan sudah tersimpan lengkap di transactions dan transaction_payments.
                // Jangan menduplikasi baris ke buku kas manual; data kas dipakai untuk
                // modal serta pengeluaran/pemasukan non-penjualan.
            }

            // Komisi tersimpan pada assignment saat reservasi dibuat. Setiap
            // transaksi berhasil harus menyegarkan payroll periode berjalan
            // yang masih draft, agar nominal di penggajian tidak tertinggal.
            $this->syncDraftPayrollCommissions($reservationId, $now);

            // Pembayaran dapat dilakukan saat pelanggan datang, sebelum treatment
            // dikerjakan. Status kunjungan hanya ditutup ketika seluruh item selesai.
            if ($allBillableItemsFinished) {
                DB::table('reservations')->where('id', $reservationId)->update([
                    'status' => 'completed',
                    'updated_by' => $request->user()?->id,
                    'updated_at' => $now,
                ]);
                DB::table('customers')->where('id', $customer->id)->increment('visit_count');
            }

            $this->logger->log(
                $request,
                'transaction.completed',
                'transaction',
                $transactionId,
                "Menyelesaikan transaksi {$number}",
                [
                    'reservation_id' => $reservationId,
                    'promotion_id' => $promotionId,
                    'manual_discount_percent' => $data['manual_discount_percent'] ?? null,
                    'subtotal' => $subtotal,
                    'product_item_ids' => $productLines->map(fn (array $line): int => (int) $line['product']->id)->all(),
                    'discount_amount' => $discountAmount,
                    'payment_charge_amount' => $paymentChargeAmount,
                    'total' => $total,
                    'payment_method_ids' => collect($payments)->pluck('method.id')->all(),
                ],
            );

            return [
                'id' => $transactionId,
                'number' => $number,
                'total' => $total,
                'base_total' => $baseTotal,
                'payment_charge_amount' => $paymentChargeAmount,
                'paid_amount' => $total,
                'change_amount' => $changeAmount,
                'cashier_name' => $request->user()?->name,
                'therapists' => $this->transactionTherapists($transactionId),
                'status' => 'paid',
                'idempotent_replay' => false,
            ];
        }, 3);
    }

    private function resolveProductItems(int $reservationId, array $data): Collection
    {
        // Produk dari kasir disimpan terlebih dahulu pada pesanan reservasi.
        // Payload lama tetap didukung untuk integrasi/API yang sudah ada, tetapi
        // tidak dapat menimpa jumlah pesanan yang sudah tersimpan.
        $saved = DB::table('reservation_product_items')
            ->where('reservation_id', $reservationId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['product_id', 'quantity'])
            ->map(fn (object $item): array => [
                'product_id' => (int) $item->product_id,
                'quantity' => (string) $item->quantity,
            ]);
        $savedProductIds = $saved->pluck('product_id')->map(fn (int $id): int => $id)->all();
        $legacy = collect($data['product_items'] ?? [])
            ->reject(fn (array $item): bool => in_array((int) $item['product_id'], $savedProductIds, true));
        $inputs = $saved->concat($legacy)->values();

        if ($inputs->isEmpty()) {
            return collect();
        }

        $productIds = $inputs->pluck('product_id')->map(fn ($id): int => (int) $id)->sort()->values();
        $products = DB::table('products')
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return $inputs->map(function (array $input, int $index) use ($products): array {
            $product = $products->get((int) $input['product_id']);

            if (! $product || ! $product->is_active) {
                throw ValidationException::withMessages([
                    "product_items.{$index}.product_id" => ['Produk tidak tersedia.'],
                ]);
            }

            $quantity = FixedPoint::parse((string) $input['quantity'], FixedPoint::STOCK_SCALE);
            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    "product_items.{$index}.quantity" => ['Jumlah produk harus lebih dari nol.'],
                ]);
            }

            $unitPrice = (int) $product->selling_price;
            if ($unitPrice <= 0) {
                throw ValidationException::withMessages([
                    "product_items.{$index}.product_id" => ["Harga jual {$product->name} belum diatur."],
                ]);
            }

            return [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => (int) ($product->cost_price ?? 0),
                'gross_amount' => FixedPoint::multiply($unitPrice, $quantity, FixedPoint::STOCK_SCALE),
                'cost_amount' => FixedPoint::multiply((int) ($product->cost_price ?? 0), $quantity, FixedPoint::STOCK_SCALE),
            ];
        });
    }

    /**
     * HPP treatment adalah total bahan resep untuk satu kali treatment.
     * Nilai akhirnya disalin ke item transaksi pada saat pembayaran.
     *
     * @return Collection<int, int>
     */
    private function treatmentCosts(Collection $billableItems): Collection
    {
        $treatmentIds = $billableItems->pluck('treatment_id')->map(fn ($id): int => (int) $id)->unique()->values();
        if ($treatmentIds->isEmpty()) {
            return collect();
        }

        $recipes = DB::table('treatment_product_recipes as recipe')
            ->join('products as product', 'product.id', '=', 'recipe.product_id')
            ->whereIn('recipe.treatment_id', $treatmentIds)
            ->get([
                'recipe.treatment_id',
                'recipe.unit_id',
                'recipe.quantity',
                'product.purchase_unit_id',
                'product.usage_unit_id',
                'product.purchase_to_usage_factor',
                'product.cost_price',
            ]);
        $costsByTreatment = [];

        foreach ($recipes as $recipe) {
            $quantity = FixedPoint::parse((string) $recipe->quantity, FixedPoint::STOCK_SCALE);
            if ((int) $recipe->unit_id === (int) $recipe->usage_unit_id) {
                $usageQuantity = $quantity;
            } elseif ((int) $recipe->unit_id === (int) $recipe->purchase_unit_id) {
                $factor = FixedPoint::parse((string) $recipe->purchase_to_usage_factor, FixedPoint::STOCK_SCALE);
                $usageQuantity = FixedPoint::multiply($quantity, $factor, FixedPoint::STOCK_SCALE);
            } else {
                throw ValidationException::withMessages([
                    'reservation_id' => ['Satuan resep tidak kompatibel dengan produk.'],
                ]);
            }

            $treatmentId = (int) $recipe->treatment_id;
            $costsByTreatment[$treatmentId] = $this->safeAdd(
                $costsByTreatment[$treatmentId] ?? 0,
                FixedPoint::multiply((int) ($recipe->cost_price ?? 0), $usageQuantity, FixedPoint::STOCK_SCALE),
            );
        }

        return $billableItems->mapWithKeys(fn (object $item): array => [
            (int) $item->id => (int) ($costsByTreatment[(int) $item->treatment_id] ?? 0),
        ]);
    }

    private function resolveDiscount(array $data, object $customer, int $subtotal): array
    {
        $promotion = null;

        if (isset($data['manual_discount_percent']) && FixedPoint::parse((string) $data['manual_discount_percent'], FixedPoint::PERCENT_SCALE) > 0) {
            $percent = FixedPoint::normalizePercent((string) $data['manual_discount_percent']);

            return [$percent, FixedPoint::percentOf($subtotal, $percent), null, 'percent'];
        }

        if (! empty($data['promotion_id'])) {
            $promotion = DB::table('promotions')
                ->where('id', $data['promotion_id'])
                ->where('is_active', true)
                ->whereDate('starts_at', '<=', today())
                ->whereDate('ends_at', '>=', today())
                ->first();

            if (! $promotion) {
                throw ValidationException::withMessages(['promotion_id' => ['Promosi tidak aktif atau tidak ditemukan.']]);
            }
        } elseif (isset($data['discount_percent']) && FixedPoint::parse((string) $data['discount_percent'], FixedPoint::PERCENT_SCALE) > 0) {
            $requested = FixedPoint::parse((string) $data['discount_percent'], FixedPoint::PERCENT_SCALE);
            $promotion = DB::table('promotions')
                ->where('is_active', true)
                ->where('discount_type', 'percent')
                ->whereDate('starts_at', '<=', today())
                ->whereDate('ends_at', '>=', today())
                ->get()
                ->first(fn (object $candidate): bool => FixedPoint::parse(
                    (string) $candidate->discount_percent,
                    FixedPoint::PERCENT_SCALE,
                ) === $requested);

            if (! $promotion) {
                throw ValidationException::withMessages([
                    'discount_percent' => ['Diskon harus berasal dari promosi yang aktif.'],
                ]);
            }
        }

        if (! $promotion) {
            return [FixedPoint::normalizePercent(0), 0, null, 'none'];
        }

        if ($promotion->members_only && ! $customer->is_member) {
            throw ValidationException::withMessages(['promotion_id' => ['Promosi ini hanya berlaku untuk member.']]);
        }

        if ($promotion->discount_type === 'percent') {
            $percent = FixedPoint::normalizePercent((string) $promotion->discount_percent);

            return [$percent, FixedPoint::percentOf($subtotal, $percent), (int) $promotion->id, 'percent'];
        }

        if ($promotion->discount_type === 'fixed') {
            return [FixedPoint::normalizePercent(0), min($subtotal, (int) $promotion->discount_amount), (int) $promotion->id, 'fixed'];
        }

        throw ValidationException::withMessages(['promotion_id' => ['Jenis promosi belum didukung.']]);
    }

    private function resolvePayments(array $data, int $total): array
    {
        $paymentInputs = $data['payments'] ?? null;

        if (! is_array($paymentInputs)) {
            $needle = mb_strtolower(trim((string) $data['payment_method']));
            $method = DB::table('payment_methods')->where('is_active', true)->get()->first(
                fn (object $candidate): bool => in_array($needle, [
                    mb_strtolower($candidate->name),
                    mb_strtolower($candidate->code),
                    mb_strtolower($candidate->type),
                    match ($candidate->type) {
                        'card' => 'kartu',
                        'bank_transfer' => 'transfer',
                        default => '',
                    },
                ], true),
            );

            if (! $method) {
                throw ValidationException::withMessages(['payment_method' => ['Metode pembayaran tidak tersedia.']]);
            }

            $paymentInputs = [['payment_method_id' => $method->id, 'amount' => $total]];
        }

        $methodIds = collect($paymentInputs)->pluck('payment_method_id')->map(fn ($id) => (int) $id)->unique()->values();
        $methods = DB::table('payment_methods')
            ->whereIn('id', $methodIds)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($methods->count() !== $methodIds->count()) {
            throw ValidationException::withMessages(['payments' => ['Semua metode pembayaran harus aktif.']]);
        }

        $resolved = [];
        $basePaid = 0;
        $chargeTotal = 0;

        foreach ($paymentInputs as $index => $input) {
            $method = $methods->get((int) $input['payment_method_id']);
            $reference = isset($input['reference_number']) ? trim((string) $input['reference_number']) : null;

            $baseAmount = (int) $input['amount'];
            $chargeRequested = array_key_exists('charge_enabled', $input)
                ? filter_var($input['charge_enabled'], FILTER_VALIDATE_BOOLEAN)
                : (bool) ($method->charge_default_enabled ?? true);
            $chargePercent = FixedPoint::normalizePercent((string) ($method->charge_percent ?? 0));
            $chargeEnabled = ! (bool) $method->is_cash
                && $chargeRequested
                && FixedPoint::parse($chargePercent, FixedPoint::PERCENT_SCALE) > 0;
            $chargeAmount = $chargeEnabled ? FixedPoint::percentOf($baseAmount, $chargePercent) : 0;
            $amount = $this->safeAdd($baseAmount, $chargeAmount);
            $tenderedAmount = isset($input['tendered_amount'])
                ? (int) $input['tendered_amount']
                : $amount;

            if ($method->is_cash && $tenderedAmount < $amount) {
                throw ValidationException::withMessages([
                    "payments.{$index}.tendered_amount" => ['Uang tunai yang diterima tidak boleh kurang dari nominal pembayaran.'],
                ]);
            }

            if (! $method->is_cash && $tenderedAmount !== $amount) {
                throw ValidationException::withMessages([
                    "payments.{$index}.tendered_amount" => ['Nominal diterima untuk pembayaran non-tunai harus sama dengan nominal pembayaran.'],
                ]);
            }

            $basePaid = $this->safeAdd($basePaid, $baseAmount);
            $chargeTotal = $this->safeAdd($chargeTotal, $chargeAmount);
            $resolved[] = [
                'method' => $method,
                'amount' => $amount,
                'base_amount' => $baseAmount,
                'charge_percent' => $chargePercent,
                'charge_amount' => $chargeAmount,
                'charge_enabled' => $chargeEnabled,
                'tendered_amount' => $tenderedAmount,
                'reference_number' => $reference ?: null,
                'notes' => $input['notes'] ?? null,
            ];
        }

        if ($basePaid !== $total) {
            throw ValidationException::withMessages([
                'payments' => ["Jumlah pembayaran sebelum charge harus tepat Rp{$total}; diterima Rp{$basePaid}."],
            ]);
        }

        return [$resolved, $chargeTotal];
    }

    private function allocateDiscounts(Collection $items, string $percent, int $discountAmount, string $discountType): array
    {
        if ($discountAmount === 0) {
            return array_fill(0, $items->count(), 0);
        }

        if ($discountType === 'fixed') {
            $remaining = $discountAmount;

            return $items->map(function (object $item) use (&$remaining): int {
                $allocated = min((int) $item->unit_price, $remaining);
                $remaining -= $allocated;

                return $allocated;
            })->all();
        }

        $discounts = $items->map(
            fn (object $item): int => FixedPoint::percentOf((int) $item->unit_price, $percent)
        )->all();
        $difference = $discountAmount - array_sum($discounts);

        if ($difference !== 0) {
            $largestIndex = 0;
            foreach ($items as $index => $item) {
                if ((int) $item->unit_price > (int) $items[$largestIndex]->unit_price) {
                    $largestIndex = $index;
                }
            }
            $discounts[$largestIndex] += $difference;
        }

        return $discounts;
    }

    private function deductRecipeStock(
        Collection $billableItems,
        int $transactionId,
        string $number,
        Request $request,
        mixed $now,
    ): void {
        $treatmentCounts = $billableItems->countBy(fn (object $item): int => (int) $item->treatment_id);
        $recipes = DB::table('treatment_product_recipes')
            ->whereIn('treatment_id', $treatmentCounts->keys())
            ->orderBy('product_id')
            ->get();

        if ($recipes->isEmpty()) {
            return;
        }

        $productIds = $recipes->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $products = DB::table('products')->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $usageByProduct = [];

        foreach ($recipes as $recipe) {
            $product = $products->get((int) $recipe->product_id);
            abort_unless($product && $product->is_active, 422, 'Produk pada resep tidak ditemukan atau tidak aktif.');
            $quantity = FixedPoint::parse((string) $recipe->quantity, FixedPoint::STOCK_SCALE);

            if ((int) $recipe->unit_id === (int) $product->usage_unit_id) {
                $usageQuantity = $quantity;
            } elseif ((int) $recipe->unit_id === (int) $product->purchase_unit_id) {
                $factor = FixedPoint::parse((string) $product->purchase_to_usage_factor, FixedPoint::STOCK_SCALE);
                $usageQuantity = FixedPoint::multiply($quantity, $factor, FixedPoint::STOCK_SCALE);
            } else {
                throw ValidationException::withMessages([
                    'reservation_id' => ["Satuan resep untuk produk {$product->name} tidak kompatibel."],
                ]);
            }

            $count = (int) $treatmentCounts->get((int) $recipe->treatment_id);
            if ($count > 0 && $usageQuantity > intdiv(PHP_INT_MAX, $count)) {
                throw ValidationException::withMessages(['reservation_id' => ['Jumlah penggunaan stok terlalu besar.']]);
            }
            $usageByProduct[$product->id] = $this->safeAdd(
                $usageByProduct[$product->id] ?? 0,
                $usageQuantity * $count,
            );
        }

        foreach ($usageByProduct as $productId => $usage) {
            $product = $products->get((int) $productId);
            $before = FixedPoint::parse((string) $product->current_stock, FixedPoint::STOCK_SCALE);

            if ($before < $usage) {
                throw ValidationException::withMessages([
                    'reservation_id' => ["Stok {$product->name} tidak mencukupi."],
                ]);
            }

            $after = $before - $usage;
            DB::table('products')->where('id', $productId)->update([
                'current_stock' => FixedPoint::format($after, FixedPoint::STOCK_SCALE),
                'updated_at' => $now,
            ]);
            DB::table('stock_movements')->insert([
                'product_id' => $productId,
                'unit_id' => $product->usage_unit_id,
                'type' => 'out',
                'quantity' => FixedPoint::format($usage, FixedPoint::STOCK_SCALE),
                'stock_before' => FixedPoint::format($before, FixedPoint::STOCK_SCALE),
                'stock_after' => FixedPoint::format($after, FixedPoint::STOCK_SCALE),
                'unit_cost' => (int) ($product->cost_price ?? 0),
                'source_type' => 'transaction',
                'source_id' => $transactionId,
                'reference' => $number,
                'notes' => 'Pemakaian resep treatment',
                'occurred_at' => $now,
                'created_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function deductSoldProductStock(
        Collection $productLines,
        int $transactionId,
        string $number,
        Request $request,
        mixed $now,
    ): void {
        foreach ($productLines as $line) {
            $product = DB::table('products')
                ->where('id', $line['product']->id)
                ->lockForUpdate()
                ->first();
            abort_unless($product && $product->is_active, 422, 'Produk tidak tersedia.');
            $quantity = $line['quantity'];
            $before = FixedPoint::parse((string) $product->current_stock, FixedPoint::STOCK_SCALE);

            if ($before < $quantity) {
                throw ValidationException::withMessages([
                    'product_items' => ["Stok {$product->name} tidak mencukupi."],
                ]);
            }

            $after = $before - $quantity;
            DB::table('products')->where('id', $product->id)->update([
                'current_stock' => FixedPoint::format($after, FixedPoint::STOCK_SCALE),
                'updated_at' => $now,
            ]);
            DB::table('stock_movements')->insert([
                'product_id' => $product->id,
                'unit_id' => $product->usage_unit_id,
                'type' => 'out',
                'quantity' => FixedPoint::format($quantity, FixedPoint::STOCK_SCALE),
                'stock_before' => FixedPoint::format($before, FixedPoint::STOCK_SCALE),
                'stock_after' => FixedPoint::format($after, FixedPoint::STOCK_SCALE),
                'unit_cost' => (int) ($product->cost_price ?? 0),
                'source_type' => 'transaction_sale',
                'source_id' => $transactionId,
                'reference' => $number,
                'notes' => 'Penjualan produk melalui kasir',
                'occurred_at' => $now,
                'created_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array<int, array{id: int, name: string, position: string|null, stars: int|null}> */
    private function transactionTherapists(int $transactionId): array
    {
        return DB::table('transaction_items as item')
            ->join('reservation_item_staff as assignment', 'assignment.reservation_item_id', '=', 'item.reservation_item_id')
            ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
            ->leftJoin('therapist_ratings as rating', function ($join) use ($transactionId): void {
                $join->on('rating.employee_id', '=', 'employee.id')
                    ->where('rating.transaction_id', '=', $transactionId);
            })
            ->where('item.transaction_id', $transactionId)
            ->distinct()
            ->orderBy('employee.name')
            ->get([
                'employee.id',
                'employee.name',
                'employee.position',
                'rating.stars',
                'rating.review',
            ])
            ->map(fn (object $therapist): array => [
                'id' => (int) $therapist->id,
                'name' => $therapist->name,
                'position' => $therapist->position,
                'stars' => $therapist->stars === null ? null : (int) $therapist->stars,
                'review' => $therapist->review,
            ])
            ->all();
    }

    private function transactionResult(object $transaction, bool $replay): array
    {
        return [
            'id' => (int) $transaction->id,
            'number' => $transaction->number,
            'total' => (int) $transaction->total,
            'base_total' => (int) $transaction->total - (int) ($transaction->payment_charge_amount ?? 0),
            'payment_charge_amount' => (int) ($transaction->payment_charge_amount ?? 0),
            'paid_amount' => (int) $transaction->paid_amount,
            'change_amount' => (int) $transaction->change_amount,
            'therapists' => $this->transactionTherapists((int) $transaction->id),
            'status' => $transaction->status,
            'idempotent_replay' => $replay,
        ];
    }

    private function syncDraftPayrollCommissions(int $reservationId, mixed $paidAt): void
    {
        $period = CarbonImmutable::instance($paidAt)->format('Y-m');
        $employeeIds = DB::table('reservation_item_staff as assignment')
            ->join('reservation_items as item', 'item.id', '=', 'assignment.reservation_item_id')
            ->where('item.reservation_id', $reservationId)
            ->pluck('assignment.employee_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($employeeIds->isEmpty()) {
            return;
        }

        $draftPayrolls = DB::table('payrolls')
            ->whereIn('employee_id', $employeeIds)
            ->where('period', $period)
            ->where('status', 'draft')
            ->lockForUpdate()
            ->get();

        foreach ($draftPayrolls as $payroll) {
            $commission = $this->payrollCommission((int) $payroll->employee_id, $period);
            $netSalary = (int) $payroll->base_salary
                + (int) $payroll->bonus
                + (int) $payroll->overtime
                + $commission
                - (int) $payroll->late_deduction
                - (int) $payroll->other_deduction;

            DB::table('payrolls')->where('id', $payroll->id)->update([
                'commission' => $commission,
                'net_salary' => $netSalary,
                'updated_at' => $paidAt,
            ]);
        }
    }

    private function payrollCommission(int $employeeId, string $period): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period)->startOfMonth();
        $end = $start->addMonth();

        return (int) DB::table('reservation_item_staff as assignment')
            ->join('reservation_items as item', 'item.id', '=', 'assignment.reservation_item_id')
            ->join('transactions as transaction', 'transaction.reservation_id', '=', 'item.reservation_id')
            ->join('transaction_items as transactionItem', function ($join): void {
                $join->on('transactionItem.transaction_id', '=', 'transaction.id')
                    ->on('transactionItem.reservation_item_id', '=', 'item.id');
            })
            ->where('assignment.employee_id', $employeeId)
            ->where('transaction.status', 'paid')
            ->where('transaction.transacted_at', '>=', $start)
            ->where('transaction.transacted_at', '<', $end)
            ->sum('assignment.commission_amount');
    }

    private function nextInvoiceNumber(string $date, string $dateCode): string
    {
        DB::table('invoice_sequences')->insertOrIgnore([
            'invoice_date' => $date,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('invoice_sequences')
            ->where('invoice_date', $date)
            ->lockForUpdate()
            ->firstOrFail();

        $next = (int) $sequence->last_number + 1;
        DB::table('invoice_sequences')
            ->where('invoice_date', $date)
            ->update(['last_number' => $next, 'updated_at' => now()]);

        $prefix = DB::table('sale_settings')->where('key', 'invoice_prefix')->value('value') ?: 'INV';
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix) ?: 'INV';

        return sprintf('%s%s%03d', $prefix, $dateCode, $next);
    }

    private function sumMoney(Collection $amounts): int
    {
        return $amounts->reduce(fn (int $sum, int $amount): int => $this->safeAdd($sum, $amount), 0);
    }

    private function safeAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw ValidationException::withMessages(['payments' => ['Jumlah nominal terlalu besar.']]);
        }

        return $left + $right;
    }
}
