<?php

namespace App\Services;

use App\Models\ChecklistDayStatus;
use App\Models\WeeklyTaskOccurrence;
use App\Models\WeeklyTaskPostponement;
use App\Models\WeeklyTaskTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WeeklyTaskScheduler
{
    public function __construct(
        private readonly ChecklistMaterializer $materializer,
        private readonly OperationalDate $dates,
        private readonly TaskCollectionResolver $collections,
    ) {}

    public function materializeWeek(CarbonImmutable $date, bool $refresh = false): void
    {
        $weekStart = $date->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->endOfWeek(CarbonImmutable::SUNDAY);

        DB::transaction(function () use ($weekStart, $weekEnd, $refresh): void {
            $this->materializer->acquireTemplateSynchronizationLock();
            $alreadyMaterialized = DB::table('weekly_materializations')
                ->whereDate('week_start', $weekStart->toDateString())
                ->exists();

            if ($alreadyMaterialized && ! $refresh) {
                return;
            }

            DB::table('weekly_materializations')->insertOrIgnore([
                'week_start' => $weekStart->toDateString(),
            ]);

            $templates = WeeklyTaskTemplate::query()
                ->active()
                ->whereDate('starts_on', '<=', $weekEnd->toDateString())
                ->with(['taskSession:id,name', 'taskCollections:id,name'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->filter(function (WeeklyTaskTemplate $template) use ($weekStart): bool {
                    if ($template->applies_to_all_collections) {
                        return true;
                    }

                    $dueDate = $weekStart->addDays($template->due_weekday - 1);
                    $activeCollection = $this->collections->forDate($dueDate);

                    return $template->taskCollections->contains('id', $activeCollection->getKey());
                })
                ->values();

            if ($refresh) {
                $staleQuery = WeeklyTaskOccurrence::query()
                    ->whereDate('week_start', $weekStart->toDateString())
                    ->where('status', 'pending');

                $staleIds = $templates->isEmpty()
                    ? $staleQuery->pluck('id')
                    : $staleQuery->whereNotIn('weekly_task_template_id', $templates->pluck('id'))->pluck('id');

                if ($staleIds->isNotEmpty()) {
                    DB::table('checklist_item_positions')
                        ->where('item_type', 'weekly')
                        ->whereIn('item_id', $staleIds)
                        ->delete();

                    WeeklyTaskPostponement::query()
                        ->whereIn('weekly_task_occurrence_id', $staleIds)
                        ->delete();

                    WeeklyTaskOccurrence::query()->whereKey($staleIds)->delete();
                }
            }

            $templates->each(function (WeeklyTaskTemplate $template) use ($weekStart): void {
                $this->syncTemplateOccurrence($template, $weekStart);
            });
        }, 3);
    }

    public function advanceThrough(CarbonImmutable $date): void
    {
        $trackingStart = DB::table('statistics_tracking')->value('started_on');
        $cursor = is_string($trackingStart)
            ? $this->dates->fromDateString($trackingStart)->startOfWeek(CarbonImmutable::MONDAY)
            : $date->startOfWeek(CarbonImmutable::MONDAY);
        $lastWeek = $date->startOfWeek(CarbonImmutable::MONDAY);

        $materialized = DB::table('weekly_materializations')
            ->whereDate('week_start', '>=', $cursor->toDateString())
            ->whereDate('week_start', '<=', $lastWeek->toDateString())
            ->pluck('week_start')
            ->mapWithKeys(static fn ($value): array => [substr((string) $value, 0, 10) => true]);

        while ($cursor->lessThanOrEqualTo($lastWeek)) {
            if (! $materialized->has($cursor->toDateString())) {
                $this->materializeWeek($cursor);
            }
            $cursor = $cursor->addWeek();
        }

        $dateString = $date->toDateString();

        WeeklyTaskOccurrence::query()
            ->where('status', 'pending')
            ->whereDate('week_start', '<=', $dateString)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use ($date): void {
                DB::transaction(function () use ($id, $date): void {
                    $occurrence = WeeklyTaskOccurrence::query()->lockForUpdate()->findOrFail($id);

                    while ($occurrence->status === 'pending' && $occurrence->scheduled_date->lessThan($date)) {
                        $this->postponeOrMiss($occurrence);
                        $occurrence->refresh();
                    }

                    if (
                        $occurrence->status === 'pending'
                        && $occurrence->scheduled_date->isSameDay($date)
                        && $this->isUnavailable($date)
                    ) {
                        $this->postponeOrMiss($occurrence, 'unavailable');
                    }
                }, 3);
            });
    }

    /**
     * @return Collection<int, WeeklyTaskOccurrence>
     */
    public function forChecklistDate(CarbonImmutable $date): Collection
    {
        $today = $this->dates->today();
        $this->materializeWeek($date);
        $this->advanceThrough($date->lessThan($today) ? $date : $today);
        $query = WeeklyTaskOccurrence::query()
            ->withCount(['evidence', 'postponements'])
            ->whereDate('week_start', $date->startOfWeek(CarbonImmutable::MONDAY)->toDateString());

        if ($date->isSameDay($today)) {
            $query->where(function ($builder) use ($date): void {
                $builder->where('status', 'pending')
                    ->orWhereDate('completed_on', $date->toDateString());
            });
        } else {
            $query->where(function ($builder) use ($date): void {
                $builder->whereDate('completed_on', $date->toDateString())
                    ->orWhereHas('postponements', function ($postponements) use ($date): void {
                        $postponements->whereDate('from_date', $date->toDateString());
                    })
                    ->orWhere(function ($nested) use ($date): void {
                        $nested->whereIn('status', ['pending', 'missed'])
                            ->whereDate('scheduled_date', $date->toDateString());
                    });
            });
        }

        return $query->orderBy('id')->get();
    }

    public function updateTemplateSnapshots(WeeklyTaskTemplate $template, ?CarbonImmutable $fromWeek = null): bool
    {
        $start = ($fromWeek ?? $template->starts_on)->startOfWeek(CarbonImmutable::MONDAY);
        $todayWeek = $this->dates->today()->startOfWeek(CarbonImmutable::MONDAY);

        if ($start->lessThan($todayWeek)) {
            $start = $todayWeek;
        }

        $this->refreshMaterializedWeeksFrom($start);

        return true;
    }

    public function refreshMaterializedWeeksFrom(CarbonImmutable $date): void
    {
        DB::table('weekly_materializations')
            ->whereDate('week_start', '>=', $date->startOfWeek(CarbonImmutable::MONDAY)->toDateString())
            ->orderBy('week_start')
            ->pluck('week_start')
            ->each(function ($weekStart): void {
                $this->materializeWeek($this->dates->fromDateString(substr((string) $weekStart, 0, 10)), true);
            });
    }

    public function deactivateTemplate(WeeklyTaskTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            $this->materializer->acquireTemplateSynchronizationLock();
            $template->forceFill(['is_active' => false])->save();
            $occurrenceIds = $template->occurrences()
                ->where('status', 'pending')
                ->whereDate('week_start', '>=', $this->dates->today()->startOfWeek()->toDateString())
                ->pluck('id');
            DB::table('checklist_item_positions')
                ->where('item_type', 'weekly')
                ->whereIn('item_id', $occurrenceIds)
                ->delete();
            $template->occurrences()
                ->whereKey($occurrenceIds)
                ->delete();
        }, 3);
    }

    private function postponeOrMiss(WeeklyTaskOccurrence $occurrence, ?string $forcedReason = null): void
    {
        $from = $occurrence->scheduled_date;

        if ($from->dayOfWeekIso >= 7) {
            $occurrence->forceFill([
                'status' => 'missed',
                'missed_reason' => $forcedReason ?? ($this->isUnavailable($from) ? 'unavailable' : 'incomplete'),
            ])->save();

            return;
        }

        $to = $from->addDay();
        WeeklyTaskPostponement::query()->firstOrCreate(
            ['weekly_task_occurrence_id' => $occurrence->id, 'from_date' => $from->toDateString()],
            [
                'to_date' => $to->toDateString(),
                'reason' => $forcedReason ?? ($this->isUnavailable($from) ? 'unavailable' : 'incomplete'),
            ],
        );
        $occurrence->forceFill(['scheduled_date' => $to->toDateString()])->save();
    }

    private function isUnavailable(CarbonImmutable $date): bool
    {
        return ChecklistDayStatus::query()
            ->whereDate('date', $date->toDateString())
            ->where('is_unavailable', true)
            ->exists();
    }

    private function syncTemplateOccurrence(WeeklyTaskTemplate $template, CarbonImmutable $weekStart): void
    {
        $template->loadMissing('taskSession:id,name');
        $dueDate = $weekStart->addDays($template->due_weekday - 1);
        $existing = WeeklyTaskOccurrence::query()
            ->whereDate('week_start', $weekStart->toDateString())
            ->where('weekly_task_template_id', $template->id)
            ->first();

        if ($existing === null) {
            WeeklyTaskOccurrence::query()->create([
                'week_start' => $weekStart->toDateString(),
                'weekly_task_template_id' => $template->id,
                'task_session_id' => $template->task_session_id,
                'task_name' => $template->task_name,
                'session_name' => $template->taskSession->name,
                'credit_hours' => $template->credit_hours,
                'original_due_date' => $dueDate->toDateString(),
                'scheduled_date' => $dueDate->toDateString(),
                'status' => 'pending',
            ]);

            return;
        }

        if ($existing->status !== 'pending') {
            return;
        }

        $dueChanged = ! $existing->original_due_date->isSameDay($dueDate);

        if ($existing->task_session_id !== $template->task_session_id) {
            DB::table('checklist_item_positions')
                ->where('item_type', 'weekly')
                ->where('item_id', $existing->id)
                ->delete();
        }

        $changes = [
            'task_name' => $template->task_name,
            'task_session_id' => $template->task_session_id,
            'session_name' => $template->taskSession->name,
            'credit_hours' => $template->credit_hours,
            'original_due_date' => $dueDate->toDateString(),
        ];

        if ($dueChanged) {
            $changes['scheduled_date'] = $dueDate->toDateString();
            $changes['missed_reason'] = null;
            $existing->postponements()->delete();
        }

        $existing->forceFill($changes)->save();
    }
}
