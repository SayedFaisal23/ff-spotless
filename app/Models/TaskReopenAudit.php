<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskReopenAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_type',
        'task_id',
        'task_name',
        'session_name',
        'task_date',
        'previous_completed_at',
        'completion_note',
        'invalidated_evidence_count',
        'reason',
        'performed_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'task_date' => 'immutable_date',
            'previous_completed_at' => 'immutable_datetime',
            'invalidated_evidence_count' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
