<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyTaskPostponement extends Model
{
    protected $fillable = ['weekly_task_occurrence_id', 'from_date', 'to_date', 'reason'];

    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date',
            'to_date' => 'immutable_date',
        ];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(WeeklyTaskOccurrence::class, 'weekly_task_occurrence_id');
    }
}
