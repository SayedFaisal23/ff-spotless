<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SanitizesPlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskSessionRequest extends FormRequest
{
    use SanitizesPlainText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => $this->sanitizePlainText($this->input('name'))]);
    }

    public function rules(): array
    {
        $sessionId = $this->route('taskSession')?->getKey();

        return [
            'name' => ['bail', 'required', 'string', 'max:100', Rule::unique('task_sessions', 'name')->ignore($sessionId)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Session name is required.',
            'name.max' => 'Session name must not exceed 100 characters.',
            'name.unique' => 'Session name has already been used.',
        ];
    }
}
