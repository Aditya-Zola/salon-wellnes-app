<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'reservation_item_id',
        'item_type',
        'item_id',
        'name',
        'quantity',
        'unit_price',
        'gross_amount',
        'discount_percent',
        'discount_amount',
        'total_amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'integer',
            'gross_amount' => 'integer',
            'discount_percent' => 'decimal:4',
            'discount_amount' => 'integer',
            'total_amount' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function reservationItem(): BelongsTo
    {
        return $this->belongsTo(ReservationItem::class);
    }
}
