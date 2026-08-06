<?php

namespace App\Http\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class SalonSnapshotService
{
    public function forUser(Authenticatable $user): array
    {
        $snapshot = [];

        if ($this->can($user, 'dashboard.view')) {
            $snapshot['dashboard'] = $this->dashboardAnalytics($user);
        }

        if ($this->can($user, 'reservations.view')) {
            $snapshot['reservations'] = $this->reservations($user);
        }

        $mayViewEmployeeMaster = $this->can($user, 'employees.view');
        $mayUseReservationStaff = $this->canAny($user, ['reservations.view', 'reservations.create']);

        if ($mayViewEmployeeMaster || $mayUseReservationStaff) {
            $serviceProviders = $this->serviceProviders();
            $snapshot['employees'] = $mayViewEmployeeMaster ? $this->employees() : $serviceProviders;

            if ($mayUseReservationStaff) {
                // Transitional alias plus the canonical explicit key for reservation forms.
                $snapshot['therapists'] = $serviceProviders;
                $snapshot['service_providers'] = $serviceProviders;
            }
        }

        if ($this->canAny($user, ['treatments.view', 'reservations.create', 'cashier.view'])) {
            $snapshot['treatments'] = $this->treatments();
        }

        if ($this->canAny($user, ['memberships.view', 'memberships.manage'])) {
            $snapshot['members'] = $this->members();
        }

        if ($this->can($user, 'products.view')) {
            $snapshot['products'] = $this->products();
            $snapshot['stock_movements'] = $this->stockMovements();
        }

        if ($this->canAny($user, ['cashier.view', 'cashier.process', 'finance.view'])) {
            $snapshot['transactions'] = $this->transactions();
        }

        if ($this->canAny($user, ['cashier.view', 'cashier.process'])) {
            $snapshot['payment_methods'] = DB::table('payment_methods')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'type', 'is_cash', 'requires_reference']);
        }

        if ($this->can($user, 'payroll.view')) {
            $snapshot['payrolls'] = DB::table('payrolls as payroll')
                ->join('employees as employee', 'employee.id', '=', 'payroll.employee_id')
                ->latest('payroll.period')
                ->get([
                    'payroll.id',
                    'payroll.employee_id',
                    'payroll.period',
                    'payroll.employee_name',
                    'payroll.position',
                    'payroll.base_salary',
                    'payroll.bonus',
                    'payroll.overtime',
                    'payroll.commission',
                    'payroll.late_deduction',
                    'payroll.other_deduction',
                    'payroll.net_salary',
                    'payroll.late_duration_minutes',
                    'payroll.status',
                    'payroll.finalized_at',
                    'employee.code as employee_code',
                ]);
        }

        if ($this->can($user, 'activity.view')) {
            $snapshot['activities'] = DB::table('activity_logs as activity')
                ->leftJoin('users as user', 'user.id', '=', 'activity.user_id')
                ->latest('activity.created_at')
                ->limit(50)
                ->get([
                    'activity.id',
                    'activity.action',
                    'activity.subject_type',
                    'activity.subject_id',
                    'activity.description',
                    'activity.metadata',
                    'activity.created_at',
                    'user.name as user_name',
                ])
                ->map(function (object $activity): object {
                    $activity->metadata = $activity->metadata ? json_decode($activity->metadata, true) : null;

                    return $activity;
                });
        }

        if ($this->canAny($user, ['memberships.view', 'memberships.manage', 'cashier.view', 'cashier.process'])) {
            $snapshot['promotions'] = DB::table('promotions')
                ->where('is_active', true)
                ->whereDate('starts_at', '<=', today())
                ->whereDate('ends_at', '>=', today())
                ->orderBy('name')
                ->get([
                    'id',
                    'code',
                    'name',
                    'discount_type',
                    'discount_percent',
                    'discount_amount',
                    'starts_at',
                    'ends_at',
                    'members_only',
                ]);
        }

        return $snapshot;
    }

    private function reservations(Authenticatable $user): array
    {
        $maySeePhone = $this->canAny($user, ['memberships.view', 'memberships.manage']);
        $maySeeOverrideReason = $this->canAny($user, ['reservations.override_conflict', 'activity.view']);
        $maySeeTransactionDetails = $this->canAny($user, ['cashier.view', 'cashier.process', 'finance.view']);
        $reservations = DB::table('reservations as reservation')
            ->join('customers as customer', 'customer.id', '=', 'reservation.customer_id')
            ->leftJoin('transactions as transaction', 'transaction.reservation_id', '=', 'reservation.id')
            ->orderByDesc('reservation.reservation_date')
            ->orderBy('reservation.reservation_time')
            ->orderBy('reservation.id')
            ->get([
                'reservation.id',
                'reservation.booking_code',
                'reservation.queue_number',
                'reservation.customer_id',
                'reservation.reservation_date',
                'reservation.reservation_time',
                'reservation.source',
                'reservation.status',
                'reservation.general_notes',
                'reservation.cancelled_at',
                'reservation.cancellation_reason',
                'reservation.created_at',
                'reservation.updated_at',
                'customer.name as customer_name',
                'customer.phone',
                'customer.is_member',
                'transaction.id as transaction_id',
                'transaction.status as transaction_status',
            ]);

        if ($reservations->isEmpty()) {
            return [];
        }

        $rawItems = DB::table('reservation_items')
            ->whereIn('reservation_id', $reservations->pluck('id'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'reservation_id',
                'treatment_id',
                'treatment_name',
                'duration_minutes',
                'normal_price',
                'unit_price',
                'discount_percent',
                'discount_amount',
                'net_price',
                'scheduled_start_at',
                'scheduled_end_at',
                'started_at',
                'continued_at',
                'ready_at',
                'overtime_at',
                'finished_at',
                'cancelled_at',
                'work_status',
                'notes',
                'sort_order',
            ]);
        $staff = DB::table('reservation_item_staff as assignment')
            ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
            ->whereIn('assignment.reservation_item_id', $rawItems->pluck('id'))
            ->orderByRaw("CASE WHEN assignment.role = 'primary' THEN 0 ELSE 1 END")
            ->orderBy('employee.name')
            ->get([
                'assignment.reservation_item_id',
                'assignment.employee_id',
                'assignment.role',
                'assignment.conflict_override_reason',
                'assignment.conflict_overridden_at',
                'employee.code as employee_code',
                'employee.name as employee_name',
                'employee.position',
                'employee.specialty',
            ])
            ->groupBy('reservation_item_id');
        $items = $rawItems->map(function (object $item) use ($staff, $maySeeOverrideReason): array {
            $assignments = collect($staff->get($item->id, []))->map(function (object $assignment) use ($maySeeOverrideReason): array {
                $data = [
                    'employee_id' => (int) $assignment->employee_id,
                    'employee_code' => $assignment->employee_code,
                    'employee_name' => $assignment->employee_name,
                    'position' => $assignment->position,
                    'specialty' => $assignment->specialty,
                    'role' => $assignment->role,
                    'conflict_overridden' => $assignment->conflict_overridden_at !== null,
                ];
                if ($maySeeOverrideReason) {
                    $data['conflict_override_reason'] = $assignment->conflict_override_reason;
                    $data['conflict_overridden_at'] = $assignment->conflict_overridden_at;
                }

                return $data;
            })->values()->all();

            return [
                'reservation_id' => (int) $item->reservation_id,
                'id' => (int) $item->id,
                'treatment_id' => (int) $item->treatment_id,
                'treatment_name' => $item->treatment_name,
                'duration_minutes' => (int) $item->duration_minutes,
                'normal_price' => (int) $item->normal_price,
                'unit_price' => (int) $item->unit_price,
                'discount_percent' => $item->discount_percent,
                'discount_amount' => (int) $item->discount_amount,
                'net_price' => (int) $item->net_price,
                'scheduled_start_at' => $item->scheduled_start_at,
                'scheduled_end_at' => $item->scheduled_end_at,
                'started_at' => $item->started_at,
                'continued_at' => $item->continued_at,
                'ready_at' => $item->ready_at,
                'overtime_at' => $item->overtime_at,
                'finished_at' => $item->finished_at,
                'cancelled_at' => $item->cancelled_at,
                'work_status' => $item->work_status,
                'notes' => $item->notes,
                'sort_order' => (int) $item->sort_order,
                'staff' => $assignments,
            ];
        })
            ->groupBy('reservation_id');

        return $reservations->map(function (object $reservation) use ($items, $maySeePhone, $maySeeTransactionDetails): array {
            $reservationItems = collect($items->get($reservation->id, []))->map(function (array $item): array {
                unset($item['reservation_id']);

                return $item;
            })->values()->all();
            $first = $reservationItems[0] ?? null;
            $primary = collect($first['staff'] ?? [])->firstWhere('role', 'primary') ?? collect($first['staff'] ?? [])->first();
            $result = [
                'id' => (int) $reservation->id,
                'booking_code' => $reservation->booking_code,
                'queue_number' => $reservation->queue_number,
                'customer_id' => (int) $reservation->customer_id,
                'customer_name' => $reservation->customer_name,
                'is_member' => (bool) $reservation->is_member,
                'reservation_date' => $reservation->reservation_date,
                'reservation_time' => $reservation->reservation_time,
                'source' => $reservation->source,
                'status' => $reservation->status,
                'notes' => $reservation->general_notes,
                'cancelled_at' => $reservation->cancelled_at,
                'cancellation_reason' => $reservation->cancellation_reason,
                'created_at' => $reservation->created_at,
                'updated_at' => $reservation->updated_at,
                'is_paid' => $reservation->transaction_status === 'paid',
                'items' => $reservationItems,
                // Transitional first-item fields used by the existing dashboard.
                'treatment_id' => $first['treatment_id'] ?? null,
                'treatment_name' => $first['treatment_name'] ?? null,
                'price' => $first['unit_price'] ?? 0,
                'therapist_id' => $primary['employee_id'] ?? null,
                'therapist_name' => $primary['employee_name'] ?? null,
            ];

            if ($maySeePhone) {
                $result['phone'] = $reservation->phone;
            }

            if ($maySeeTransactionDetails) {
                $result['transaction_id'] = $reservation->transaction_id ? (int) $reservation->transaction_id : null;
                $result['transaction_status'] = $reservation->transaction_status;
            }

            return $result;
        })->values()->all();
    }

    private function employees(): mixed
    {
        return DB::table('employees')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'position', 'specialty', 'is_service_provider', 'active']);
    }

    private function serviceProviders(): mixed
    {
        return DB::table('employees')
            ->where('active', true)
            ->where('is_service_provider', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'position', 'specialty', 'is_service_provider', 'active']);
    }

    private function treatments(): mixed
    {
        return DB::table('treatments as treatment')
            ->join('treatment_categories as category', 'category.id', '=', 'treatment.category_id')
            ->where('treatment.is_active', true)
            ->orderBy('category.sort_order')
            ->orderBy('treatment.name')
            ->get([
                'treatment.id',
                'treatment.code',
                'treatment.name',
                'treatment.category_id',
                'category.name as category',
                'treatment.duration_minutes',
                'treatment.normal_price',
                'treatment.normal_price as price',
                'treatment.default_commission_percent',
                'treatment.description',
            ]);
    }

    private function members(): mixed
    {
        return DB::table('customers')
            ->where('is_member', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'phone', 'email', 'member_since', 'visit_count', 'notes']);
    }

    private function products(): mixed
    {
        return DB::table('products as product')
            ->join('units as usage_unit', 'usage_unit.id', '=', 'product.usage_unit_id')
            ->join('units as purchase_unit', 'purchase_unit.id', '=', 'product.purchase_unit_id')
            ->orderBy('product.name')
            ->get([
                'product.id',
                'product.code',
                'product.name',
                'product.category',
                'product.current_stock',
                'product.current_stock as stock',
                'product.minimum_stock',
                'product.selling_price',
                'product.purchase_unit_id',
                'product.usage_unit_id',
                'product.purchase_to_usage_factor',
                'product.is_active',
                'usage_unit.code as unit',
                'usage_unit.name as usage_unit_name',
                'purchase_unit.name as purchase_unit_name',
            ]);
    }

    private function stockMovements(): mixed
    {
        return DB::table('stock_movements as movement')
            ->join('products as product', 'product.id', '=', 'movement.product_id')
            ->join('units as unit', 'unit.id', '=', 'movement.unit_id')
            ->leftJoin('users as user', 'user.id', '=', 'movement.created_by')
            ->latest('movement.occurred_at')
            ->limit(50)
            ->get([
                'movement.id',
                'movement.product_id',
                'product.name as product_name',
                'movement.type',
                'movement.quantity',
                'movement.stock_before',
                'movement.stock_after',
                'movement.source_type',
                'movement.source_id',
                'movement.reference',
                'movement.notes',
                'movement.occurred_at',
                'unit.code as unit',
                'user.name as user_name',
            ]);
    }

    private function transactions(): mixed
    {
        $transactions = DB::table('transactions as transaction')
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->latest('transaction.transacted_at')
            ->limit(50)
            ->get([
                'transaction.id',
                'transaction.number',
                'transaction.reservation_id',
                'transaction.customer_id',
                'customer.name as customer_name',
                'customer.is_member',
                'transaction.status',
                'transaction.transacted_at',
                'transaction.subtotal',
                'transaction.discount_percent',
                'transaction.discount_amount',
                'transaction.total',
                'transaction.paid_amount',
                'transaction.change_amount',
                'transaction.notes',
                'transaction.created_at',
            ]);

        if ($transactions->isEmpty()) {
            return $transactions;
        }

        $payments = DB::table('transaction_payments as payment')
            ->join('payment_methods as method', 'method.id', '=', 'payment.payment_method_id')
            ->whereIn('payment.transaction_id', $transactions->pluck('id'))
            ->where('payment.status', 'confirmed')
            ->orderBy('payment.id')
            ->get([
                'payment.id',
                'payment.transaction_id',
                'payment.amount',
                'payment.reference_number',
                'payment.paid_at',
                'method.id as payment_method_id',
                'method.code as payment_method_code',
                'method.name as payment_method_name',
            ])
            ->groupBy('transaction_id');

        return $transactions->map(function (object $transaction) use ($payments): object {
            $transaction->payments = collect($payments->get($transaction->id, []))->values();
            $transaction->payment_method = $transaction->payments->pluck('payment_method_name')->join(' + ');

            return $transaction;
        });
    }

    private function dashboardAnalytics(Authenticatable $user): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $start = $today->subDays(6);
        $data = [];

        if ($this->can($user, 'reservations.view')) {
            $activeReservations = DB::table('reservations')
                ->whereDate('reservation_date', $today)
                ->where('status', '!=', 'cancelled');
            $reservationCount = (clone $activeReservations)->count();
            $arrivedCount = (clone $activeReservations)->whereIn('status', ['arrived', 'in_service', 'completed'])->count();
            $data += [
                'reservations_today' => $reservationCount,
                'arrived_today' => $arrivedCount,
                'serving_today' => (clone $activeReservations)->where('status', 'in_service')->count(),
                'arrival_percent' => $reservationCount ? intdiv(($arrivedCount * 100) + intdiv($reservationCount, 2), $reservationCount) : 0,
            ];
        }

        if ($this->canAny($user, ['cashier.view', 'finance.view'])) {
            $todayRevenue = (int) DB::table('transactions')->where('status', 'paid')->whereDate('transacted_at', $today)->sum('total');
            $data['revenue_today'] = $todayRevenue;
            $data['revenue_yesterday'] = (int) DB::table('transactions')->where('status', 'paid')->whereDate('transacted_at', $today->subDay())->sum('total');
            $data['revenue_last_7_days'] = collect(range(0, 6))->map(function (int $offset) use ($start): array {
                $date = $start->addDays($offset);
                $dayNames = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

                return [
                    'date' => $date->toDateString(),
                    'label' => $dayNames[$date->dayOfWeekIso],
                    'total' => (int) DB::table('transactions')->where('status', 'paid')->whereDate('transacted_at', $date)->sum('total'),
                ];
            })->all();
            $data['treatment_last_7_days'] = DB::table('transaction_items as item')
                ->join('transactions as transaction', 'transaction.id', '=', 'item.transaction_id')
                ->where('transaction.status', 'paid')
                ->where('item.item_type', 'treatment')
                ->whereBetween('transaction.transacted_at', [$start->startOfDay(), $today->endOfDay()])
                ->select('item.name', DB::raw('SUM(item.quantity) as total'))
                ->groupBy('item.name')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn (object $item): array => ['name' => $item->name, 'total' => (int) $item->total])
                ->values();
        }

        if ($this->can($user, 'products.view')) {
            $data['low_stock_count'] = DB::table('products')
                ->where('is_active', true)
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->count();
        }

        if ($this->canAny($user, ['memberships.view', 'memberships.manage'])) {
            $monthStart = $today->startOfMonth();
            $data['member_count'] = DB::table('customers')->where('is_member', true)->where('is_active', true)->count();
            $data['new_members_month'] = DB::table('customers')
                ->where('is_member', true)
                ->whereBetween('member_since', [$monthStart->toDateString(), $today->toDateString()])
                ->count();
            $data['active_promotion_count'] = DB::table('promotions')
                ->where('is_active', true)
                ->whereDate('starts_at', '<=', $today)
                ->whereDate('ends_at', '>=', $today)
                ->count();
            $data['ending_promotions_month'] = DB::table('promotions')
                ->where('is_active', true)
                ->whereBetween('ends_at', [$today->toDateString(), $today->endOfMonth()->toDateString()])
                ->count();
            $monthTransactions = DB::table('transactions')
                ->where('status', 'paid')
                ->whereBetween('transacted_at', [$monthStart->startOfDay(), $today->endOfDay()]);
            $count = (clone $monthTransactions)->count();
            $memberCount = (clone $monthTransactions)
                ->join('customers', 'customers.id', '=', 'transactions.customer_id')
                ->where('customers.is_member', true)
                ->count();
            $data['member_transaction_percent'] = $count ? intdiv(($memberCount * 100) + intdiv($count, 2), $count) : 0;
        }

        if ($this->can($user, 'finance.view')) {
            $monthStart = $today->startOfMonth();
            $income = (int) DB::table('cash_entries')->where('status', 'posted')->where('type', 'income')->whereBetween('entry_date', [$monthStart->toDateString(), $today->toDateString()])->sum('amount');
            $expense = (int) DB::table('cash_entries')->where('status', 'posted')->where('type', 'expense')->whereBetween('entry_date', [$monthStart->toDateString(), $today->toDateString()])->sum('amount');
            $transactions = DB::table('transactions')->where('status', 'paid')->whereBetween('transacted_at', [$monthStart->startOfDay(), $today->endOfDay()]);
            $count = (clone $transactions)->count();
            $sum = (int) (clone $transactions)->sum('total');
            $data += [
                'month_income' => $income,
                'month_expense' => $expense,
                'month_balance' => $income - $expense,
                'month_transaction_count' => $count,
                'month_transaction_average' => $count ? intdiv($sum + intdiv($count, 2), $count) : 0,
            ];
        }

        return $data;
    }

    private function can(Authenticatable $user, string $permission): bool
    {
        return method_exists($user, 'can') && $user->can($permission);
    }

    private function canAny(Authenticatable $user, array $permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission): bool => $this->can($user, $permission));
    }
}
