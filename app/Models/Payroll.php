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
        'bonus',
        'overtime',
        'commission',
        'late_deduction',
        'other_deduction',
        'net_salary',
        'late_duration_minutes',
        'status',
        'finalized_by',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'integer',
            'bonus' => 'integer',
            'overtime' => 'integer',
            'commission' => 'integer',
            'late_deduction' => 'integer',
            'other_deduction' => 'integer',
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
