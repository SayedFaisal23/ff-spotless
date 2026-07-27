<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderTaskSessionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_ids' => ['required', 'array', 'min:1'],
            'session_ids.*' => ['required', 'integer', 'distinct', 'exists:task_sessions,id'],
        ];
    }
}
