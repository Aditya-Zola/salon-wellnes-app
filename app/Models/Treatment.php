<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Treatment extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'code',
        'name',
        'duration_minutes',
        'normal_price',
        'default_commission_percent',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'normal_price' => 'integer',
            'default_commission_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TreatmentCategory::class, 'category_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(TreatmentProductRecipe::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'treatment_product_recipes')
            ->withPivot(['id', 'unit_id', 'quantity'])
            ->withTimestamps();
    }

    public function reservationItems(): HasMany
    {
        return $this->hasMany(ReservationItem::class);
    }
}
