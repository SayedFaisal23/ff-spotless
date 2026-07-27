<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyChecklist extends Model
{
    public $timestamps = false;

    /**
     * Preserve the precision provided by the timestamp(6) database column.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'task_template_id',
        'task_name',
        'task_session_id',
        'session_name',
        'credit_hours',
        'is_completed',
        'completed_at',
        'completed_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'task_session_id' => 'integer',
            'credit_hours' => 'decimal:2',
            'is_completed' => 'boolean',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TaskTemplate, $this>
     */
    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function taskSession(): BelongsTo
    {
        return $this->belongsTo(TaskSession::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DailyTaskEvidence::class);
    }
}
