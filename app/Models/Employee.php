<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code',
        'name',
        'position',
        'specialty',
        'is_service_provider',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'is_service_provider' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reservationItemAssignments(): HasMany
    {
        return $this->hasMany(ReservationItemStaff::class);
    }

    public function reservationItems(): BelongsToMany
    {
        return $this->belongsToMany(ReservationItem::class, 'reservation_item_staff')
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

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }
}
