<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'decimal_places',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function productsPurchased(): HasMany
    {
        return $this->hasMany(Product::class, 'purchase_unit_id');
    }

    public function productsUsed(): HasMany
    {
        return $this->hasMany(Product::class, 'usage_unit_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(TreatmentProductRecipe::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
