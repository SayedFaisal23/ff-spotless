<?php

namespace App\Services;

use App\Exceptions\ChecklistDateOutsideMaterializationWindow;
use App\Models\DailyChecklist;
use App\Models\TaskTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class ChecklistMaterializer
{
    public function __construct(
        private readonly OperationalDate $dates,
    ) {}

    /**
     * @return Collection<int, DailyChecklist>
     */
    public function forDate(CarbonImmutable $date): Collection
    {
        $dateString = $date->toDateString();

        if (! $this->isMaterialized($dateString)) {
            DB::transaction(function () use ($date, $dateString): void {
                $this->acquireTemplateSynchronizationLock();

                if ($this->isMaterialized($dateString)) {
                    return;
                }

                if (! $this->dates->isWithinMaterializationWindow($date)) {
                    throw new ChecklistDateOutsideMaterializationWindow;
                }

                DB::table('checklist_materializations')->insert(['date' => $dateString]);

                $templates = TaskTemplate::query()
                    ->active()
                    ->with('taskSession:id,name')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();

                $rows = $templates->map(static fn (TaskTemplate $template): array => [
                    'date' => $dateString,
                    'task_template_id' => $template->id,
                    'task_name' => $template->task_name,
                    'task_session_id' => $template->task_session_id,
                    'session_name' => $template->taskSession->name,
                    'credit_hours' => $template->credit_hours,
                    'is_completed' => false,
                    'completed_at' => null,
                    'completed_by_user_id' => null,
                ])->all();

                if ($rows !== []) {
                    DailyChecklist::query()->insertOrIgnore($rows);
                }
            }, 3);
        }

        return DailyChecklist::query()
            ->whereDate('date', $dateString)
            ->withCount('evidence')
            ->orderBy('id')
            ->get();
    }

    public function catchUpThrough(CarbonImmutable $date): void
    {
        $startedOn = DB::table('statistics_tracking')->value('started_on');

        if (! is_string($startedOn)) {
            return;
        }

        $cursor = $this->dates->fromDateString($startedOn);
        $last = $date->startOfDay();
        $materialized = DB::table('checklist_materializations')
            ->whereDate('date', '>=', $cursor->toDateString())
            ->whereDate('date', '<=', $last->toDateString())
            ->pluck('date')
            ->mapWithKeys(static fn ($value): array => [substr((string) $value, 0, 10) => true]);

        while ($cursor->lessThanOrEqualTo($last)) {
            if (! $materialized->has($cursor->toDateString())) {
                $this->forDate($cursor);
            }
            $cursor = $cursor->addDay();
        }
    }

    public function appendTemplateToCurrentAndFutureSheets(TaskTemplate $template): void
    {
        $today = $this->dates->today()->toDateString();
        $template->loadMissing('taskSession:id,name');

        DB::transaction(function () use ($template, $today): void {
            $this->acquireTemplateSynchronizationLock();

            DB::table('checklist_materializations')
                ->whereDate('date', '>=', $today)
                ->orderBy('date')
                ->pluck('date')
                ->chunk(500)
                ->each(function ($dates) use ($template): void {
                    $rows = $dates->map(static fn (string $date): array => [
                        'date' => $date,
                        'task_template_id' => $template->id,
                        'task_name' => $template->task_name,
                        'task_session_id' => $template->task_session_id,
                        'session_name' => $template->taskSession->name,
                        'credit_hours' => $template->credit_hours,
                        'is_completed' => false,
                        'completed_at' => null,
                        'completed_by_user_id' => null,
                    ])->all();

                    DailyChecklist::query()->insertOrIgnore($rows);
                });
        }, 3);
    }

    public function updateTemplateAndCurrentAndFutureIncompleteSnapshots(
        TaskTemplate $template,
        string $taskName,
        int $sessionId,
        string $sessionName,
        string $creditHours,
    ): bool {
        $today = $this->dates->today()->toDateString();

        return DB::transaction(function () use ($template, $taskName, $sessionId, $sessionName, $creditHours, $today): bool {
            $this->acquireTemplateSynchronizationLock();
            $lockedTemplate = TaskTemplate::query()->lockForUpdate()->findOrFail($template->getKey());

            if (! $lockedTemplate->is_active) {
                return false;
            }

            if ($lockedTemplate->task_session_id !== $sessionId) {
                $taskIds = DailyChecklist::query()
                    ->where('task_template_id', $lockedTemplate->getKey())
                    ->whereDate('date', '>=', $today)
                    ->where('is_completed', false)
                    ->pluck('id');
                DB::table('checklist_item_positions')
                    ->where('item_type', 'daily')
                    ->whereIn('item_id', $taskIds)
                    ->delete();
            }

            $lockedTemplate->forceFill([
                'task_name' => $taskName,
                'task_session_id' => $sessionId,
                'credit_hours' => $creditHours,
            ])->save();

            DailyChecklist::query()
                ->where('task_template_id', $lockedTemplate->getKey())
                ->whereDate('date', '>=', $today)
                ->where('is_completed', false)
                ->update([
                    'task_name' => $taskName,
                    'task_session_id' => $sessionId,
                    'session_name' => $sessionName,
                    'credit_hours' => $creditHours,
                ]);

            return true;
        }, 3);
    }

    public function renameSessionSnapshots(int $sessionId, string $name): void
    {
        DailyChecklist::query()
            ->where('task_session_id', $sessionId)
            ->whereDate('date', '>=', $this->dates->today()->toDateString())
            ->where('is_completed', false)
            ->update(['session_name' => $name]);
    }

    public function deactivateTemplateAndRemoveCurrentAndFutureIncompleteSnapshots(TaskTemplate $template): void
    {
        $today = $this->dates->today()->toDateString();

        DB::transaction(function () use ($template, $today): void {
            $this->acquireTemplateSynchronizationLock();
            $lockedTemplate = TaskTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            $lockedTemplate->forceFill(['is_active' => false])->save();

            $taskIds = DailyChecklist::query()
                ->where('task_template_id', $lockedTemplate->getKey())
                ->whereDate('date', '>=', $today)
                ->where('is_completed', false)
                ->pluck('id');

            DB::table('checklist_item_positions')
                ->where('item_type', 'daily')
                ->whereIn('item_id', $taskIds)
                ->delete();

            DailyChecklist::query()
                ->whereKey($taskIds)
                ->delete();
        }, 3);
    }

    private function isMaterialized(string $date): bool
    {
        return DB::table('checklist_materializations')->whereDate('date', $date)->exists();
    }

    public function acquireTemplateSynchronizationLock(): void
    {
        $lock = DB::table('checklist_sync_locks')
            ->where('name', 'template-synchronization')
            ->lockForUpdate()
            ->first();

        if ($lock === null) {
            throw new LogicException('The checklist template synchronization lock is missing.');
        }
    }
}
