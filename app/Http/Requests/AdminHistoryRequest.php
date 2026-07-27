<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\InteractsWithOperationalDate;
use Illuminate\Foundation\Http\FormRequest;

class AdminHistoryRequest extends FormRequest
{
    use InteractsWithOperationalDate;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'string', 'date_format:Y-m-d'],
            'stats_from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'stats_to' => ['nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:stats_from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.string' => 'Tarikh tidak sah.',
            'date.date_format' => 'Tarikh mesti menggunakan format YYYY-MM-DD.',
            'stats_from.date_format' => 'Tarikh mula statistik mesti menggunakan format YYYY-MM-DD.',
            'stats_to.date_format' => 'Tarikh akhir statistik mesti menggunakan format YYYY-MM-DD.',
            'stats_to.after_or_equal' => 'Tarikh akhir statistik mesti selepas tarikh mula.',
        ];
    }
}
