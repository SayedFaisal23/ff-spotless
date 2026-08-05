<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCollectionSchedule extends Model
{
    protected $fillable = [
        'task_collection_id',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'task_collection_id' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    public function taskCollection(): BelongsTo
    {
        return $this->belongsTo(TaskCollection::class);
    }
}
