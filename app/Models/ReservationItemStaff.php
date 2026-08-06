<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ReservationItemStaff extends Pivot
{
    protected $table = 'reservation_item_staff';

    public $incrementing = true;

    protected $fillable = [
        'reservation_item_id',
        'employee_id',
        'role',
        'commission_percent',
        'commission_amount',
        'conflict_override_reason',
        'conflict_overridden_by',
        'conflict_overridden_at',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:4',
            'commission_amount' => 'integer',
            'conflict_overridden_at' => 'datetime',
        ];
    }

    public function reservationItem(): BelongsTo
    {
        return $this->belongsTo(ReservationItem::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function conflictOverrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conflict_overridden_by');
    }
}
