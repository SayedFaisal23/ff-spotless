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
            'task_name.required' => 'Nama tugasan diperlukan.',
            'task_name.max' => 'Nama tugasan tidak boleh melebihi 255 aksara.',
            'task_session_id.required' => 'Sesi tugasan diperlukan.',
            'task_session_id.exists' => 'Sesi tugasan tidak ditemui.',
            'due_weekday.required' => 'Hari tugasan mingguan diperlukan.',
            'due_weekday.between' => 'Hari tugasan mingguan tidak sah.',
            'credit_hours.required' => 'Jam kredit diperlukan.',
            'credit_hours.min' => 'Jam kredit mestilah sekurang-kurangnya 0.25.',
            'credit_hours.max' => 'Jam kredit tidak boleh melebihi 24.',
        ];
    }
}
