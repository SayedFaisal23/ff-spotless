<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SanitizesPlainText;
use Illuminate\Foundation\Http\FormRequest;

class StoreWeeklyTaskTemplateRequest extends FormRequest
{
    use SanitizesPlainText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['task_name' => $this->sanitizePlainText($this->input('task_name'))]);
    }

    public function rules(): array
    {
        return [
            'task_name' => ['bail', 'required', 'string', 'max:255'],
            'task_session_id' => ['bail', 'required', 'integer', 'exists:task_sessions,id'],
            'due_weekday' => ['bail', 'required', 'integer', 'between:1,7'],
            'credit_hours' => ['bail', 'required', 'numeric', 'min:0.25', 'max:24', 'decimal:0,2', 'multiple_of:0.25'],
        ];
    }

    public function messages(): array
    {
        return [
            'task_name.required' => 'Task name is required.',
            'task_name.max' => 'Task name must not exceed 255 characters.',
            'task_session_id.required' => 'Task session is required.',
            'task_session_id.exists' => 'Task session was not found.',
            'due_weekday.required' => 'Weekly due day is required.',
            'due_weekday.between' => 'Weekly due day is invalid.',
            'credit_hours.required' => 'Credit hours are required.',
            'credit_hours.min' => 'Credit hours must be at least 0.25.',
            'credit_hours.max' => 'Credit hours must not exceed 24.',
        ];
    }
}
