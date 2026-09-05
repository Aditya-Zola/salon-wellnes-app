<?php

namespace App\Http\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class RemunerationReportService
{
    /**
     * Presents the same source fields used by the salon remuneration workbook.
     * Operational records (commission, treatment and attendance notes) are read
     * from their original tables. Payroll components stay a deliberate, manual
     * input per employee and payroll period.
     */
    public function report(Authenticatable $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        abort_unless($user->can('payroll.view'), 403);

        $periodStart = $from->toDateString();
        $periodEnd = $to->toDateString();
        $payrollPeriod = $to->format('Y-m');
        $settings = DB::table('sale_settings')
            ->whereIn('key', ['remuneration_payday_day', 'remuneration_cutoff_day'])
            ->pluck('value', 'key');

        $employees = DB::table('employees')
            ->where('active', true)
            // Accounts used only to access the application are represented as
            // employees too. They are not remuneration recipients.
            ->where('code', 'not like', 'USR-%')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'position', 'is_service_provider']);

        $commissionDetails = DB::table('reservation_item_staff as assignment')
            ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
            ->join('reservation_items as item', 'item.id', '=', 'assignment.reservation_item_id')
            ->join('reservations as reservation', 'reservation.id', '=', 'item.reservation_id')
            ->join('transactions as transaction', 'transaction.reservation_id', '=', 'reservation.id')
            ->join('transaction_items as transactionItem', function ($join): void {
                $join->on('transactionItem.transaction_id', '=', 'transaction.id')
                    ->on('transactionItem.reservation_item_id', '=', 'item.id');
            })
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->where('transaction.status', 'paid')
            ->whereBetween('transaction.transacted_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('transaction.transacted_at')
            ->orderBy('employee.name')
            ->get([
                'employee.id as employee_id',
                'employee.name as employee_name',
                'transaction.transacted_at',
                'transaction.number as transaction_number',
                'customer.name as customer_name',
                'transactionItem.name as treatment_name',
                'transactionItem.quantity',
                'assignment.commission_amount',
            ])
            ->map(fn (object $item): array => [
                'employee_id' => (int) $item->employee_id,
                'employee_name' => $item->employee_name,
                'date' => CarbonImmutable::parse($item->transacted_at)->toDateString(),
                'transaction_number' => $item->transaction_number,
                'customer_name' => $item->customer_name,
                'treatment_name' => $item->treatment_name,
                'quantity' => (float) $item->quantity,
                'commission' => (int) $item->commission_amount,
            ])
            ->values();
        $commissionByEmployee = $commissionDetails
            ->groupBy('employee_id')
            ->map(fn ($items): array => [
                'commission' => (int) $items->sum('commission'),
                'treatment_count' => (float) $items->sum('quantity'),
            ]);

        $attendance = DB::table('employee_attendances as attendance')
            ->join('employees as employee', 'employee.id', '=', 'attendance.employee_id')
            ->whereBetween('attendance.attendance_date', [$periodStart, $periodEnd])
            ->orderBy('attendance.attendance_date')
            ->orderBy('employee.name')
            ->get([
                'employee.id as employee_id',
                'employee.name as employee_name',
                'attendance.attendance_date',
                'attendance.status',
                'attendance.overtime_amount',
                'attendance.notes',
            ])
            ->map(fn (object $item): array => [
                'employee_id' => (int) $item->employee_id,
                'employee_name' => $item->employee_name,
                'date' => $item->attendance_date,
                'status' => $item->status,
                'overtime_amount' => (int) $item->overtime_amount,
                'notes' => $item->notes,
            ])
            ->values();
        $offDaysByEmployee = $attendance
            ->where('status', 'off')
            ->groupBy('employee_id')
            ->map(fn ($items): int => $items->count());
        $overtimeByEmployee = $attendance
            ->where('status', 'overtime')
            ->groupBy('employee_id')
            ->map(fn ($items): array => [
                'amount' => (int) $items->sum('overtime_amount'),
                'days' => $items->count(),
            ]);

        $payrolls = DB::table('payrolls')
            ->where('period', $payrollPeriod)
            ->get([
                'id',
                'employee_id',
                'base_salary',
                'paid_work_days',
                'daily_rate',
                'bonus',
                'target_bonus',
                'service_bonus',
                'attendance_bonus',
                'overtime',
                'overtime_days',
                'meal_allowance',
                'attendance_allowance',
                'other_allowance',
                'tip_deposit',
                'late_duration_minutes',
                'late_rate_per_minute',
                'late_deduction',
                'absence_days',
                'absence_deduction',
                'cash_advance',
                'other_deduction',
                'net_salary',
                'notes',
                'status',
            ])
            ->keyBy('employee_id');

        $employeeRows = $employees->map(function (object $employee) use ($commissionByEmployee, $payrolls, $offDaysByEmployee, $overtimeByEmployee): array {
            $commission = $commissionByEmployee->get($employee->id, ['commission' => 0, 'treatment_count' => 0]);
            $payroll = $payrolls->get($employee->id);
            $values = $this->payrollValues($payroll, (int) $commission['commission']);
            $overtime = $overtimeByEmployee->get($employee->id, ['amount' => 0, 'days' => 0]);
            // Lembur harian dari kehadiran adalah sumber tunggal untuk KOM-LEM.
            // Jika sudah ada, ia menggantikan angka lembur bulanan lama agar tidak
            // dihitung dua kali.
            if ($overtime['amount'] > 0) {
                $difference = $overtime['amount'] - $values['overtime'];
                $values['overtime'] = $overtime['amount'];
                $values['overtime_days'] = $overtime['days'];
                $values['gross_income'] += $difference;
                $values['net_salary'] += $difference;
            }

            return [
                'payroll_id' => $payroll?->id ? (int) $payroll->id : null,
                'employee_id' => (int) $employee->id,
                'employee_code' => $employee->code,
                'employee_name' => $employee->name,
                'position' => $employee->position,
                'is_service_provider' => (bool) $employee->is_service_provider,
                'treatment_count' => (float) $commission['treatment_count'],
                'recorded_off_days' => (int) ($offDaysByEmployee->get($employee->id, 0)),
                ...$values,
                'has_payroll_input' => $payroll !== null,
                'status' => $payroll->status ?? 'draft',
            ];
        })->values();

        $stockMovements = DB::table('stock_movements as movement')
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
            ->whereBetween('movement.occurred_at', [$from->startOfDay(), $to->endOfDay()])
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
                'movement.source_id',
                'movement.reference',
                'movement.notes',
                'movement.occurred_at',
                'product.name as product_name',
                'product.purchase_to_usage_factor',
                'movementUnit.code as movement_unit',
                'purchaseUnit.code as purchase_unit',
                'usageUnit.code as usage_unit',
                'reservation.id as reservation_id',
                'customer.name as customer_name',
            ])
            ->map(fn (object $item): array => [
                'id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'date' => CarbonImmutable::parse($item->occurred_at)->toDateString(),
                'time' => CarbonImmutable::parse($item->occurred_at)->format('H:i'),
                'type' => $item->type,
                'quantity' => (float) $item->quantity,
                'stock_before' => (float) $item->stock_before,
                'stock_after' => (float) $item->stock_after,
                'source_type' => $item->source_type,
                'product_name' => $item->product_name,
                'purchase_to_usage_factor' => (float) $item->purchase_to_usage_factor,
                'movement_unit' => $item->movement_unit,
                'purchase_unit' => $item->purchase_unit,
                'usage_unit' => $item->usage_unit,
                'reference' => $item->reference,
                'notes' => $item->notes,
                'reservation_id' => $item->reservation_id ? (int) $item->reservation_id : null,
                'customer_name' => $item->customer_name,
            ])
            ->values();

        $reservationIds = $stockMovements->pluck('reservation_id')->filter()->unique()->values();
        $therapistsByReservation = $reservationIds->isEmpty()
            ? collect()
            : DB::table('reservation_item_staff as assignment')
                ->join('reservation_items as item', 'item.id', '=', 'assignment.reservation_item_id')
                ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
                ->whereIn('item.reservation_id', $reservationIds)
                ->orderBy('employee.name')
                ->get(['item.reservation_id', 'employee.name'])
                ->groupBy('reservation_id')
                ->map(fn ($items): string => $items->pluck('name')->unique()->join(', '));
        $stockMovements = $stockMovements->map(function (array $movement) use ($therapistsByReservation): array {
            $movement['therapists'] = $movement['reservation_id']
                ? ($therapistsByReservation->get($movement['reservation_id']) ?: null)
                : null;

            return $movement;
        })->values();
        $recipeDosesByProduct = $stockMovements->isEmpty()
            ? collect()
            : DB::table('treatment_product_recipes')
                ->whereIn('product_id', $stockMovements->pluck('product_id')->unique())
                ->get(['product_id', 'quantity'])
                ->groupBy('product_id');
        $stockTableRows = $stockMovements->values()->map(function (array $movement, int $index) use ($recipeDosesByProduct): array {
            $quantity = (float) $movement['quantity'];
            $stockBefore = (float) $movement['stock_before'];
            $stockAfter = (float) $movement['stock_after'];
            $incoming = $movement['type'] === 'in'
                || ($movement['type'] === 'adjustment' && $stockAfter >= $stockBefore);
            $outgoing = $movement['type'] === 'out'
                || ($movement['type'] === 'adjustment' && $stockAfter < $stockBefore);
            $doses = collect($recipeDosesByProduct->get($movement['product_id'], []))
                ->map(fn (object $recipe): float => (float) $recipe->quantity)
                ->unique(fn (float $dose): string => number_format($dose, 4, '.', ''))
                ->values();
            $dose = $outgoing && $movement['customer_name']
                ? $quantity
                : ($doses->count() === 1 ? (float) $doses->first() : null);
            $factor = max(0.0001, (float) $movement['purchase_to_usage_factor']);
            $capacity = $dose && $dose > 0 ? ($incoming ? $stockAfter : $stockBefore) / $dose : null;
            $customersServed = $outgoing && $dose && $dose > 0 ? $quantity / $dose : null;
            $remainingCapacity = $dose && $dose > 0 ? $stockAfter / $dose : null;

            return [
                'number' => $index + 1,
                'product' => mb_strtoupper($movement['product_name']),
                'incoming_date' => $incoming ? $movement['date'] : null,
                'incoming_quantity' => $incoming ? $quantity / $factor : null,
                'purchase_unit' => $incoming ? mb_strtoupper($movement['purchase_unit']) : null,
                'gross_quantity' => $incoming ? $factor : null,
                'gross_unit' => $incoming ? mb_strtoupper($movement['usage_unit']) : null,
                'dose' => $dose,
                'dose_unit' => $dose ? mb_strtoupper($movement['usage_unit']) : null,
                'capacity' => $capacity,
                'outgoing_date' => $outgoing ? $movement['date'] : null,
                'outgoing_time' => $outgoing ? $movement['time'] : null,
                'customers_served' => $customersServed,
                'outgoing_quantity' => $outgoing ? $quantity : null,
                'outgoing_unit' => $outgoing ? mb_strtoupper($movement['movement_unit']) : null,
                'remaining_capacity' => $remainingCapacity,
                'stock_after' => $stockAfter,
                'stock_unit' => mb_strtoupper($movement['movement_unit']),
                'customer' => $outgoing ? ($movement['customer_name'] ?: null) : null,
                'therapists' => $outgoing ? $movement['therapists'] : null,
            ];
        })->all();

        $sales = DB::table('transactions as transaction')
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->where('transaction.status', 'paid')
            ->whereBetween('transaction.transacted_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('transaction.transacted_at')
            ->get([
                'transaction.transacted_at',
                'transaction.number',
                'customer.name as customer_name',
                'transaction.total',
            ])
            ->map(fn (object $item): array => [
                'date' => CarbonImmutable::parse($item->transacted_at)->toDateString(),
                'type' => 'Penjualan',
                'number' => $item->number,
                'customer_name' => $item->customer_name,
                'amount' => (int) $item->total,
            ]);
        $refunds = DB::table('sales_returns as salesReturn')
            ->join('transactions as transaction', 'transaction.id', '=', 'salesReturn.transaction_id')
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->where('salesReturn.status', 'posted')
            ->whereBetween('salesReturn.returned_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('salesReturn.returned_at')
            ->get([
                'salesReturn.returned_at',
                'salesReturn.number',
                'customer.name as customer_name',
                'salesReturn.total_amount',
            ])
            ->map(fn (object $item): array => [
                'date' => CarbonImmutable::parse($item->returned_at)->toDateString(),
                'type' => 'Retur',
                'number' => $item->number,
                'customer_name' => $item->customer_name,
                'amount' => -((int) $item->total_amount),
            ]);
        $salesActivity = $sales->concat($refunds)->sortBy('date')->values();

        return [
            'period' => [
                'from' => $periodStart,
                'to' => $periodEnd,
                'payroll_period' => $payrollPeriod,
                'payday_day' => $this->daySetting($settings->get('remuneration_payday_day'), 1),
                'cutoff_day' => $this->daySetting($settings->get('remuneration_cutoff_day'), 31),
            ],
            'summary' => [
                'employee_count' => $employeeRows->count(),
                'payroll_input_count' => $employeeRows->where('has_payroll_input', true)->count(),
                'commission' => (int) $employeeRows->sum('commission'),
                'gross_income' => (int) $employeeRows->sum('gross_income'),
                'deductions' => (int) $employeeRows->sum('total_deduction'),
                'net_income' => (int) $employeeRows->sum('net_salary'),
                'late_minutes' => (int) $employeeRows->sum('late_minutes'),
                'revenue' => (int) $salesActivity->sum('amount'),
                'stock_in' => (float) $stockMovements->where('type', 'in')->sum('quantity'),
                'stock_out' => (float) $stockMovements->where('type', 'out')->sum('quantity'),
                'stock_movement_count' => $stockMovements->count(),
            ],
            'employees' => $employeeRows->all(),
            'commission_details' => $commissionDetails->all(),
            'attendance' => $attendance->all(),
            'stock_movements' => $stockMovements->all(),
            'stock_table_rows' => $stockTableRows,
            'sales' => $salesActivity->all(),
        ];
    }

    /** @return array<string, int|float|string|null> */
    private function payrollValues(?object $payroll, int $commission): array
    {
        $value = fn (string $field): int => (int) ($payroll->{$field} ?? 0);
        $decimal = fn (string $field): float => (float) ($payroll->{$field} ?? 0);
        $baseSalary = $value('base_salary');
        $bonus = $value('bonus');
        $targetBonus = $value('target_bonus');
        $serviceBonus = $value('service_bonus');
        $attendanceBonus = $value('attendance_bonus');
        $overtime = $value('overtime');
        $mealAllowance = $value('meal_allowance');
        $attendanceAllowance = $value('attendance_allowance');
        $otherAllowance = $value('other_allowance');
        $tipDeposit = $value('tip_deposit');
        $absenceDeduction = $value('absence_deduction');
        $lateDeduction = $value('late_deduction');
        $cashAdvance = $value('cash_advance');
        $otherDeduction = $value('other_deduction');
        $grossIncome = $baseSalary + $commission + $bonus + $targetBonus + $serviceBonus + $attendanceBonus
            + $overtime + $mealAllowance + $attendanceAllowance + $otherAllowance + $tipDeposit;
        $totalDeduction = $absenceDeduction + $lateDeduction + $cashAdvance + $otherDeduction;

        return [
            'base_salary' => $baseSalary,
            'paid_work_days' => $decimal('paid_work_days'),
            'daily_rate' => $value('daily_rate'),
            'commission' => $commission,
            'bonus' => $bonus,
            'target_bonus' => $targetBonus,
            'service_bonus' => $serviceBonus,
            'attendance_bonus' => $attendanceBonus,
            'total_bonus' => $bonus + $targetBonus + $serviceBonus + $attendanceBonus,
            'overtime' => $overtime,
            'overtime_days' => $decimal('overtime_days'),
            'meal_allowance' => $mealAllowance,
            'attendance_allowance' => $attendanceAllowance,
            'other_allowance' => $otherAllowance,
            'total_allowance' => $mealAllowance + $attendanceAllowance + $otherAllowance,
            'tip_deposit' => $tipDeposit,
            'absence_days' => $decimal('absence_days'),
            'absence_deduction' => $absenceDeduction,
            'late_minutes' => $value('late_duration_minutes'),
            'late_rate_per_minute' => $value('late_rate_per_minute'),
            'late_deduction' => $lateDeduction,
            'cash_advance' => $cashAdvance,
            'other_deduction' => $otherDeduction,
            'gross_income' => $grossIncome,
            'total_deduction' => $totalDeduction,
            'net_salary' => $grossIncome - $totalDeduction,
            'notes' => $payroll->notes ?? null,
        ];
    }

    private function daySetting(?string $value, int $default): int
    {
        $day = (int) $value;

        return $day >= 1 && $day <= 31 ? $day : $default;
    }
}
