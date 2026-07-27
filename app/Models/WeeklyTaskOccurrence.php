<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyTaskOccurrence extends Model
{
    protected $fillable = [
        'week_start',
        'weekly_task_template_id',
        'task_session_id',
        'task_name',
        'session_name',
        'credit_hours',
        'original_due_date',
        'scheduled_date',
        'status',
        'missed_reason',
        'completed_at',
        'completed_on',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_date',
            'credit_hours' => 'decimal:2',
            'original_due_date' => 'immutable_date',
            'scheduled_date' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
            'completed_on' => 'immutable_date',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WeeklyTaskTemplate::class, 'weekly_task_template_id');
    }

    public function taskSession(): BelongsTo
    {
        return $this->belongsTo(TaskSession::class);
    }

    public function postponements(): HasMany
    {
        return $this->hasMany(WeeklyTaskPostponement::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(WeeklyTaskEvidence::class);
    }
}
