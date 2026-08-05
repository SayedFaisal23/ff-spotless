<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplate extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_name',
        'task_session_id',
        'task_collection_id',
        'applies_to_all_collections',
        'credit_hours',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task_session_id' => 'integer',
            'task_collection_id' => 'integer',
            'applies_to_all_collections' => 'boolean',
            'credit_hours' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<TaskTemplate>  $query
     * @return Builder<TaskTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return HasMany<DailyChecklist, $this>
     */
    public function dailyChecklists(): HasMany
    {
        return $this->hasMany(DailyChecklist::class);
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
        return $this->belongsToMany(TaskCollection::class, 'task_template_task_collection');
    }
}
