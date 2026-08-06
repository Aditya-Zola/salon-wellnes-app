<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $aliases = [
            'Terjadwal' => 'scheduled',
            'Sudah datang' => 'arrived',
            'Sedang dilayani' => 'in_service',
            'Selesai' => 'completed',
            'Batal' => 'cancelled',
        ];
        $input = $this->input('status');
        $status = is_string($input) ? trim($input) : $input;

        $this->merge(['status' => is_string($status) ? ($aliases[$status] ?? strtolower($status)) : $status]);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['scheduled', 'arrived', 'in_service', 'completed', 'cancelled'])],
            'reason' => ['nullable', 'required_if:status,cancelled', 'string', 'max:500'],
        ];
    }
}
