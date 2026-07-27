<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistItemPosition extends Model
{
    protected $fillable = ['date', 'task_session_id', 'item_type', 'item_id', 'position'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'task_session_id' => 'integer',
            'item_id' => 'integer',
            'position' => 'integer',
        ];
    }
}
