<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyTaskEvidence extends Model
{
    protected $table = 'daily_task_evidence';

    protected $fillable = ['daily_checklist_id', 'disk', 'path', 'mime_type', 'size_bytes'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(DailyChecklist::class, 'daily_checklist_id');
    }
}
