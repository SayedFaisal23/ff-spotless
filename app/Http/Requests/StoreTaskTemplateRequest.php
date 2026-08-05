<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SanitizesPlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTaskTemplateRequest extends FormRequest
{
    use SanitizesPlainText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'task_name' => $this->sanitizePlainText($this->input('task_name')),
            'applies_to_all_collections' => $this->boolean('applies_to_all_collections'),
            'task_collection_ids' => array_values(array_filter((array) $this->input('task_collection_ids', []), static fn ($value) => $value !== null && $value !== '')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'task_name' => ['bail', 'required', 'string', 'max:255'],
            'task_session_id' => ['bail', 'required', 'integer', 'exists:task_sessions,id'],
            'applies_to_all_collections' => ['bail', 'required', 'boolean'],
            'task_collection_ids' => ['bail', 'array'],
            'task_collection_ids.*' => ['bail', 'integer', 'distinct', 'exists:task_collections,id'],
            'credit_hours' => ['bail', 'required', 'numeric', 'min:0.25', 'max:24', 'decimal:0,2', 'multiple_of:0.25'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('applies_to_all_collections')) {
                return;
            }

            if (count((array) $this->input('task_collection_ids', [])) === 0) {
                $validator->errors()->add('task_collection_ids', 'Choose at least one task collection, or select all collections.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'task_name.required' => 'Task name is required.',
            'task_name.string' => 'Task name must be text.',
            'task_name.max' => 'Task name must not exceed 255 characters.',
            'task_session_id.required' => 'Task session is required.',
            'task_session_id.integer' => 'Task session is invalid.',
            'task_session_id.exists' => 'Task session was not found.',
            'task_collection_ids.array' => 'Task collections are invalid.',
            'task_collection_ids.*.integer' => 'A task collection is invalid.',
            'task_collection_ids.*.exists' => 'A task collection was not found.',
            'credit_hours.required' => 'Credit hours are required.',
            'credit_hours.numeric' => 'Credit hours must be a number.',
            'credit_hours.min' => 'Credit hours must be at least 0.25.',
            'credit_hours.max' => 'Credit hours must not exceed 24.',
            'credit_hours.decimal' => 'Credit hours can have at most two decimal places.',
            'credit_hours.multiple_of' => 'Credit hours must be in 0.25 increments.',
        ];
    }
}
