<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cashier.refund') ?? false;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.transaction_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'items.*.restock' => ['required', 'boolean'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Pilih minimal satu produk yang akan diretur.',
            'items.min' => 'Pilih minimal satu produk yang akan diretur.',
            'items.*.quantity.gt' => 'Jumlah retur harus lebih dari nol.',
            'reason.min' => 'Alasan retur minimal 5 karakter.',
        ];
    }
}
