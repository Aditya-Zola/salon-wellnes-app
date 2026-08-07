<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationItemStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $aliases = [
            'scheduled' => 'waiting',
            'continued' => 'continue',
            'completed' => 'finished',
        ];
        $input = $this->input('status');
        $status = is_string($input) ? strtolower(trim($input)) : $input;

        $this->merge(['status' => is_string($status) ? ($aliases[$status] ?? $status) : $status]);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['waiting', 'in_progress', 'continue', 'ready', 'finished', 'overtime', 'cancelled'])],
            'reason' => ['nullable', 'required_if:status,cancelled', 'string', 'max:500'],
        ];
    }
}
