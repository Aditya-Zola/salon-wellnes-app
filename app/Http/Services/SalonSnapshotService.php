<?php

namespace App\Http\Services;

use App\Http\Support\FixedPoint;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
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
        $mayManagePayroll = $this->can($user, 'payroll.manage');

        if ($mayViewEmployeeMaster || $mayUseReservationStaff || $mayManagePayroll) {
            $serviceProviders = $this->serviceProviders();
            $snapshot['employees'] = ($mayViewEmployeeMaster || $mayManagePayroll) ? $this->employees() : $serviceProviders;

            if ($mayUseReservationStaff) {
                // Transitional alias plus the canonical explicit key for reservation forms.
                $snapshot['therapists'] = $serviceProviders;
                $snapshot['service_providers'] = $serviceProviders;
            }
        }

        $mayManageTreatmentRecipes = $this->can($user, 'treatments.update');

        if ($this->canAny($user, ['treatments.view', 'reservations.create', 'cashier.view'])) {
            $snapshot['treatments'] = $this->treatments($mayManageTreatmentRecipes);
        }

        if ($this->canAny($user, ['memberships.view', 'memberships.manage'])) {
            $snapshot['members'] = $this->members();
        }

        if ($this->canAny($user, ['products.view', 'cashier.view', 'cashier.process', 'treatments.update'])) {
            $snapshot['products'] = $this->products();
            $snapshot['units'] = DB::table('units')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'decimal_places']);
        }

        if ($this->can($user, 'products.view')) {
            $snapshot['stock_movements'] = $this->stockMovements();
        }

        if ($this->canAny($user, ['cashier.view', 'cashier.process', 'finance.view', 'sales.view'])) {
            $snapshot['transactions'] = $this->transactions($this->can($user, 'sales.view'));
        }

        if ($this->can($user, 'finance.view')) {
            $snapshot['cash_entries'] = $this->cashEntries();
        }

        if ($this->canAny($user, ['cashier.view', 'cashier.process'])) {
            $snapshot['payment_methods'] = DB::table('payment_methods')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'type', 'is_cash', 'requires_reference', 'account_name', 'account_number', 'charge_percent', 'charge_default_enabled']);
            $snapshot['salon'] = $this->salonContact();
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
                ->leftJoin('reservations as reservation', function ($join): void {
                    $join->on('reservation.id', '=', 'activity.subject_id')
                        ->where('activity.subject_type', '=', 'reservation');
                })
                ->leftJoin('reservation_items as reservationItem', function ($join): void {
                    $join->on('reservationItem.id', '=', 'activity.subject_id')
                        ->where('activity.subject_type', '=', 'reservation_item');
                })
                ->leftJoin('reservations as itemReservation', 'itemReservation.id', '=', 'reservationItem.reservation_id')
                ->leftJoin('customers as reservationCustomer', 'reservationCustomer.id', '=', 'reservation.customer_id')
                ->leftJoin('customers as itemCustomer', 'itemCustomer.id', '=', 'itemReservation.customer_id')
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
                    DB::raw('COALESCE(reservationCustomer.name, itemCustomer.name) as reservation_customer_name'),
                    DB::raw('COALESCE(reservation.queue_number, itemReservation.queue_number) as reservation_queue_number'),
                ])
                ->map(function (object $activity): object {
                    $activity->metadata = $activity->metadata ? json_decode($activity->metadata, true) : null;

                    return $activity;
                });
        }

        $mayManageMemberships = $this->can($user, 'memberships.manage');
        if ($this->canAny($user, ['memberships.view', 'memberships.manage', 'cashier.view', 'cashier.process'])) {
            $promotions = DB::table('promotions');

            // Pengelola perlu melihat event yang sudah berakhir/nonaktif agar
            // masih dapat diperbaiki. Kasir hanya menerima event yang berlaku.
            if (! $mayManageMemberships) {
                $promotions
                    ->where('is_active', true)
                    ->whereDate('starts_at', '<=', today())
                    ->whereDate('ends_at', '>=', today());
            }

            $snapshot['promotions'] = $promotions
                ->orderByDesc('is_active')
                ->orderByDesc('ends_at')
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
                    'is_active',
                    'description',
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
                'scheduled_ready_at',
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
                'scheduled_ready_at' => $item->scheduled_ready_at,
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
        $products = DB::table('reservation_product_items')
            ->whereIn('reservation_id', $reservations->pluck('id'))
            ->orderBy('id')
            ->get([
                'reservation_id',
                'product_id',
                'product_name',
                'unit_code',
                'quantity',
                'unit_price',
            ])
            ->map(fn (object $item): array => [
                'reservation_id' => (int) $item->reservation_id,
                'product_id' => (int) $item->product_id,
                'name' => $item->product_name,
                'unit' => $item->unit_code,
                'quantity' => (float) $item->quantity,
                'unit_price' => (int) $item->unit_price,
            ])
            ->groupBy('reservation_id');

        return $reservations->map(function (object $reservation) use ($items, $products, $maySeePhone, $maySeeTransactionDetails): array {
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
                'product_items' => collect($products->get($reservation->id, []))->map(function (array $item): array {
                    unset($item['reservation_id']);

                    return $item;
                })->values()->all(),
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

    private function treatments(bool $withRecipes = false): mixed
    {
        $treatments = DB::table('treatments as treatment')
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

        if (! $withRecipes || $treatments->isEmpty()) {
            return $treatments;
        }

        $recipesByTreatment = DB::table('treatment_product_recipes as recipe')
            ->join('products as product', 'product.id', '=', 'recipe.product_id')
            ->join('units as unit', 'unit.id', '=', 'recipe.unit_id')
            ->whereIn('recipe.treatment_id', $treatments->pluck('id')->all())
            ->orderBy('product.name')
            ->get([
                'recipe.treatment_id',
                'recipe.product_id',
                'recipe.quantity',
                'product.name as product_name',
                'unit.code as unit',
            ])
            ->groupBy('treatment_id');
        $commissionProfilesByTreatment = DB::table('treatment_commission_splits')
            ->whereIn('treatment_id', $treatments->pluck('id')->all())
            ->orderBy('therapist_count')
            ->orderBy('therapist_position')
            ->get([
                'treatment_id',
                'therapist_count',
                'therapist_position',
                'commission_percent',
            ])
            ->groupBy('treatment_id');

        return $treatments->map(function (object $treatment) use ($recipesByTreatment, $commissionProfilesByTreatment): object {
            $treatment->recipes = ($recipesByTreatment->get($treatment->id) ?? collect())->values();
            $treatment->commission_profiles = collect($commissionProfilesByTreatment->get($treatment->id, []))
                ->groupBy('therapist_count')
                ->map(function ($splits, $therapistCount): array {
                    return [
                        'therapist_count' => (int) $therapistCount,
                        'commission_percents' => collect($splits)
                            ->sortBy('therapist_position')
                            ->pluck('commission_percent')
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all();

            return $treatment;
        });
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
                'product.cost_price',
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

    public function salesPage(Authenticatable $user, int $page = 1, int $perPage = 20, ?string $search = null, ?string $paymentMethod = null): array
    {
        abort_unless($this->can($user, 'sales.view'), 403);

        $search = trim((string) $search);
        $paymentMethod = trim((string) $paymentMethod);
        $compactSearch = preg_replace('/[-_\s]+/', '', $search);
        $query = DB::table('transactions as transaction')
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->where('transaction.status', 'paid')
            ->when($search !== '', function ($builder) use ($search, $compactSearch): void {
                $like = '%'.$search.'%';
                $compactLike = '%'.$compactSearch.'%';
                $builder->where(function ($nested) use ($like, $compactLike): void {
                    $nested->where('transaction.number', 'like', $like)
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(transaction.number, '-', ''), '_', ''), ' ', '') LIKE ?", [$compactLike])
                        ->orWhere('customer.name', 'like', $like);
                });
            })
            ->when($paymentMethod !== '', function ($builder) use ($paymentMethod): void {
                $builder->whereExists(function ($payment) use ($paymentMethod): void {
                    $payment->selectRaw('1')
                        ->from('transaction_payments as payment')
                        ->join('payment_methods as method', 'method.id', '=', 'payment.payment_method_id')
                        ->whereColumn('payment.transaction_id', 'transaction.id')
                        ->where('payment.status', 'confirmed')
                        ->where('method.name', $paymentMethod);
                });
            })
            ->latest('transaction.transacted_at');

        $paginator = $query->paginate(min(max($perPage, 10), 50), [
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
            'transaction.payment_charge_amount',
            'transaction.total',
            'transaction.paid_amount',
            'transaction.change_amount',
            'transaction.refunded_amount',
            'transaction.notes',
            'transaction.created_at',
            'transaction.finalized_by',
        ], 'page', $page);
        $transactions = $paginator->getCollection();

        if ($transactions->isNotEmpty()) {
            $payments = DB::table('transaction_payments as payment')
                ->join('payment_methods as method', 'method.id', '=', 'payment.payment_method_id')
                ->whereIn('payment.transaction_id', $transactions->pluck('id'))
                ->where('payment.status', 'confirmed')
                ->orderBy('payment.id')
                ->get(['payment.id', 'payment.transaction_id', 'payment.amount', 'payment.base_amount', 'payment.charge_percent', 'payment.charge_amount', 'payment.charge_enabled', 'payment.tendered_amount', 'payment.reference_number', 'payment.paid_at', 'method.id as payment_method_id', 'method.code as payment_method_code', 'method.name as payment_method_name', 'method.is_cash as payment_method_is_cash'])
                ->groupBy('transaction_id');
            $items = DB::table('transaction_items')
                ->whereIn('transaction_id', $transactions->pluck('id'))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'transaction_id', 'item_type', 'item_id', 'name', 'quantity', 'unit_price', 'total_amount', 'sort_order'])
                ->groupBy('transaction_id');
            $returnedQuantities = DB::table('sales_return_items as item')
                ->join('sales_returns as sales_return', 'sales_return.id', '=', 'item.sales_return_id')
                ->whereIn('sales_return.transaction_id', $transactions->pluck('id'))
                ->where('sales_return.status', 'posted')
                ->select('item.transaction_item_id', DB::raw('SUM(item.quantity) as quantity'))
                ->groupBy('item.transaction_item_id')
                ->pluck('quantity', 'transaction_item_id');
            $returns = DB::table('sales_returns as sales_return')
                ->join('payment_methods as method', 'method.id', '=', 'sales_return.refund_payment_method_id')
                ->whereIn('sales_return.transaction_id', $transactions->pluck('id'))
                ->where('sales_return.status', 'posted')
                ->orderByDesc('sales_return.returned_at')
                ->get([
                    'sales_return.id',
                    'sales_return.transaction_id',
                    'sales_return.number',
                    'sales_return.total_amount',
                    'sales_return.reason',
                    'sales_return.reference_number',
                    'sales_return.returned_at',
                    'method.name as payment_method_name',
                ])
                ->groupBy('transaction_id');
            $cashiers = DB::table('users')
                ->whereIn('id', $transactions->pluck('finalized_by')->filter()->unique())
                ->pluck('name', 'id');
            $transactions = $transactions->map(function (object $transaction) use ($payments, $items, $cashiers, $returnedQuantities, $returns): object {
                $transaction->payments = collect($payments->get($transaction->id, []))->values();
                $transaction->payment_method = $transaction->payments->pluck('payment_method_name')->join(' + ');
                $transaction->items = collect($items->get($transaction->id, []))->map(function (object $item) use ($returnedQuantities): object {
                    $item->returned_quantity = (string) ($returnedQuantities->get($item->id) ?? '0.0000');
                    $item->refundable_quantity = max(0, (float) $item->quantity - (float) $item->returned_quantity);

                    return $item;
                })->values();
                $transaction->returns = collect($returns->get($transaction->id, []))->values();
                $transaction->net_total = max(0, (int) $transaction->total - (int) $transaction->refunded_amount);
                $transaction->cashier_name = $cashiers->get($transaction->finalized_by) ?: 'Kasir Selesa';

                return $transaction;
            })->values();
        }

        return [
            'data' => $transactions,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'payment_options' => DB::table('payment_methods')
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->values(),
            'refund_payment_options' => DB::table('payment_methods')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'type', 'is_cash', 'requires_reference'])
                ->values(),
        ];
    }

    public function salesReturnsPage(Authenticatable $user, int $page = 1, int $perPage = 20, ?string $search = null, ?string $paymentMethod = null): array
    {
        abort_unless($this->can($user, 'sales.view'), 403);

        $search = trim((string) $search);
        $paymentMethod = trim((string) $paymentMethod);
        $compactSearch = preg_replace('/[-_\s]+/', '', $search);
        $query = DB::table('sales_returns as sales_return')
            ->join('transactions as transaction', 'transaction.id', '=', 'sales_return.transaction_id')
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->join('payment_methods as method', 'method.id', '=', 'sales_return.refund_payment_method_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'sales_return.created_by')
            ->where('sales_return.status', 'posted')
            ->when($search !== '', function ($builder) use ($search, $compactSearch): void {
                $like = '%'.$search.'%';
                $compactLike = '%'.$compactSearch.'%';
                $builder->where(function ($nested) use ($like, $compactLike): void {
                    $nested->where('sales_return.number', 'like', $like)
                        ->orWhere('transaction.number', 'like', $like)
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(transaction.number, '-', ''), '_', ''), ' ', '') LIKE ?", [$compactLike])
                        ->orWhere('customer.name', 'like', $like);
                });
            })
            ->when($paymentMethod !== '', fn ($builder) => $builder->where('method.name', $paymentMethod))
            ->latest('sales_return.returned_at');

        $paginator = $query->paginate(min(max($perPage, 10), 50), [
            'sales_return.id',
            'sales_return.number',
            'sales_return.total_amount',
            'sales_return.reason',
            'sales_return.reference_number',
            'sales_return.returned_at',
            'transaction.number as transaction_number',
            'customer.name as customer_name',
            'method.name as payment_method_name',
            'creator.name as created_by_name',
        ], 'page', $page);
        $returns = $paginator->getCollection();

        if ($returns->isNotEmpty()) {
            $items = DB::table('sales_return_items')
                ->whereIn('sales_return_id', $returns->pluck('id'))
                ->orderBy('id')
                ->get(['sales_return_id', 'product_name', 'quantity', 'amount'])
                ->groupBy('sales_return_id');
            $returns = $returns->map(function (object $salesReturn) use ($items): object {
                $salesReturn->items = collect($items->get($salesReturn->id, []))->values();

                return $salesReturn;
            })->values();
        }

        return [
            'data' => $returns,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'payment_options' => DB::table('payment_methods')->where('is_active', true)->orderBy('name')->pluck('name')->values(),
        ];
    }

    public function membersPage(Authenticatable $user, int $page = 1, int $perPage = 10, ?string $search = null): array
    {
        abort_unless($this->canAny($user, ['memberships.view', 'memberships.manage']), 403);

        $search = trim((string) $search);
        $query = DB::table('customers')
            ->where('is_member', true)
            ->where('is_active', true)
            ->when($search !== '', function ($builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder->where('phone', 'like', $like);
            })
            ->orderBy('name')
            ->orderBy('id');
        $paginator = $query->paginate(min(max($perPage, 10), 50), [
            'id',
            'code',
            'name',
            'phone',
            'email',
            'member_since',
            'visit_count',
            'notes',
        ], 'page', $page);

        return [
            'data' => $paginator->getCollection()->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function productsPage(Authenticatable $user, int $page = 1, int $perPage = 20, ?string $search = null): array
    {
        abort_unless($this->can($user, 'products.view'), 403);

        $search = trim((string) $search);
        $query = DB::table('products as product')
            ->join('units as usage_unit', 'usage_unit.id', '=', 'product.usage_unit_id')
            ->join('units as purchase_unit', 'purchase_unit.id', '=', 'product.purchase_unit_id')
            ->when($search !== '', function ($builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder->where(function ($nested) use ($like): void {
                    $nested->where('product.name', 'like', $like)
                        ->orWhere('product.code', 'like', $like)
                        ->orWhere('product.category', 'like', $like);
                });
            })
            ->orderBy('product.name')
            ->orderBy('product.id');
        $paginator = $query->paginate(min(max($perPage, 10), 50), [
            'product.id',
            'product.code',
            'product.name',
            'product.category',
            'product.current_stock',
            'product.current_stock as stock',
            'product.minimum_stock',
            'product.selling_price',
            'product.cost_price',
            'product.purchase_unit_id',
            'product.usage_unit_id',
            'product.purchase_to_usage_factor',
            'product.is_active',
            'usage_unit.code as unit',
            'usage_unit.name as usage_unit_name',
            'purchase_unit.name as purchase_unit_name',
        ], 'page', $page);

        return [
            'data' => $paginator->getCollection()->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function stockMovementsPage(
        Authenticatable $user,
        int $page = 1,
        int $perPage = 20,
        ?string $from = null,
        ?string $to = null,
    ): array {
        abort_unless($this->can($user, 'products.view'), 403);

        $query = DB::table('stock_movements as movement')
            ->join('products as product', 'product.id', '=', 'movement.product_id')
            ->join('units as unit', 'unit.id', '=', 'movement.unit_id')
            ->leftJoin('users as user', 'user.id', '=', 'movement.created_by')
            ->when($from, fn ($builder, string $date) => $builder->where('movement.occurred_at', '>=', $date.' 00:00:00'))
            ->when($to, fn ($builder, string $date) => $builder->where('movement.occurred_at', '<=', $date.' 23:59:59'))
            ->orderByDesc('movement.occurred_at')
            ->orderByDesc('movement.id');
        $paginator = $query->paginate(min(max($perPage, 10), 50), [
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
        ], 'page', $page);

        return [
            'data' => $paginator->getCollection()->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function transactions(bool $includeItems = false): mixed
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
                'transaction.payment_charge_amount',
                'transaction.total',
                'transaction.paid_amount',
                'transaction.change_amount',
                'transaction.refunded_amount',
                'transaction.notes',
                'transaction.created_at',
                'transaction.finalized_by',
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
                'payment.base_amount',
                'payment.charge_percent',
                'payment.charge_amount',
                'payment.charge_enabled',
                'payment.tendered_amount',
                'payment.reference_number',
                'payment.paid_at',
                'method.id as payment_method_id',
                'method.code as payment_method_code',
                'method.name as payment_method_name',
                'method.is_cash as payment_method_is_cash',
            ])
            ->groupBy('transaction_id');

        $items = $includeItems
            ? DB::table('transaction_items')
                ->whereIn('transaction_id', $transactions->pluck('id'))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get([
                    'id',
                    'transaction_id',
                    'item_type',
                    'name',
                    'quantity',
                    'unit_price',
                    'total_amount',
                    'sort_order',
                ])
                ->groupBy('transaction_id')
            : collect();

        $cashiers = $includeItems
            ? DB::table('users')
                ->whereIn('id', $transactions->pluck('finalized_by')->filter()->unique())
                ->pluck('name', 'id')
            : collect();

        return $transactions->map(function (object $transaction) use ($payments, $items, $cashiers, $includeItems): object {
            $transaction->payments = collect($payments->get($transaction->id, []))->values();
            $transaction->payment_method = $transaction->payments->pluck('payment_method_name')->join(' + ');

            if ($includeItems) {
                $transaction->items = collect($items->get($transaction->id, []))->values();
                $transaction->cashier_name = $cashiers->get($transaction->finalized_by) ?: 'Kasir Selesa';
            }

            return $transaction;
        });
    }

    private function salonContact(): array
    {
        $settings = DB::table('sale_settings')
            ->whereIn('key', ['salon_address', 'salon_whatsapp'])
            ->pluck('value', 'key');

        return [
            'address' => $settings->get('salon_address') ?: 'Jl. Telaga Asmara, Tlogosari Kulon, Semarang',
            'whatsapp' => $settings->get('salon_whatsapp') ?: '081128702019',
        ];
    }

    private function cashEntries(
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $limit = 100,
    ): mixed {
        $query = DB::table('cash_entries as entry')
            ->leftJoin('users as creator', 'creator.id', '=', 'entry.created_by')
            ->where('entry.status', 'posted')
            ->whereNull('entry.transaction_payment_id')
            ->when($from, fn ($builder, CarbonImmutable $date) => $builder->whereDate('entry.entry_date', '>=', $date->toDateString()))
            ->when($to, fn ($builder, CarbonImmutable $date) => $builder->whereDate('entry.entry_date', '<=', $date->toDateString()))
            ->orderByDesc('entry.entry_date')
            ->orderByDesc('entry.id');
        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get([
            'entry.id',
            'entry.transaction_payment_id',
            'entry.type',
            'entry.report_group',
            'entry.category',
            'entry.description',
            'entry.amount',
            'entry.entry_date',
            'entry.created_at',
            'creator.name as created_by_name',
        ])
            ->map(function (object $entry): object {
                $entry->automated = false;

                return $entry;
            })
            ->values();
    }

    /**
     * Menyediakan laporan keuangan untuk rentang pilihan pengguna. Neraca
     * bersifat posisi saldo, sehingga memakai satu tanggal "per" yang terpisah
     * dari rentang arus kas dan laba-rugi.
     */
    public function financeReport(
        Authenticatable $user,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $asOf,
    ): array {
        abort_unless($this->can($user, 'finance.view'), 403);

        $manualCashEntries = DB::table('cash_entries')
            ->where('status', 'posted')
            ->whereNull('transaction_payment_id')
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]);
        $income = (int) (clone $manualCashEntries)->where('type', 'income')->sum('amount');
        $expense = (int) (clone $manualCashEntries)->where('type', 'expense')->sum('amount');
        $count = (clone $manualCashEntries)->count();
        $expenseCategories = (clone $manualCashEntries)
            ->where('type', 'expense')
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn (object $item): array => ['category' => $item->category, 'total' => (int) $item->total])
            ->values()
            ->all();

        return [
            'cash_flow' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'income' => $income,
                'expense' => $expense,
                'balance' => $income - $expense,
                'entry_count' => $count,
                'expense_categories' => $expenseCategories,
                'payment_flows' => $this->paymentFlows($from, $to)->values()->all(),
                'cash_entries' => $this->cashEntries($from, $to, null)->all(),
            ],
            'profit_loss' => $this->profitLoss($from, $to),
            'balance_sheet' => $this->balanceSheet($asOf),
        ];
    }

    private function dashboardAnalytics(Authenticatable $user): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
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

        if ($this->can($user, 'therapist_attendance.view')) {
            $therapists = DB::table('employees as employee')
                ->leftJoin('employee_attendances as attendance', function ($join) use ($today): void {
                    $join->on('attendance.employee_id', '=', 'employee.id')
                        ->where('attendance.attendance_date', '=', $today->toDateString());
                })
                ->where('employee.active', true)
                ->where('employee.is_service_provider', true)
                ->orderBy('employee.name')
                ->get([
                    'employee.id as employee_id',
                    'employee.name',
                    'employee.specialty',
                    'attendance.status',
                ])
                ->map(fn (object $therapist): array => [
                    'employee_id' => (int) $therapist->employee_id,
                    'name' => $therapist->name,
                    'specialty' => $therapist->specialty,
                    // Sama seperti halaman kehadiran: tanpa catatan berarti masuk.
                    'status' => $therapist->status ?: 'present',
                ])
                ->values();

            $data['therapist_attendance_today'] = [
                'present' => $therapists->where('status', 'present')->values()->all(),
                'off' => $therapists->where('status', 'off')->values()->all(),
            ];
        }

        if ($this->can($user, 'employees.view')) {
            $data['therapist_rating_summary_current_month'] = $this->therapistRatingSummary(
                $today->startOfMonth(),
                $today,
            );
        }

        if ($this->canAny($user, ['cashier.view', 'finance.view'])) {
            $todayRevenue = $this->netRevenueForDate($today);
            $data['revenue_today'] = $todayRevenue;
            $data['revenue_yesterday'] = $this->netRevenueForDate($today->subDay());
            $yearStart = $today->startOfYear();
            $monthStart = $today->startOfMonth();
            $revenueStart = $weekStart->lessThan($yearStart) ? $weekStart : $yearStart;
            $revenueByDay = $this->netRevenueByDay($revenueStart, $today);
            $paymentFlowsToday = $this->paymentFlows($today, $today);
            $data['revenue_by_payment_method_today'] = collect([
                ['key' => 'total', 'name' => 'Total pendapatan', 'total' => $todayRevenue, 'type' => 'total'],
            ])->concat($paymentFlowsToday)->values()->all();
            $data['revenue_last_7_days'] = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $revenueByDay): array {
                $date = $weekStart->addDays($offset);
                $dayNames = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

                return [
                    'date' => $date->toDateString(),
                    'label' => $dayNames[$date->dayOfWeekIso],
                    'total' => (int) $revenueByDay->get($date->toDateString(), 0),
                ];
            })->all();
            $data['revenue_current_month'] = collect(range(0, $monthStart->diffInDays($today)))
                ->map(function (int $offset) use ($monthStart, $revenueByDay): array {
                    $date = $monthStart->addDays($offset);

                    return [
                        'date' => $date->toDateString(),
                        'label' => $date->format('d'),
                        'total' => (int) $revenueByDay->get($date->toDateString(), 0),
                    ];
                })
                ->all();
            $revenueByMonth = $revenueByDay
                ->groupBy(fn (int $total, string $date): string => substr($date, 0, 7))
                ->map(fn (Collection $dailyRevenue): int => (int) $dailyRevenue->sum());
            $monthNames = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
            $data['revenue_current_year'] = collect(range(0, $today->month - 1))
                ->map(function (int $offset) use ($yearStart, $revenueByMonth, $monthNames): array {
                    $month = $yearStart->addMonths($offset);

                    return [
                        'date' => $month->format('Y-m'),
                        'label' => $monthNames[$month->month],
                        'total' => (int) $revenueByMonth->get($month->format('Y-m'), 0),
                    ];
                })
                ->all();
            $data['treatment_last_7_days'] = DB::table('transaction_items as item')
                ->join('transactions as transaction', 'transaction.id', '=', 'item.transaction_id')
                ->where('transaction.status', 'paid')
                ->where('item.item_type', 'treatment')
                ->whereBetween('transaction.transacted_at', [$weekStart->startOfDay(), $today->endOfDay()])
                ->select('item.name', DB::raw('SUM(item.quantity) as total'))
                ->groupBy('item.name')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn (object $item): array => ['name' => $item->name, 'total' => (int) $item->total])
                ->values();
            $data['treatment_most_frequent_current_month'] = DB::table('transaction_items as item')
                ->join('transactions as transaction', 'transaction.id', '=', 'item.transaction_id')
                ->where('transaction.status', 'paid')
                ->where('item.item_type', 'treatment')
                ->whereBetween('transaction.transacted_at', [$monthStart->startOfDay(), $today->endOfDay()])
                ->select('item.item_id', 'item.name', DB::raw('SUM(item.quantity) as total'))
                ->groupBy('item.item_id', 'item.name')
                ->orderByDesc('total')
                ->orderBy('item.name')
                ->limit(5)
                ->get()
                ->map(fn (object $item): array => [
                    'id' => $item->item_id ? (int) $item->item_id : null,
                    'name' => $item->name,
                    'total' => (float) $item->total,
                ])
                ->values()
                ->all();
            $treatmentsByDay = DB::table('transaction_items as item')
                ->join('transactions as trx', 'trx.id', '=', 'item.transaction_id')
                ->where('trx.status', 'paid')
                ->where('item.item_type', 'treatment')
                ->whereBetween('trx.transacted_at', [$monthStart->startOfDay(), $today->endOfDay()])
                ->selectRaw('DATE(trx.transacted_at) as date, SUM(item.quantity) as total')
                ->groupByRaw('DATE(trx.transacted_at)')
                ->pluck('total', 'date');
            $data['treatment_daily_current_month'] = collect(range(0, $monthStart->diffInDays($today)))
                ->map(function (int $offset) use ($monthStart, $treatmentsByDay): array {
                    $date = $monthStart->addDays($offset);

                    return [
                        'date' => $date->toDateString(),
                        'label' => $date->format('d'),
                        'total' => (int) ($treatmentsByDay->get($date->toDateString(), 0)),
                    ];
                })
                ->all();
        }

        if ($this->can($user, 'products.view')) {
            $data['low_stock_count'] = DB::table('products')
                ->where('is_active', true)
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->count();

            // Menu treatment yang terdampak bila salah satu bahan resepnya sudah di batas minimum.
            $data['treatment_stock_alerts'] = DB::table('treatment_product_recipes as recipe')
                ->join('treatments as treatment', 'treatment.id', '=', 'recipe.treatment_id')
                ->join('products as product', 'product.id', '=', 'recipe.product_id')
                ->join('units as unit', 'unit.id', '=', 'product.usage_unit_id')
                ->where('treatment.is_active', true)
                ->where('product.is_active', true)
                ->whereColumn('product.current_stock', '<=', 'product.minimum_stock')
                ->orderBy('product.current_stock')
                ->orderBy('treatment.name')
                ->limit(5)
                ->get([
                    'treatment.id as treatment_id',
                    'treatment.name as treatment_name',
                    'product.id as product_id',
                    'product.name as product_name',
                    'product.current_stock',
                    'product.minimum_stock',
                    'unit.code as unit',
                ])
                ->map(fn (object $item): array => [
                    'treatment_id' => (int) $item->treatment_id,
                    'treatment_name' => $item->treatment_name,
                    'product_id' => (int) $item->product_id,
                    'product_name' => $item->product_name,
                    'current_stock' => (float) $item->current_stock,
                    'minimum_stock' => (float) $item->minimum_stock,
                    'unit' => $item->unit,
                ])
                ->values()
                ->all();
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
            $manualCashEntries = DB::table('cash_entries')
                ->where('status', 'posted')
                ->whereNull('transaction_payment_id')
                ->whereBetween('entry_date', [$monthStart->toDateString(), $today->toDateString()]);
            $income = (int) (clone $manualCashEntries)->where('type', 'income')->sum('amount');
            $expense = (int) (clone $manualCashEntries)->where('type', 'expense')->sum('amount');
            $count = (clone $manualCashEntries)->count();
            $expenseCategories = (clone $manualCashEntries)
                ->where('type', 'expense')
                ->select('category', DB::raw('SUM(amount) as total'))
                ->groupBy('category')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn (object $item): array => ['category' => $item->category, 'total' => (int) $item->total])
                ->values()
                ->all();
            $data += [
                'month_income' => $income,
                'month_expense' => $expense,
                'month_balance' => $income - $expense,
                'month_cash_entry_count' => $count,
                'month_expense_categories' => $expenseCategories,
                'payment_flows_month' => $this->paymentFlows($monthStart, $today)->values()->all(),
                'profit_loss_month' => $this->profitLoss($monthStart, $today),
                'balance_sheet' => $this->balanceSheet($today),
            ];
        }

        return $data;
    }

    /**
     * Rekap ini sengaja menggunakan jumlah dan rerata bersamaan. Therapist
     * dengan satu penilaian sempurna tidak otomatis mengalahkan therapist
     * yang konsisten dinilai baik oleh lebih banyak pelanggan.
     */
    private function therapistRatingSummary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ratings = DB::table('therapist_ratings as rating')
            ->join('employees as employee', 'employee.id', '=', 'rating.employee_id')
            ->whereBetween('rating.rated_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('employee.name')
            ->get([
                'employee.id as employee_id',
                'employee.name',
                'employee.position',
                'rating.stars',
                'rating.review',
                'rating.rated_at',
            ]);

        return $ratings
            ->groupBy('employee_id')
            ->map(function (Collection $employeeRatings): array {
                $first = $employeeRatings->first();
                $total = $employeeRatings->count();
                $counts = [
                    'stars_1' => $employeeRatings->where('stars', 1)->count(),
                    'stars_2' => $employeeRatings->where('stars', 2)->count(),
                    'stars_3' => $employeeRatings->where('stars', 3)->count(),
                    'stars_4' => $employeeRatings->where('stars', 4)->count(),
                    'stars_5' => $employeeRatings->where('stars', 5)->count(),
                ];
                $score = $employeeRatings->sum(fn (object $rating): int => (int) $rating->stars);
                $reviews = $employeeRatings
                    ->filter(fn (object $rating): bool => filled($rating->review))
                    ->sortByDesc('rated_at')
                    ->map(fn (object $rating): array => [
                        'stars' => (int) $rating->stars,
                        'review' => trim($rating->review),
                        'rated_at' => CarbonImmutable::parse($rating->rated_at)->toIso8601String(),
                    ])
                    ->values()
                    ->all();

                return [
                    'employee_id' => (int) $first->employee_id,
                    'name' => $first->name,
                    'position' => $first->position,
                    'total' => $total,
                    ...$counts,
                    'average' => round($score / max(1, $total), 2),
                    'review_count' => count($reviews),
                    'reviews' => $reviews,
                ];
            })
            ->sort(function (array $first, array $second): int {
                return ($second['average'] <=> $first['average'])
                    ?: ($second['total'] <=> $first['total'])
                    ?: ($second['stars_5'] <=> $first['stars_5'])
                    ?: strcasecmp($first['name'], $second['name']);
            })
            ->values()
            ->all();
    }

    /**
     * Laba-rugi berbasis transaksi yang sudah dibayar. HPP memakai snapshot
     * pada item transaksi, sehingga mengubah HPP master hari ini tidak mengubah
     * hasil transaksi yang sudah lampau.
     */
    private function profitLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = DB::table('transaction_items as item')
            ->join('transactions as transaction', 'transaction.id', '=', 'item.transaction_id')
            ->where('transaction.status', 'paid')
            ->whereBetween('transaction.transacted_at', [$from->startOfDay(), $to->endOfDay()]);
        $salesRevenue = (int) (clone $items)->sum('item.total_amount');
        $grossHpp = (int) (clone $items)->sum('item.cost_amount');
        $paymentCharges = (int) DB::table('transactions')
            ->where('status', 'paid')
            ->whereBetween('transacted_at', [$from->startOfDay(), $to->endOfDay()])
            ->sum('payment_charge_amount');
        $returns = (int) DB::table('sales_return_items as item')
            ->join('sales_returns as sales_return', 'sales_return.id', '=', 'item.sales_return_id')
            ->where('sales_return.status', 'posted')
            ->whereBetween('sales_return.returned_at', [$from->startOfDay(), $to->endOfDay()])
            ->sum('item.amount');
        $restockedReturnCosts = DB::table('sales_return_items as return_item')
            ->join('sales_returns as sales_return', 'sales_return.id', '=', 'return_item.sales_return_id')
            ->join('transaction_items as item', 'item.id', '=', 'return_item.transaction_item_id')
            ->where('sales_return.status', 'posted')
            ->where('return_item.restocked', true)
            ->whereBetween('sales_return.returned_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['item.unit_cost', 'return_item.quantity'])
            ->reduce(
                fn (int $total, object $item): int => $total + FixedPoint::multiply(
                    (int) ($item->unit_cost ?? 0),
                    FixedPoint::parse((string) $item->quantity, FixedPoint::STOCK_SCALE),
                    FixedPoint::STOCK_SCALE,
                ),
                0,
            );
        $manualEntries = DB::table('cash_entries')
            ->where('status', 'posted')
            ->whereNull('transaction_payment_id')
            ->where('report_group', 'operating')
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]);
        $manualIncome = (int) (clone $manualEntries)->where('type', 'income')->sum('amount');
        $manualExpense = (int) (clone $manualEntries)->where('type', 'expense')->sum('amount');
        $revenue = $salesRevenue - $returns + $paymentCharges + $manualIncome;
        $netHpp = max(0, $grossHpp - $restockedReturnCosts);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sales_revenue' => $salesRevenue,
            'sales_returns' => $returns,
            'payment_charge_income' => $paymentCharges,
            'manual_income' => $manualIncome,
            'revenue_total' => $revenue,
            'gross_hpp' => $grossHpp,
            'restocked_return_hpp' => $restockedReturnCosts,
            'hpp_total' => $netHpp,
            'manual_expense' => $manualExpense,
            'net_profit' => $revenue - $netHpp - $manualExpense,
        ];
    }

    /**
     * Neraca dasar tanpa utang/piutang: kas fisik, saldo setiap rekening, dan
     * nilai stok berdasarkan HPP aktif pada master produk.
     */
    private function balanceSheet(CarbonImmutable $to): array
    {
        $from = CarbonImmutable::parse('2000-01-01', config('app.timezone'));
        $paymentFlows = $this->paymentFlows($from, $to);
        $manualEntries = DB::table('cash_entries')
            ->where('status', 'posted')
            ->whereNull('transaction_payment_id')
            ->whereDate('entry_date', '<=', $to->toDateString());
        $manualCash = (int) (clone $manualEntries)->where('type', 'income')->sum('amount')
            - (int) (clone $manualEntries)->where('type', 'expense')->sum('amount');
        $cashPayments = (int) $paymentFlows->where('is_cash', true)->sum('net');
        $cash = $cashPayments + $manualCash;
        $inventory = $this->inventoryValueAt($to);
        $accounts = $paymentFlows
            ->reject(fn (array $flow): bool => $flow['is_cash'])
            ->map(fn (array $flow): array => [
                'id' => $flow['id'],
                'name' => $flow['name'],
                'type' => $flow['type'],
                'account_name' => $flow['account_name'],
                'account_number' => $flow['account_number'],
                'balance' => $flow['net'],
            ])
            ->values()
            ->all();
        $bankBalance = array_sum(array_column($accounts, 'balance'));
        $assets = $cash + $bankBalance + $inventory;

        return [
            'as_of' => $to->toDateString(),
            'cash' => $cash,
            'manual_cash' => $manualCash,
            'payment_accounts' => $accounts,
            'bank_total' => $bankBalance,
            'inventory' => $inventory,
            'liabilities' => 0,
            'assets_total' => $assets,
            'equity' => $assets,
        ];
    }

    /**
     * Kuantitas stok lampau direkonstruksi dari posisi stok saat ini dikurangi
     * seluruh mutasi setelah tanggal neraca. Dengan begitu stok awal yang
     * sudah ada sebelum sistem dipakai juga tetap ikut terhitung.
     */
    private function inventoryValueAt(CarbonImmutable $to): int
    {
        $changesAfter = DB::table('stock_movements')
            ->where('occurred_at', '>', $to->endOfDay())
            ->get(['product_id', 'stock_before', 'stock_after'])
            ->groupBy('product_id')
            ->map(fn (Collection $movements): int => $movements->reduce(
                fn (int $total, object $movement): int => $total
                    + FixedPoint::parse((string) $movement->stock_after, FixedPoint::STOCK_SCALE)
                    - FixedPoint::parse((string) $movement->stock_before, FixedPoint::STOCK_SCALE),
                0,
            ));

        return DB::table('products')
            ->where('is_active', true)
            ->where('created_at', '<=', $to->endOfDay())
            ->get(['id', 'current_stock', 'cost_price'])
            ->reduce(function (int $total, object $product) use ($changesAfter): int {
                $currentStock = FixedPoint::parse((string) $product->current_stock, FixedPoint::STOCK_SCALE);
                $stockAtDate = max(0, $currentStock - (int) $changesAfter->get($product->id, 0));

                return $total + FixedPoint::multiply(
                    (int) ($product->cost_price ?? 0),
                    $stockAtDate,
                    FixedPoint::STOCK_SCALE,
                );
            }, 0);
    }

    /**
     * Arus aktual per rekening/metode: dana yang masuk saat pembayaran dan
     * dana yang keluar saat refund. Metode aktif tetap ditampilkan meski belum
     * ada transaksi agar nama rekening yang baru diatur langsung terlihat.
     */
    private function paymentFlows(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $inflows = DB::table('transaction_payments as payment')
            ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
            ->where('transaction.status', 'paid')
            ->where('payment.status', 'confirmed')
            ->whereBetween('payment.paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->select('payment.payment_method_id', DB::raw('SUM(payment.amount) as total'))
            ->groupBy('payment.payment_method_id')
            ->pluck('total', 'payment.payment_method_id');
        $outflows = DB::table('sales_returns')
            ->where('status', 'posted')
            ->whereBetween('returned_at', [$from->startOfDay(), $to->endOfDay()])
            ->select('refund_payment_method_id', DB::raw('SUM(total_amount) as total'))
            ->groupBy('refund_payment_method_id')
            ->pluck('total', 'refund_payment_method_id');
        $usedMethodIds = $inflows->keys()->merge($outflows->keys())->map(fn ($id): int => (int) $id)->unique()->values();
        $methods = DB::table('payment_methods')
            ->where(function ($query) use ($usedMethodIds): void {
                $query->where('is_active', true);
                if ($usedMethodIds->isNotEmpty()) {
                    $query->orWhereIn('id', $usedMethodIds->all());
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'type',
                'is_cash',
                'is_active',
                'account_name',
                'account_number',
            ]);

        return $methods->map(fn (object $method): array => [
            'key' => 'method-'.$method->id,
            'id' => (int) $method->id,
            'name' => $method->name,
            'type' => $method->type,
            'is_cash' => (bool) $method->is_cash,
            'is_active' => (bool) $method->is_active,
            'account_name' => $method->account_name,
            'account_number' => $method->account_number,
            'inflow' => (int) $inflows->get($method->id, 0),
            'outflow' => (int) $outflows->get($method->id, 0),
            'net' => (int) $inflows->get($method->id, 0) - (int) $outflows->get($method->id, 0),
            // Alias yang mempertahankan kontrak data dashboard sebelumnya.
            'total' => (int) $inflows->get($method->id, 0) - (int) $outflows->get($method->id, 0),
        ]);
    }

    private function netRevenueForDate(CarbonImmutable $date): int
    {
        $sales = (int) DB::table('transactions')
            ->where('status', 'paid')
            ->whereDate('transacted_at', $date)
            ->sum('total');
        $refunds = (int) DB::table('sales_returns')
            ->where('status', 'posted')
            ->whereDate('returned_at', $date)
            ->sum('total_amount');

        return $sales - $refunds;
    }

    private function netRevenueByDay(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $sales = DB::table('transactions')
            ->where('status', 'paid')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->endOfDay()])
            ->selectRaw('DATE(transacted_at) as date, SUM(total) as total')
            ->groupByRaw('DATE(transacted_at)')
            ->pluck('total', 'date');
        $refunds = DB::table('sales_returns')
            ->where('status', 'posted')
            ->whereBetween('returned_at', [$start->startOfDay(), $end->endOfDay()])
            ->selectRaw('DATE(returned_at) as date, SUM(total_amount) as total')
            ->groupByRaw('DATE(returned_at)')
            ->pluck('total', 'date');

        return $sales->keys()
            ->merge($refunds->keys())
            ->unique()
            ->mapWithKeys(fn (string $date): array => [
                $date => (int) $sales->get($date, 0) - (int) $refunds->get($date, 0),
            ]);
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
