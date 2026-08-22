<?php

namespace App\Http\Requests;

use App\Http\Support\FixedPoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        return [
            'reservation_id' => ['required', 'integer', 'exists:reservations,id'],
            'promotion_id' => ['nullable', 'integer', 'exists:promotions,id'],
            'discount_percent' => ['nullable', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
            'manual_discount_percent' => ['nullable', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
            'product_items' => ['nullable', 'array', 'max:50'],
            'product_items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'product_items.*.quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'payments' => ['required_without:payment_method', 'array', 'min:1', 'max:10'],
            'payments.*.payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'payments.*.amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'payments.*.tendered_amount' => ['nullable', 'integer', 'min:1', 'max:999999999999'],
            'payments.*.reference_number' => ['nullable', 'string', 'max:100'],
            'payments.*.notes' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['required_without:payments', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['discount_percent', 'manual_discount_percent'] as $field) {
                if ($validator->errors()->has($field) || ! $this->filled($field)) {
                    continue;
                }

                $percent = FixedPoint::parse((string) $this->input($field), FixedPoint::PERCENT_SCALE);

                if ($percent > 100 * (10 ** FixedPoint::PERCENT_SCALE)) {
                    $validator->errors()->add($field, 'Persentase diskon tidak boleh lebih dari 100.');
                }
            }
        }];
    }
}
