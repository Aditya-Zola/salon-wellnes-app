<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'discount_type',
        'discount_percent',
        'discount_amount',
        'starts_at',
        'ends_at',
        'members_only',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:4',
            'discount_amount' => 'integer',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'members_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
