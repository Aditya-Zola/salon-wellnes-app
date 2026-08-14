<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'account_name',
        'account_number',
        'type',
        'is_cash',
        'requires_reference',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_cash' => 'boolean',
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function transactionPayments(): HasMany
    {
        return $this->hasMany(TransactionPayment::class);
    }
}
