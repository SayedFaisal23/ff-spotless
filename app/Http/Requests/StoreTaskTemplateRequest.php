<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SanitizesPlainText;
use Illuminate\Foundation\Http\FormRequest;

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
            'credit_hours' => ['bail', 'required', 'numeric', 'min:0.25', 'max:24', 'decimal:0,2', 'multiple_of:0.25'],
        ];
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
            'credit_hours.required' => 'Credit hours are required.',
            'credit_hours.numeric' => 'Credit hours must be a number.',
            'credit_hours.min' => 'Credit hours must be at least 0.25.',
            'credit_hours.max' => 'Credit hours must not exceed 24.',
            'credit_hours.decimal' => 'Credit hours can have at most two decimal places.',
            'credit_hours.multiple_of' => 'Credit hours must be in 0.25 increments.',
        ];
    }
}
