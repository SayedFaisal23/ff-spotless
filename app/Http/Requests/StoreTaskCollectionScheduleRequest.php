<?php

namespace App\Http\Requests;

use App\Models\TaskCollectionSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTaskCollectionScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_collection_id' => ['bail', 'required', 'integer', 'exists:task_collections,id'],
            'starts_on' => ['bail', 'required', 'date_format:Y-m-d'],
            'ends_on' => ['bail', 'required', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $startsOn = (string) $this->input('starts_on');
            $endsOn = (string) $this->input('ends_on');

            if ($endsOn < $startsOn) {
                $validator->errors()->add('ends_on', 'The end date must be on or after the start date.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $overlaps = TaskCollectionSchedule::query()
                ->whereDate('starts_on', '<=', $endsOn)
                ->whereDate('ends_on', '>=', $startsOn)
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('starts_on', 'This date range overlaps an existing collection schedule.');
            }
        });
    }
}
