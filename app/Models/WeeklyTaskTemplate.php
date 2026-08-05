<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyTaskTemplate extends Model
{
    protected $fillable = [
        'task_name',
        'task_session_id',
        'task_collection_id',
        'applies_to_all_collections',
        'due_weekday',
        'credit_hours',
        'sort_order',
        'starts_on',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'task_collection_id' => 'integer',
            'applies_to_all_collections' => 'boolean',
            'due_weekday' => 'integer',
            'credit_hours' => 'decimal:2',
            'sort_order' => 'integer',
            'starts_on' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function taskSession(): BelongsTo
    {
        return $this->belongsTo(TaskSession::class);
    }

    public function taskCollection(): BelongsTo
    {
        return $this->belongsTo(TaskCollection::class);
    }

    public function taskCollections(): BelongsToMany
    {
        return $this->belongsToMany(TaskCollection::class, 'weekly_task_template_task_collection');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(WeeklyTaskOccurrence::class);
    }
}
