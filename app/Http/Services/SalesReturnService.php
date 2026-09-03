<?php

namespace App\Http\Services;

use App\Http\Support\FixedPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function create(int $transactionId, array $data, Request $request): array
    {
        return DB::transaction(function () use ($transactionId, $data, $request): array {
            $transaction = DB::table('transactions')->where('id', $transactionId)->lockForUpdate()->first();
            abort_unless($transaction, 404, 'Transaksi tidak ditemukan.');
            abort_unless($transaction->status === 'paid', 422, 'Hanya transaksi lunas yang dapat diretur.');

            $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''))
                ?: 'return:'.$transactionId.':'.Str::uuid();
            $existing = DB::table('sales_returns')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->transaction_id === $transactionId, 409, 'Kunci permintaan sudah digunakan.');

                return $this->result($existing, true);
            }

            $paymentMethod = DB::table('payment_methods')
                ->where('id', $data['payment_method_id'])
                ->lockForUpdate()
                ->first();
            abort_unless($paymentMethod && $paymentMethod->is_active, 422, 'Metode pengembalian dana tidak tersedia.');
            if ($paymentMethod->requires_reference && blank($data['reference_number'] ?? null)) {
                throw ValidationException::withMessages([
                    'reference_number' => ['Nomor referensi wajib diisi untuk metode ini.'],
                ]);
            }

            $inputs = collect($data['items'])->keyBy(fn (array $item): int => (int) $item['transaction_item_id']);
            $items = DB::table('transaction_items')
                ->where('transaction_id', $transactionId)
                ->where('item_type', 'product')
                ->whereIn('id', $inputs->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($items->count() !== $inputs->count()) {
                throw ValidationException::withMessages([
                    'items' => ['Salah satu produk tidak termasuk dalam transaksi ini.'],
                ]);
            }

            $returnedQuantities = DB::table('sales_return_items as item')
                ->join('sales_returns as sales_return', 'sales_return.id', '=', 'item.sales_return_id')
                ->where('sales_return.transaction_id', $transactionId)
                ->where('sales_return.status', 'posted')
                ->whereIn('item.transaction_item_id', $items->pluck('id'))
                ->select('item.transaction_item_id', DB::raw('SUM(item.quantity) as quantity'))
                ->groupBy('item.transaction_item_id')
                ->pluck('quantity', 'transaction_item_id');

            $prepared = $items->map(function (object $item) use ($inputs, $returnedQuantities): array {
                $input = $inputs->get((int) $item->id);
                $sold = FixedPoint::parse((string) $item->quantity, FixedPoint::STOCK_SCALE);
                $returned = FixedPoint::parse((string) ($returnedQuantities->get($item->id) ?? 0), FixedPoint::STOCK_SCALE);
                $quantity = FixedPoint::parse((string) $input['quantity'], FixedPoint::STOCK_SCALE);

                if ($quantity > $sold - $returned) {
                    throw ValidationException::withMessages([
                        'items' => ["Jumlah retur {$item->name} melebihi sisa yang dapat diretur."],
                    ]);
                }

                return [
                    'item' => $item,
                    'quantity' => $quantity,
                    'amount' => FixedPoint::multiply((int) $item->unit_price, $quantity, FixedPoint::STOCK_SCALE),
                    'restock' => (bool) $input['restock'],
                ];
            });
            $total = $prepared->sum('amount');
            abort_if($total <= 0, 422, 'Nominal pengembalian dana harus lebih dari nol.');
            abort_if((int) $transaction->refunded_amount + $total > (int) $transaction->total, 422, 'Total pengembalian dana melebihi nilai transaksi.');

            $now = now();
            $number = $this->nextNumber($now->toDateString(), $now->format('Ymd'));
            $returnId = DB::table('sales_returns')->insertGetId([
                'number' => $number,
                'transaction_id' => $transactionId,
                'refund_payment_method_id' => $paymentMethod->id,
                'total_amount' => $total,
                'reference_number' => filled($data['reference_number'] ?? null) ? trim($data['reference_number']) : null,
                'reason' => trim($data['reason']),
                'status' => 'posted',
                'idempotency_key' => $idempotencyKey,
                'returned_at' => $now,
                'created_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($prepared as $line) {
                $product = DB::table('products')->where('id', $line['item']->item_id)->lockForUpdate()->first();
                abort_unless($product, 422, "Produk {$line['item']->name} tidak ditemukan.");

                DB::table('sales_return_items')->insert([
                    'sales_return_id' => $returnId,
                    'transaction_item_id' => $line['item']->id,
                    'product_id' => $product->id,
                    'unit_id' => $product->usage_unit_id,
                    'product_name' => $line['item']->name,
                    'quantity' => FixedPoint::format($line['quantity'], FixedPoint::STOCK_SCALE),
                    'unit_price' => $line['item']->unit_price,
                    'amount' => $line['amount'],
                    'restocked' => $line['restock'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if (! $line['restock']) {
                    continue;
                }

                $before = FixedPoint::parse((string) $product->current_stock, FixedPoint::STOCK_SCALE);
                if ($line['quantity'] > PHP_INT_MAX - $before) {
                    throw ValidationException::withMessages(['items' => ['Jumlah stok setelah retur terlalu besar.']]);
                }
                $after = $before + $line['quantity'];
                DB::table('products')->where('id', $product->id)->update([
                    'current_stock' => FixedPoint::format($after, FixedPoint::STOCK_SCALE),
                    'updated_at' => $now,
                ]);
                DB::table('stock_movements')->insert([
                    'product_id' => $product->id,
                    'unit_id' => $product->usage_unit_id,
                    'type' => 'in',
                    'quantity' => FixedPoint::format($line['quantity'], FixedPoint::STOCK_SCALE),
                    'stock_before' => FixedPoint::format($before, FixedPoint::STOCK_SCALE),
                    'stock_after' => FixedPoint::format($after, FixedPoint::STOCK_SCALE),
                    'unit_cost' => (int) ($line['item']->unit_cost ?? 0),
                    'source_type' => 'sales_return',
                    'source_id' => $returnId,
                    'reference' => $number,
                    'notes' => 'Produk kembali dari retur penjualan',
                    'occurred_at' => $now,
                    'created_by' => $request->user()?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('transactions')->where('id', $transactionId)->update([
                'refunded_amount' => (int) $transaction->refunded_amount + $total,
                'updated_at' => $now,
            ]);

            $this->logger->log(
                $request,
                'transaction.refunded',
                'sales_return',
                $returnId,
                "Memproses retur {$number} untuk transaksi {$transaction->number}",
                [
                    'transaction_id' => $transactionId,
                    'total_amount' => $total,
                    'payment_method_id' => (int) $paymentMethod->id,
                    'items' => $prepared->map(fn (array $line): array => [
                        'transaction_item_id' => (int) $line['item']->id,
                        'quantity' => FixedPoint::format($line['quantity'], FixedPoint::STOCK_SCALE),
                        'restocked' => $line['restock'],
                    ])->all(),
                ],
            );

            return [
                'id' => $returnId,
                'number' => $number,
                'transaction_id' => $transactionId,
                'total_amount' => $total,
                'status' => 'posted',
                'idempotent_replay' => false,
            ];
        }, 3);
    }

    private function nextNumber(string $date, string $dateCode): string
    {
        DB::table('sales_return_sequences')->insertOrIgnore([
            'sequence_date' => $date,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sequence = DB::table('sales_return_sequences')->where('sequence_date', $date)->lockForUpdate()->firstOrFail();
        $next = (int) $sequence->last_number + 1;
        DB::table('sales_return_sequences')->where('sequence_date', $date)->update([
            'last_number' => $next,
            'updated_at' => now(),
        ]);

        return sprintf('RTN-%s-%03d', $dateCode, $next);
    }

    private function result(object $return, bool $replay): array
    {
        return [
            'id' => (int) $return->id,
            'number' => $return->number,
            'transaction_id' => (int) $return->transaction_id,
            'total_amount' => (int) $return->total_amount,
            'status' => $return->status,
            'idempotent_replay' => $replay,
        ];
    }
}
