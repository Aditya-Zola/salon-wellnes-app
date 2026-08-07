<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReservationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'treatment_id',
        'treatment_name',
        'duration_minutes',
        'normal_price',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'net_price',
        'commission_percent',
        'commission_amount',
        'scheduled_start_at',
        'scheduled_end_at',
        'started_at',
        'finished_at',
        'ready_at',
        'continued_at',
        'overtime_at',
        'cancelled_at',
        'work_status',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'normal_price' => 'integer',
            'unit_price' => 'integer',
            'discount_percent' => 'decimal:4',
            'discount_amount' => 'integer',
            'net_price' => 'integer',
            'commission_percent' => 'decimal:4',
            'commission_amount' => 'integer',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'ready_at' => 'datetime',
            'continued_at' => 'datetime',
            'overtime_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(ReservationItemStaff::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'reservation_item_staff')
            ->using(ReservationItemStaff::class)
            ->withPivot([
                'id',
                'role',
                'commission_percent',
                'commission_amount',
                'conflict_override_reason',
                'conflict_overridden_by',
                'conflict_overridden_at',
            ])
            ->withTimestamps();
    }

    public function transactionItem(): HasOne
    {
        return $this->hasOne(TransactionItem::class);
    }
}
