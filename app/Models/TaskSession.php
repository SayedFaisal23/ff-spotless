<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskSession extends Model
{
    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function taskTemplates(): HasMany
    {
        return $this->hasMany(TaskTemplate::class);
    }

    public function weeklyTaskTemplates(): HasMany
    {
        return $this->hasMany(WeeklyTaskTemplate::class);
    }
}
