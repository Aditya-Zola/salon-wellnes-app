<?php

namespace App\Http\Services;

use App\Http\Support\FixedPoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DepositReceiptService
{
    public static function number(int $depositId, string $paidAt): string
    {
        return sprintf('DP%s%03d', CarbonImmutable::parse($paidAt)->format('Ymd'), $depositId);
    }

    public function capture(int $reservationId): array
    {
        $items = DB::table('reservation_items')->where('reservation_id', $reservationId)
            ->where('work_status', '!=', 'cancelled')->orderBy('sort_order')->orderBy('id')->get()
            ->groupBy(fn (object $item): string => $item->treatment_bundle_instance ?: 'item-'.$item->id)
            ->map(fn ($group): array => [
                'name' => $group->first()->treatment_bundle_name ?: $group->first()->treatment_name,
                'item_type' => 'treatment', 'quantity' => 1,
                'unit_price' => (int) $group->sum('unit_price'), 'total_amount' => (int) $group->sum('unit_price'),
                'returned_quantity' => 0, 'returned_amount' => 0,
            ])->values();
        $products = DB::table('reservation_product_items')->where('reservation_id', $reservationId)->orderBy('id')->get()
            ->map(fn (object $item): array => [
                'name' => $item->product_name, 'item_type' => 'product', 'quantity' => $item->quantity,
                'unit_price' => (int) $item->unit_price, 'total_amount' => FixedPoint::multiply((int) $item->unit_price, FixedPoint::parse((string) $item->quantity, FixedPoint::STOCK_SCALE), FixedPoint::STOCK_SCALE),
                'returned_quantity' => 0, 'returned_amount' => 0,
            ]);
        $customerName = DB::table('reservations as reservation')->join('customers as customer', 'customer.id', '=', 'reservation.customer_id')
            ->where('reservation.id', $reservationId)->value('customer.name');

        return ['customer_name' => $customerName, 'total' => (int) $items->sum('total_amount') + (int) $products->sum('total_amount'),
            'items' => $items->concat($products)->all(), 'therapists' => $this->therapists($reservationId)];
    }

    private function therapists(int $reservationId): array
    {
        return DB::table('reservation_item_staff as assignment')
            ->join('reservation_items as item', 'item.id', '=', 'assignment.reservation_item_id')
            ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
            ->where('item.reservation_id', $reservationId)->where('item.work_status', '!=', 'cancelled')
            ->orderBy('employee.name')->pluck('employee.name')->unique()->values()->all();
    }

    public function viewData(int $depositId): array
    {
        $deposit = DB::table('reservation_deposits as deposit')
            ->join('payment_methods as method', 'method.id', '=', 'deposit.payment_method_id')
            ->leftJoin('users as cashier', 'cashier.id', '=', 'deposit.received_by')
            ->where('deposit.id', $depositId)->where('deposit.status', 'confirmed')
            ->first(['deposit.*', 'method.name as method_name', 'method.is_cash', 'cashier.name as cashier_name']);
        abort_unless($deposit, 404, 'Nota DP tidak ditemukan.');
        $snapshot = $deposit->receipt_snapshot ? json_decode($deposit->receipt_snapshot, true, 512, JSON_THROW_ON_ERROR) : $this->capture((int) $deposit->reservation_id);
        $invoice = (object) [
            'number' => $deposit->number ?: self::number((int) $deposit->id, $deposit->paid_at), 'customer_name' => $snapshot['customer_name'],
            'cashier_name' => $snapshot['cashier_name'] ?? $deposit->cashier_name, 'transacted_at' => $deposit->paid_at,
            'subtotal' => $snapshot['total'], 'discount_amount' => 0, 'payment_charge_amount' => 0,
            'total' => $snapshot['total'], 'refunded_amount' => 0, 'change_amount' => 0,
        ];

        return [
            'invoice' => $invoice, 'displayItems' => collect($snapshot['items'])->map(fn (array $item): object => (object) $item),
            'payments' => collect([(object) ['method_name' => $snapshot['method_name'] ?? $deposit->method_name,
                'reference_number' => $deposit->reference_number, 'amount' => (int) $deposit->amount, 'paid_at' => $deposit->paid_at,
                'is_cash' => (bool) $deposit->is_cash]]),
            'therapists' => collect($snapshot['therapists'] ?? $this->therapists((int) $deposit->reservation_id)),
            'depositReceipt' => true, 'depositAmount' => (int) $deposit->amount,
            'settlementAmount' => 0, 'remainingAmount' => max(0, $snapshot['total'] - (int) $deposit->amount),
            'legacyDeposit' => ! $deposit->receipt_snapshot,
        ];
    }

    public function receipt(int $depositId): array
    {
        $data = $this->viewData($depositId);
        $invoice = $data['invoice'];

        return [
            'depositReceiptId' => $depositId, 'isDeposit' => true, 'number' => $invoice->number,
            'customer' => $invoice->customer_name, 'cashier' => $invoice->cashier_name ?: 'Kasir Selesa',
            'date' => $invoice->transacted_at,
            'therapists' => $data['therapists']->all(),
            'items' => $data['displayItems']->map(fn (object $item): array => [
                'name' => $item->name, 'quantity' => (float) $item->quantity,
                'unitPrice' => (int) $item->unit_price, 'total' => (int) $item->total_amount,
            ])->all(),
            'payments' => $data['payments']->map(fn (object $payment): array => [
                'name' => $payment->method_name, 'amount' => $payment->amount, 'isCash' => $payment->is_cash,
                'tenderedAmount' => $payment->amount, 'reference' => $payment->reference_number,
            ])->all(),
            'subtotal' => $invoice->subtotal, 'total' => $invoice->total, 'discount' => 0, 'paymentCharge' => 0, 'change' => 0,
            'depositAmount' => $data['depositAmount'], 'remainingAmount' => $data['remainingAmount'],
            'legacyDeposit' => $data['legacyDeposit'],
        ];
    }

    public function history(array $filters): array
    {
        $search = trim($filters['search'] ?? '');
        $query = DB::table('reservation_deposits as deposit')
            ->join('reservations as reservation', 'reservation.id', '=', 'deposit.reservation_id')
            ->join('customers as customer', 'customer.id', '=', 'reservation.customer_id')
            ->join('payment_methods as method', 'method.id', '=', 'deposit.payment_method_id')
            ->where('deposit.status', 'confirmed')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('customer.name', 'like', '%'.$search.'%')->orWhere('reservation.booking_code', 'like', '%'.$search.'%')
                        ->orWhere('reservation.queue_number', 'like', '%'.$search.'%')->orWhere('deposit.number', 'like', '%'.$search.'%');
                    if (preg_match('/^DP(\d{4})(\d{2})(\d{2})(\d{3,})$/i', $search, $match)
                        && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
                        $date = sprintf('%s-%s-%s', $match[1], $match[2], $match[3]);
                        $query->orWhere(fn ($query) => $query->where('deposit.id', (int) $match[4])
                            ->whereBetween('deposit.paid_at', [$date.' 00:00:00', $date.' 23:59:59']));
                    } elseif (preg_match('/^DP-?(\d+)$/i', $search, $match)) {
                        $query->orWhere('deposit.id', (int) $match[1]);
                    }
                });
            })
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->where('deposit.paid_at', '>=', $date.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->where('deposit.paid_at', '<=', $date.' 23:59:59'))
            ->when($filters['payment_method'] ?? null, fn ($query, $method) => $query->where('method.name', $method))
            ->orderByDesc('deposit.paid_at')->orderByDesc('deposit.id');
        $page = $query->paginate($filters['per_page'] ?? 20, [
            'deposit.id', 'deposit.reservation_id', 'deposit.number', 'deposit.amount', 'deposit.paid_at', 'deposit.receipt_snapshot',
            'customer.name as customer_name', 'reservation.queue_number', 'method.name as payment_method_name',
        ], 'page', $filters['page'] ?? 1);
        $rows = $page->getCollection()->map(function (object $row): object {
            $snapshot = $row->receipt_snapshot ? json_decode($row->receipt_snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
            $row->customer_name = $snapshot['customer_name'] ?? $row->customer_name;
            $row->payment_method_name = $snapshot['method_name'] ?? $row->payment_method_name;
            $row->number = $row->number ?: self::number((int) $row->id, $row->paid_at);
            unset($row->receipt_snapshot);

            return $row;
        });

        return ['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'total' => $page->total(), 'per_page' => $page->perPage()],
            'payment_options' => DB::table('reservation_deposits as deposit')->join('payment_methods as method', 'method.id', '=', 'deposit.payment_method_id')
                ->where('deposit.status', 'confirmed')->distinct()->orderBy('method.name')->pluck('method.name')];
    }
}
