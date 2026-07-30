<?php

namespace App\Services;

use App\Models\DailyChecklist;
use App\Models\DailyTaskEvidence;
use App\Models\TaskReopenAudit;
use App\Models\WeeklyTaskEvidence;
use App\Models\WeeklyTaskOccurrence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskReopenService
{
    private const ADMIN_LABEL = 'Master admin';

    public function __construct(private readonly OperationalDate $dates) {}

    public function reopenDaily(DailyChecklist $task, string $reason): void
    {
        $reason = trim($reason);

        DB::transaction(function () use ($task, $reason): void {
            $locked = DailyChecklist::query()->lockForUpdate()->findOrFail($task->id);
            $today = $this->dates->today();

            if (! $locked->is_completed) {
                throw ValidationException::withMessages(['task' => 'Only completed tasks can be reopened.']);
            }

            if (! $locked->date->isSameDay($today)) {
                throw ValidationException::withMessages(['task' => 'A daily task can only be reopened on its checklist date.']);
            }

            $now = $this->dates->nowUtc();
            $evidenceCount = $this->invalidateDailyEvidence($locked, $reason, $now);
            $this->writeAudit(
                taskType: 'daily',
                taskId: $locked->id,
                taskName: $locked->task_name,
                sessionName: $locked->session_name,
                taskDate: $locked->date->toDateString(),
                completedAt: $locked->completed_at,
                completionNote: $locked->completion_note,
                evidenceCount: $evidenceCount,
                reason: $reason,
                occurredAt: $now,
            );

            $locked->forceFill([
                'is_completed' => false,
                'completed_at' => null,
                'completed_by_user_id' => null,
                'completion_note' => null,
            ])->save();
        }, 3);
    }

    public function reopenWeekly(WeeklyTaskOccurrence $task, string $reason): void
    {
        $reason = trim($reason);

        DB::transaction(function () use ($task, $reason): void {
            $locked = WeeklyTaskOccurrence::query()->lockForUpdate()->findOrFail($task->id);
            $today = $this->dates->today();

            if ($locked->status !== 'completed') {
                throw ValidationException::withMessages(['task' => 'Only completed tasks can be reopened.']);
            }

            if (! $locked->week_start->isSameDay($today->startOfWeek())) {
                throw ValidationException::withMessages(['task' => 'A weekly task can only be reopened during its current week.']);
            }

            $now = $this->dates->nowUtc();
            $evidenceCount = $this->invalidateWeeklyEvidence($locked, $reason, $now);
            $this->writeAudit(
                taskType: 'weekly',
                taskId: $locked->id,
                taskName: $locked->task_name,
                sessionName: $locked->session_name,
                taskDate: ($locked->completed_on ?? $today)->toDateString(),
                completedAt: $locked->completed_at,
                completionNote: $locked->completion_note,
                evidenceCount: $evidenceCount,
                reason: $reason,
                occurredAt: $now,
            );

            $locked->forceFill([
                'status' => 'pending',
                'missed_reason' => null,
                'completed_at' => null,
                'completed_on' => null,
                'completion_note' => null,
            ])->save();
        }, 3);
    }

    private function invalidateDailyEvidence(DailyChecklist $task, string $reason, $now): int
    {
        $query = DailyTaskEvidence::query()
            ->where('daily_checklist_id', $task->id)
            ->whereNull('invalidated_at');
        $count = $query->count();

        $query->update([
            'invalidated_at' => $now,
            'invalidated_by' => self::ADMIN_LABEL,
            'invalidation_reason' => $reason,
        ]);

        return $count;
    }

    private function invalidateWeeklyEvidence(WeeklyTaskOccurrence $task, string $reason, $now): int
    {
        $query = WeeklyTaskEvidence::query()
            ->where('weekly_task_occurrence_id', $task->id)
            ->whereNull('invalidated_at');
        $count = $query->count();

        $query->update([
            'invalidated_at' => $now,
            'invalidated_by' => self::ADMIN_LABEL,
            'invalidation_reason' => $reason,
        ]);

        return $count;
    }

    private function writeAudit(
        string $taskType,
        int $taskId,
        string $taskName,
        string $sessionName,
        string $taskDate,
        $completedAt,
        ?string $completionNote,
        int $evidenceCount,
        string $reason,
        $occurredAt,
    ): void {
        TaskReopenAudit::query()->create([
            'task_type' => $taskType,
            'task_id' => $taskId,
            'task_name' => $taskName,
            'session_name' => $sessionName,
            'task_date' => $taskDate,
            'previous_completed_at' => $completedAt,
            'completion_note' => $completionNote,
            'invalidated_evidence_count' => $evidenceCount,
            'reason' => $reason,
            'performed_by' => self::ADMIN_LABEL,
            'occurred_at' => $occurredAt,
        ]);
    }
}
