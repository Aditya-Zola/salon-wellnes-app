<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->boolean('override_conflict')
            || (bool) $this->user()?->can('reservations.override_conflict');
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (! is_array($items) && $this->filled('treatment_id')) {
            $items = [[
                'treatment_id' => $this->input('treatment_id'),
                'start_time' => $this->input('start_time', $this->input('time')),
                'actual_price' => $this->input('actual_price'),
                'notes' => $this->input('item_notes'),
                'staff' => [[
                    'employee_id' => $this->input('employee_id', $this->input('therapist_id')),
                    'role' => 'primary',
                ]],
            ]];
        }

        if (is_array($items)) {
            foreach ($items as $itemIndex => $item) {
                if (! is_array($item) || ! isset($item['staff']) || ! is_array($item['staff'])) {
                    continue;
                }

                foreach ($item['staff'] as $staffIndex => $staff) {
                    if (! is_array($staff)) {
                        continue;
                    }

                    $role = $staff['role'] ?? ($staffIndex === 0 ? 'primary' : 'assistant');
                    $items[$itemIndex]['staff'][$staffIndex]['role'] = is_string($role)
                        ? strtolower(trim($role))
                        : $role;
                }
            }
        }

        $name = $this->input('name');
        $phone = $this->input('phone');
        $source = $this->input('source', 'walk_in');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'phone' => is_string($phone) ? trim($phone) : $phone,
            'source' => is_string($source) ? strtolower(trim($source)) : $source,
            'items' => $items,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'date' => ['required', 'date_format:Y-m-d'],
            'source' => ['required', Rule::in(['walk_in', 'whatsapp', 'phone', 'other'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.treatment_id' => ['required', 'integer', 'exists:treatments,id'],
            'items.*.start_time' => ['required', 'date_format:H:i'],
            'items.*.actual_price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'items.*.staff' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.staff.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'items.*.staff.*.role' => ['required', Rule::in(['primary', 'assistant'])],
            'override_conflict' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'required_if:override_conflict,true', 'string', 'max:500'],
        ];
    }
}
