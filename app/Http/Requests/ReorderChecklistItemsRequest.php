<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderChecklistItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'task_session_id' => ['required', 'integer', 'exists:task_sessions,id'],
            'items' => ['required', 'array'],
            'items.*.type' => ['required', 'string', 'in:daily,weekly'],
            'items.*.id' => ['required', 'integer'],
        ];
    }
}
