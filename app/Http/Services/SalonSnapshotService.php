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
                ->get(['id', 'code', 'name', 'type', 'is_cash', 'requires_reference', 'account_name', 'account_number']);
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
                ->where(function ($query): void {
                    $query->where('activity.action', 'like', '%.created')
                        ->orWhere('activity.action', 'like', '%.updated')
                        ->orWhere('activity.action', 'like', '%.deleted')
                        ->orWhere('activity.action', 'like', '%.activated')
                        ->orWhere('activity.action', 'like', '%.deactivated')
                        ->orWhere('activity.action', 'like', '%.adjusted')
                        ->orWhere('activity.action', 'like', '%added%')
                        ->orWhere('activity.action', 'like', '%removed%');
                })
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

        return $treatments->map(function (object $treatment) use ($recipesByTreatment): object {
            $treatment->recipes = ($recipesByTreatment->get($treatment->id) ?? collect())->values();

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
        $query = DB::table('transactions as transaction')
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->where('transaction.status', 'paid')
            ->when($search !== '', function ($builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder->where(function ($nested) use ($like): void {
                    $nested->where('transaction.number', 'like', $like)
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
                ->get(['payment.id', 'payment.transaction_id', 'payment.amount', 'payment.tendered_amount', 'payment.reference_number', 'payment.paid_at', 'method.id as payment_method_id', 'method.code as payment_method_code', 'method.name as payment_method_name', 'method.is_cash as payment_method_is_cash'])
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

    private function cashEntries(): mixed
    {
        $manual = DB::table('cash_entries as entry')
            ->leftJoin('users as creator', 'creator.id', '=', 'entry.created_by')
            ->where('entry.status', 'posted')
            ->whereNull('entry.transaction_payment_id')
            ->orderByDesc('entry.entry_date')
            ->orderByDesc('entry.id')
            ->limit(100)
            ->get([
                'entry.id',
                'entry.transaction_payment_id',
                'entry.type',
                'entry.category',
                'entry.description',
                'entry.amount',
                'entry.entry_date',
                'entry.created_at',
                'creator.name as created_by_name',
            ]);
        $refunds = DB::table('sales_returns as sales_return')
            ->join('transactions as transaction', 'transaction.id', '=', 'sales_return.transaction_id')
            ->join('payment_methods as method', 'method.id', '=', 'sales_return.refund_payment_method_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'sales_return.created_by')
            ->where('sales_return.status', 'posted')
            ->where('method.is_cash', true)
            ->latest('sales_return.returned_at')
            ->limit(100)
            ->get([
                'sales_return.id',
                'sales_return.number',
                'sales_return.reason',
                'sales_return.total_amount',
                'sales_return.returned_at',
                'transaction.number as transaction_number',
                'creator.name as created_by_name',
            ])
            ->map(fn (object $refund): object => (object) [
                'id' => 'return-'.$refund->id,
                'transaction_payment_id' => null,
                'type' => 'expense',
                'category' => 'Retur penjualan',
                'description' => "{$refund->number} · {$refund->reason}",
                'amount' => (int) $refund->total_amount,
                'entry_date' => substr((string) $refund->returned_at, 0, 10),
                'created_at' => $refund->returned_at,
                'created_by_name' => $refund->created_by_name,
                'transaction_number' => $refund->transaction_number,
                'automated' => true,
            ]);

        return $manual
            ->map(function (object $entry): object {
                $entry->automated = false;

                return $entry;
            })
            ->concat($refunds)
            ->sortByDesc('created_at')
            ->take(100)
            ->values();
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
            $todayRevenue = $this->netRevenueForDate($today);
            $data['revenue_today'] = $todayRevenue;
            $data['revenue_yesterday'] = $this->netRevenueForDate($today->subDay());
            $paymentRevenueTotals = DB::table('transaction_payments as payment')
                ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
                ->where('transaction.status', 'paid')
                ->where('payment.status', 'confirmed')
                ->whereDate('payment.paid_at', $today)
                ->select('payment.payment_method_id', DB::raw('SUM(payment.amount) as total'))
                ->groupBy('payment.payment_method_id')
                ->get()
                ->mapWithKeys(fn (object $payment): array => [(int) $payment->payment_method_id => (int) $payment->total]);
            $paymentRefundTotals = DB::table('sales_returns')
                ->where('status', 'posted')
                ->whereDate('returned_at', $today)
                ->select('refund_payment_method_id', DB::raw('SUM(total_amount) as total'))
                ->groupBy('refund_payment_method_id')
                ->get()
                ->mapWithKeys(fn (object $refund): array => [(int) $refund->refund_payment_method_id => (int) $refund->total]);
            $methods = DB::table('payment_methods')
                ->where(function ($query) use ($paymentRevenueTotals, $paymentRefundTotals): void {
                    $query->where('is_active', true);
                    $usedMethodIds = $paymentRevenueTotals->keys()->concat($paymentRefundTotals->keys())->unique()->values();
                    if ($usedMethodIds->isNotEmpty()) {
                        $query->orWhereIn('id', $usedMethodIds->all());
                    }
                })
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'is_cash', 'is_active']);
            $data['revenue_by_payment_method_today'] = collect([
                ['key' => 'total', 'name' => 'Total pendapatan', 'total' => $todayRevenue, 'type' => 'total'],
            ])->concat($methods->map(fn (object $method): array => [
                'key' => 'method-'.$method->id,
                'id' => (int) $method->id,
                'name' => $method->name,
                'type' => $method->type,
                'total' => (int) $paymentRevenueTotals->get((int) $method->id, 0) - (int) $paymentRefundTotals->get((int) $method->id, 0),
                'is_active' => (bool) $method->is_active,
            ]))->values()->all();
            $data['revenue_last_7_days'] = collect(range(0, 6))->map(function (int $offset) use ($start): array {
                $date = $start->addDays($offset);
                $dayNames = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

                return [
                    'date' => $date->toDateString(),
                    'label' => $dayNames[$date->dayOfWeekIso],
                    'total' => $this->netRevenueForDate($date),
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
            $monthStart = $today->startOfMonth();
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
            $cashSales = (int) DB::table('transaction_payments as payment')
                ->join('payment_methods as method', 'method.id', '=', 'payment.payment_method_id')
                ->where('payment.status', 'confirmed')
                ->where('method.is_cash', true)
                ->whereBetween('payment.paid_at', [$monthStart->startOfDay(), $today->endOfDay()])
                ->sum('payment.amount');
            $cashRefunds = (int) DB::table('sales_returns as sales_return')
                ->join('payment_methods as method', 'method.id', '=', 'sales_return.refund_payment_method_id')
                ->where('sales_return.status', 'posted')
                ->where('method.is_cash', true)
                ->whereBetween('sales_return.returned_at', [$monthStart->startOfDay(), $today->endOfDay()])
                ->sum('sales_return.total_amount');
            if ($cashRefunds > 0) {
                array_unshift($expenseCategories, ['category' => 'Retur penjualan', 'total' => $cashRefunds]);
                $expenseCategories = array_slice($expenseCategories, 0, 5);
            }
            $data += [
                'month_income' => $income + $cashSales,
                'month_expense' => $expense + $cashRefunds,
                'month_balance' => $income + $cashSales - $expense - $cashRefunds,
                'month_cash_entry_count' => $count + (int) DB::table('sales_returns as sales_return')
                    ->join('payment_methods as method', 'method.id', '=', 'sales_return.refund_payment_method_id')
                    ->where('sales_return.status', 'posted')
                    ->where('method.is_cash', true)
                    ->whereBetween('sales_return.returned_at', [$monthStart->startOfDay(), $today->endOfDay()])
                    ->count(),
                'month_expense_categories' => $expenseCategories,
            ];
        }

        return $data;
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

    private function can(Authenticatable $user, string $permission): bool
    {
        return method_exists($user, 'can') && $user->can($permission);
    }

    private function canAny(Authenticatable $user, array $permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission): bool => $this->can($user, $permission));
    }
}
