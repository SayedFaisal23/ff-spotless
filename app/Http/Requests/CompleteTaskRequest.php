<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['bail', 'required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
            'photos' => ['bail', 'required', 'array', 'min:1'],
            'photos.*' => [
                'bail',
                'required',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Tarikh diperlukan.',
            'date.date_format' => 'Tarikh mesti menggunakan format YYYY-MM-DD.',
            'note.max' => 'Nota tugasan tidak boleh melebihi 500 aksara.',
            'photos.required' => 'Sekurang-kurangnya satu foto bukti diperlukan.',
            'photos.array' => 'Foto bukti tidak sah.',
            'photos.min' => 'Sekurang-kurangnya satu foto bukti diperlukan.',
            'photos.*.image' => 'Bukti mestilah fail imej yang sah.',
            'photos.*.mimetypes' => 'Bukti hanya boleh berupa JPEG, PNG atau WebP.',
            'photos.*.max' => 'Setiap foto bukti tidak boleh melebihi 10 MB.',
        ];
    }
}
