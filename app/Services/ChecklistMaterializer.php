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
        private readonly TaskCollectionResolver $collections,
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

                $activeCollection = $this->collections->forDate($date);
                $templates = TaskTemplate::query()
                    ->active()
                    ->where(function ($query) use ($activeCollection): void {
                        $query->where('applies_to_all_collections', true)
                            ->orWhereHas('taskCollections', function ($collections) use ($activeCollection): void {
                                $collections->whereKey($activeCollection->getKey());
                            });
                    })
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
        $this->refreshMaterializedDatesFrom($this->dates->today());
    }

    public function updateTemplateAndCurrentAndFutureIncompleteSnapshots(
        TaskTemplate $template,
        string $taskName,
        int $sessionId,
        string $sessionName,
        string $creditHours,
    ): bool {
        return DB::transaction(function () use ($template, $taskName, $sessionId, $creditHours): bool {
            $this->acquireTemplateSynchronizationLock();
            $lockedTemplate = TaskTemplate::query()->lockForUpdate()->findOrFail($template->getKey());

            if (! $lockedTemplate->is_active) {
                return false;
            }

            $lockedTemplate->forceFill([
                'task_name' => $taskName,
                'task_session_id' => $sessionId,
                'credit_hours' => $creditHours,
            ])->save();

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
        DB::transaction(function () use ($template): void {
            $this->acquireTemplateSynchronizationLock();
            $lockedTemplate = TaskTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            $lockedTemplate->forceFill(['is_active' => false])->save();
        }, 3);
    }

    public function refreshMaterializedDatesFrom(CarbonImmutable $date): void
    {
        DB::table('checklist_materializations')
            ->whereDate('date', '>=', $date->toDateString())
            ->orderBy('date')
            ->pluck('date')
            ->each(function ($value): void {
                $this->syncMaterializedDate($this->dates->fromDateString(substr((string) $value, 0, 10)));
            });
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

    private function syncMaterializedDate(CarbonImmutable $date): void
    {
        $dateString = $date->toDateString();

        DB::transaction(function () use ($date, $dateString): void {
            $this->acquireTemplateSynchronizationLock();
            $activeCollection = $this->collections->forDate($date);
            $templates = TaskTemplate::query()
                ->active()
                ->where(function ($query) use ($activeCollection): void {
                    $query->where('applies_to_all_collections', true)
                        ->orWhereHas('taskCollections', function ($collections) use ($activeCollection): void {
                            $collections->whereKey($activeCollection->getKey());
                        });
                })
                ->with('taskSession:id,name')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $rows = DailyChecklist::query()
                ->whereDate('date', $dateString)
                ->orderBy('id')
                ->get()
                ->groupBy('task_template_id');

            $staleIds = $rows
                ->filter(fn ($group, $taskTemplateId): bool => ! $templates->has((int) $taskTemplateId))
                ->flatten(1)
                ->filter(fn (DailyChecklist $task): bool => ! $task->is_completed)
                ->pluck('id');

            if ($staleIds->isNotEmpty()) {
                DB::table('checklist_item_positions')
                    ->where('item_type', 'daily')
                    ->whereIn('item_id', $staleIds)
                    ->delete();

                DailyChecklist::query()->whereKey($staleIds)->delete();
            }

            $templates->each(function (TaskTemplate $template) use ($dateString, $rows): void {
                $existing = $rows->get($template->id)?->first();

                if ($existing instanceof DailyChecklist) {
                    if (! $existing->is_completed) {
                        if ($existing->task_session_id !== $template->task_session_id) {
                            DB::table('checklist_item_positions')
                                ->where('item_type', 'daily')
                                ->where('item_id', $existing->id)
                                ->delete();
                        }

                        $existing->forceFill([
                            'task_name' => $template->task_name,
                            'task_session_id' => $template->task_session_id,
                            'session_name' => $template->taskSession->name,
                            'credit_hours' => $template->credit_hours,
                        ])->save();
                    }

                    return;
                }

                DailyChecklist::query()->create([
                    'date' => $dateString,
                    'task_template_id' => $template->id,
                    'task_name' => $template->task_name,
                    'task_session_id' => $template->task_session_id,
                    'session_name' => $template->taskSession->name,
                    'credit_hours' => $template->credit_hours,
                    'is_completed' => false,
                    'completed_at' => null,
                    'completed_by_user_id' => null,
                ]);
            });
        }, 3);
    }
}
