<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category',
        'purchase_unit_id',
        'usage_unit_id',
        'purchase_to_usage_factor',
        'current_stock',
        'minimum_stock',
        'selling_price',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'purchase_to_usage_factor' => 'decimal:4',
            'current_stock' => 'decimal:4',
            'minimum_stock' => 'decimal:4',
            'selling_price' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function usageUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'usage_unit_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(TreatmentProductRecipe::class);
    }

    public function treatments(): BelongsToMany
    {
        return $this->belongsToMany(Treatment::class, 'treatment_product_recipes')
            ->withPivot(['id', 'unit_id', 'quantity'])
            ->withTimestamps();
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
