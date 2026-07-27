<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyTaskEvidence extends Model
{
    protected $table = 'weekly_task_evidence';

    protected $fillable = ['weekly_task_occurrence_id', 'disk', 'path', 'mime_type', 'size_bytes'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(WeeklyTaskOccurrence::class, 'weekly_task_occurrence_id');
    }
}
