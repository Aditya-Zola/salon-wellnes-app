<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_payment_id',
        'type',
        'category',
        'description',
        'amount',
        'entry_date',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'entry_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function transactionPayment(): BelongsTo
    {
        return $this->belongsTo(TransactionPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
