<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'period',
        'employee_name',
        'position',
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
        'commission',
        'absence_days',
        'absence_deduction',
        'late_deduction',
        'late_rate_per_minute',
        'other_deduction',
        'cash_advance',
        'net_salary',
        'late_duration_minutes',
        'notes',
        'status',
        'finalized_by',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'integer',
            'paid_work_days' => 'decimal:2',
            'daily_rate' => 'integer',
            'bonus' => 'integer',
            'target_bonus' => 'integer',
            'service_bonus' => 'integer',
            'attendance_bonus' => 'integer',
            'overtime' => 'integer',
            'overtime_days' => 'decimal:2',
            'meal_allowance' => 'integer',
            'attendance_allowance' => 'integer',
            'other_allowance' => 'integer',
            'tip_deposit' => 'integer',
            'commission' => 'integer',
            'absence_days' => 'decimal:2',
            'absence_deduction' => 'integer',
            'late_deduction' => 'integer',
            'late_rate_per_minute' => 'integer',
            'other_deduction' => 'integer',
            'cash_advance' => 'integer',
            'net_salary' => 'integer',
            'late_duration_minutes' => 'integer',
            'finalized_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
