<?php

namespace App\Services;

use App\Models\ChecklistDayStatus;
use App\Models\ChecklistItemPosition;
use App\Models\DailyChecklist;
use App\Models\TaskSession;
use App\Models\TaskReopenAudit;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\WeeklyTaskOccurrence;
use App\Models\WeeklyTaskTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DashboardPresenter
{
    public function __construct(
        private readonly MasterAdminSession $adminSession,
        private readonly OperationalDate $dates,
    ) {}

    public function welcome(Request $request): array
    {
        return $this->base($request, 'welcome', $this->dates->today(), []);
    }

    /**
     * @param  array{daily: Collection<int, DailyChecklist>, weekly: Collection<int, WeeklyTaskOccurrence>}  $checklist
     */
    public function checklist(Request $request, CarbonImmutable $date, array $checklist): array
    {
        return $this->base($request, 'checklist', $date, $this->taskItems($date, $checklist));
    }

    /**
     * @param  Collection<int, TaskTemplate>  $templates
     * @param  Collection<int, WeeklyTaskTemplate>  $weeklyTemplates
     * @param  array{daily: Collection<int, DailyChecklist>, weekly: Collection<int, WeeklyTaskOccurrence>}  $checklist
     */
    public function admin(
        Request $request,
        CarbonImmutable $date,
        Collection $templates,
        Collection $weeklyTemplates,
        array $checklist,
        array $statistics,
    ): array {
        $props = $this->base($request, 'admin', $date, []);
        $props['templates'] = $templates->map(static fn (TaskTemplate $template): array => [
            'id' => $template->id,
            'taskName' => $template->task_name,
            'sessionId' => $template->task_session_id,
            'sessionName' => $template->taskSession->name,
            'creditHours' => (float) $template->credit_hours,
        ])->values()->all();
        $props['weeklyTemplates'] = $weeklyTemplates->map(static fn (WeeklyTaskTemplate $template): array => [
            'id' => $template->id,
            'taskName' => $template->task_name,
            'sessionId' => $template->task_session_id,
            'sessionName' => $template->taskSession->name,
            'dueWeekday' => $template->due_weekday,
            'creditHours' => (float) $template->credit_hours,
            'startsOn' => $template->starts_on->toDateString(),
        ])->values()->all();
        $props['completedTasks'] = $this->historyItems($date, $checklist);
        $props['overdueTasks'] = $this->overdueTasks();
        $props['reopenAudits'] = $this->reopenAudits();
        $props['statistics'] = $statistics;
        $props['workload'] = $this->workload($templates, $weeklyTemplates);

        return $props;
    }

    /**
     * @param  array{daily: Collection<int, DailyChecklist>, weekly: Collection<int, WeeklyTaskOccurrence>}  $checklist
     * @return list<array<string, mixed>>
     */
    private function taskItems(CarbonImmutable $date, array $checklist): array
    {
        $positions = ChecklistItemPosition::query()
            ->whereDate('date', $date->toDateString())
            ->get()
            ->keyBy(static fn (ChecklistItemPosition $position): string => $position->item_type.':'.$position->item_id);

        $daily = $checklist['daily']->map(function (DailyChecklist $task) use ($positions): array {
            $key = 'daily:'.$task->id;

            return [
                'key' => $key,
                'type' => 'daily',
                'id' => $task->id,
                'text' => $task->task_name,
                'sessionId' => $task->task_session_id,
                'sessionName' => $task->session_name,
                'creditHours' => (float) $task->credit_hours,
                'position' => $positions->get($key)?->position ?? 100000 + $task->id,
                'completed' => $task->is_completed,
                'isWeekly' => false,
                'evidenceCount' => $task->evidence_count ?? 0,
            ];
        });

        $weekly = $checklist['weekly']->map(function (WeeklyTaskOccurrence $task) use ($positions): array {
            $key = 'weekly:'.$task->id;

            return [
                'key' => $key,
                'type' => 'weekly',
                'id' => $task->id,
                'text' => $task->task_name,
                'sessionId' => $task->task_session_id,
                'sessionName' => $task->session_name,
                'creditHours' => (float) $task->credit_hours,
                'position' => $positions->get($key)?->position ?? 200000 + $task->id,
                'completed' => $task->status === 'completed',
                'isWeekly' => true,
                'status' => $task->status,
                'originalDueDate' => $task->original_due_date->toDateString(),
                'scheduledDate' => $task->scheduled_date->toDateString(),
                'postponedCount' => $task->postponements_count ?? 0,
                'evidenceCount' => $task->evidence_count ?? 0,
            ];
        });

        return $daily->concat($weekly)->sortBy('position')->values()->all();
    }

    /**
     * @param  array{daily: Collection<int, DailyChecklist>, weekly: Collection<int, WeeklyTaskOccurrence>}  $checklist
     * @return list<array<string, mixed>>
     */
    private function historyItems(CarbonImmutable $date, array $checklist): array
    {
        $daily = $checklist['daily']->loadMissing(['evidence', 'completedBy:id,name,username'])
            ->map(function (DailyChecklist $task): array {
                return [
                    'key' => 'daily:'.$task->id,
                    'type' => 'daily',
                    'id' => $task->id,
                    'date' => $task->date->toDateString(),
                    'text' => $task->task_name,
                    'sessionId' => $task->task_session_id,
                    'sessionName' => $task->session_name,
                    'creditHours' => (float) $task->credit_hours,
                    'status' => $task->is_completed ? 'completed' : ($task->date->lessThan($this->dates->today()) ? 'missed' : 'pending'),
                    'isCompleted' => $task->is_completed,
                    'completedAt' => $this->localTimestamp($task->completed_at),
                    'completionNote' => $task->completion_note,
                    'completedBy' => $task->completedBy?->only(['id', 'name', 'username']),
                    'canReopen' => $task->is_completed && $task->date->isSameDay($this->dates->today()),
                    'evidence' => $task->evidence->map(static fn ($evidence): array => [
                        'id' => $evidence->id,
                        'url' => route('admin.evidence.daily', $evidence),
                    ])->values()->all(),
                ];
            });

        $weekly = $checklist['weekly']->loadMissing(['evidence', 'postponements'])
            ->map(function (WeeklyTaskOccurrence $task) use ($date): array {
                return [
                    'key' => 'weekly:'.$task->id,
                    'type' => 'weekly',
                    'id' => $task->id,
                    'date' => $date->toDateString(),
                    'text' => $task->task_name,
                    'sessionId' => $task->task_session_id,
                    'sessionName' => $task->session_name,
                    'creditHours' => (float) $task->credit_hours,
                    'status' => $task->status,
                    'missedReason' => $task->missed_reason,
                    'isCompleted' => $task->status === 'completed',
                    'completedAt' => $this->localTimestamp($task->completed_at),
                    'completionNote' => $task->completion_note,
                    'originalDueDate' => $task->original_due_date->toDateString(),
                    'scheduledDate' => $task->scheduled_date->toDateString(),
                    'canReopen' => $task->status === 'completed'
                        && $task->week_start->isSameDay($this->dates->today()->startOfWeek()),
                    'postponements' => $task->postponements->map(static fn ($postponement): array => [
                        'from' => $postponement->from_date->toDateString(),
                        'to' => $postponement->to_date->toDateString(),
                        'reason' => $postponement->reason,
                    ])->values()->all(),
                    'evidence' => $task->evidence->map(static fn ($evidence): array => [
                        'id' => $evidence->id,
                        'url' => route('admin.evidence.weekly', $evidence),
                    ])->values()->all(),
                ];
            });

        return $daily->concat($weekly)->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function overdueTasks(): array
    {
        $today = $this->dates->today();
        $daily = DailyChecklist::query()
            ->whereDate('date', '<', $today->toDateString())
            ->where('is_completed', false)
            ->when(Schema::hasTable('checklist_day_statuses'), function ($query): void {
                $query->whereNotIn('date', ChecklistDayStatus::query()
                    ->where('is_unavailable', true)
                    ->select('date'));
            })
            ->orderBy('date')
            ->limit(50)
            ->get()
            ->map(static fn (DailyChecklist $task): array => [
                'key' => 'daily:'.$task->id,
                'type' => 'daily',
                'id' => $task->id,
                'taskText' => $task->task_name,
                'sessionName' => $task->session_name,
                'date' => $task->date->toDateString(),
                'detail' => 'Daily task not completed',
            ]);

        $weekly = WeeklyTaskOccurrence::query()
            ->where('status', 'missed')
            ->orderBy('scheduled_date')
            ->limit(50)
            ->get()
            ->map(static fn (WeeklyTaskOccurrence $task): array => [
                'key' => 'weekly:'.$task->id,
                'type' => 'weekly',
                'id' => $task->id,
                'taskText' => $task->task_name,
                'sessionName' => $task->session_name,
                'date' => $task->scheduled_date->toDateString(),
                'detail' => $task->missed_reason === 'unavailable' ? 'Weekly task unavailable' : 'Weekly task missed',
            ]);

        return $daily->concat($weekly)->sortBy('date')->take(50)->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reopenAudits(): array
    {
        return TaskReopenAudit::query()
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn (TaskReopenAudit $audit): array => [
                'id' => $audit->id,
                'taskType' => $audit->task_type,
                'taskText' => $audit->task_name,
                'sessionName' => $audit->session_name,
                'taskDate' => $audit->task_date->toDateString(),
                'previousCompletedAt' => $this->localTimestamp($audit->previous_completed_at),
                'completionNote' => $audit->completion_note,
                'evidenceCount' => $audit->invalidated_evidence_count,
                'reason' => $audit->reason,
                'performedBy' => $audit->performed_by,
                'occurredAt' => $this->localTimestamp($audit->occurred_at),
            ])->values()->all();
    }

    private function base(Request $request, string $mode, CarbonImmutable $date, array $tasks): array
    {
        $user = $request->user();
        $sessions = Schema::hasTable('task_sessions')
            ? TaskSession::query()->orderBy('sort_order')->get()
            : collect();
        $dateString = $date->toDateString();

        return [
            'mode' => $mode,
            'auth' => [
                'user' => $user instanceof User ? $user->only(['id', 'name', 'username']) : null,
                'isAdmin' => $this->adminSession->isAuthenticated($request),
            ],
            'sessions' => $sessions->map(static fn (TaskSession $session): array => [
                'id' => $session->id,
                'name' => $session->name,
                'sortOrder' => $session->sort_order,
                'isActive' => $session->is_active,
            ])->values()->all(),
            'tasks' => $tasks,
            'currentDate' => $dateString,
            'isReadOnly' => ! $this->dates->isToday($dateString),
            'dayUnavailable' => Schema::hasTable('checklist_day_statuses')
                && ChecklistDayStatus::query()
                    ->whereDate('date', $dateString)
                    ->where('is_unavailable', true)
                    ->exists(),
            'uploadLimits' => [
                'maxFiles' => max(1, (int) ini_get('max_file_uploads')),
                'maxFileMb' => 10,
                'uploadMax' => (string) ini_get('upload_max_filesize'),
                'postMax' => (string) ini_get('post_max_size'),
            ],
            'templates' => [],
            'weeklyTemplates' => [],
            'completedTasks' => [],
            'overdueTasks' => [],
            'reopenAudits' => [],
            'statistics' => null,
            'workload' => [],
        ];
    }

    private function workload(Collection $templates, Collection $weeklyTemplates): array
    {
        $sessions = TaskSession::query()->active()->orderBy('sort_order')->get();
        $rows = $sessions->map(function (TaskSession $session) use ($templates, $weeklyTemplates): array {
            $daily = $templates->where('task_session_id', $session->id)->sum(fn ($task) => (float) $task->credit_hours);
            $weekly = $weeklyTemplates->where('task_session_id', $session->id)->sum(fn ($task) => (float) $task->credit_hours);

            return [
                'sessionId' => $session->id,
                'sessionName' => $session->name,
                'dailyCredits' => round($daily, 2),
                'weeklyCredits' => round($weekly, 2),
                'expectedWeeklyCredits' => round(($daily * 7) + $weekly, 2),
            ];
        });
        $average = $rows->avg('expectedWeeklyCredits') ?: 0;

        return $rows->map(static fn (array $row): array => [
            ...$row,
            'isOverloaded' => $average > 0 && $row['expectedWeeklyCredits'] > $average * 1.2,
        ])->values()->all();
    }

    private function localTimestamp($timestamp): ?string
    {
        return $timestamp?->setTimezone($this->dates->timezone())->format('Y-m-d\\TH:i:s.uP');
    }
}
