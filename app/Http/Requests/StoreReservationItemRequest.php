<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReservationItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $staff = $this->input('staff');

        if (is_array($staff)) {
            foreach ($staff as $index => $assignment) {
                if (! is_array($assignment)) {
                    continue;
                }

                $role = $assignment['role'] ?? ($index === 0 ? 'primary' : 'assistant');
                $staff[$index]['role'] = is_string($role) ? strtolower(trim($role)) : $role;
            }
        }

        $this->merge([
            'staff' => $staff,
            'notes' => is_string($this->input('notes')) ? trim($this->input('notes')) : $this->input('notes'),
        ]);
    }

    public function rules(): array
    {
        return [
            'treatment_id' => ['required', 'integer', 'exists:treatments,id'],
            'start_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'staff' => ['required', 'array', 'min:1', 'max:10'],
            'staff.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'staff.*.role' => ['required', Rule::in(['primary', 'assistant'])],
        ];
    }
}
