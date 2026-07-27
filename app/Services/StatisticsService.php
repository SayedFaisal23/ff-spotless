<?php

namespace App\Services;

use App\Models\ChecklistDayStatus;
use App\Models\DailyChecklist;
use App\Models\TaskSession;
use App\Models\WeeklyTaskOccurrence;
use App\Models\WeeklyTaskPostponement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    public function __construct(
        private readonly ChecklistMaterializer $daily,
        private readonly WeeklyTaskScheduler $weekly,
        private readonly OperationalDate $dates,
    ) {}

    public function trackingStart(): string
    {
        return (string) DB::table('statistics_tracking')->value('started_on');
    }

    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->daily->catchUpThrough($this->dates->today());
        $this->weekly->advanceThrough($this->dates->today());

        $dailyTasks = DailyChecklist::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get();
        $weekly = WeeklyTaskOccurrence::query()
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($scheduled) use ($from, $to): void {
                    $scheduled->whereDate('scheduled_date', '>=', $from->toDateString())
                        ->whereDate('scheduled_date', '<=', $to->toDateString());
                })->orWhere(function ($completed) use ($from, $to): void {
                    $completed->whereDate('completed_on', '>=', $from->toDateString())
                        ->whereDate('completed_on', '<=', $to->toDateString());
                });
            })
            ->get();
        $sessions = TaskSession::query()->orderBy('sort_order')->get(['id', 'name']);
        $today = $this->dates->today();
        $trend = [];
        $overview = [
            'completed' => 0,
            'missed' => 0,
            'pending' => 0,
            'plannedCredits' => 0.0,
            'completedCredits' => 0.0,
        ];

        for ($cursor = $from; $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addDay()) {
            $date = $cursor->toDateString();
            $row = [
                'date' => $date,
                'completed' => 0,
                'missed' => 0,
                'pending' => 0,
                'plannedCredits' => 0.0,
                'completedCredits' => 0.0,
            ];

            foreach ($dailyTasks->filter(fn ($task) => $task->date->toDateString() === $date) as $task) {
                $credits = (float) $task->credit_hours;
                $row['plannedCredits'] += $credits;

                if ($task->is_completed) {
                    $row['completed']++;
                    $row['completedCredits'] += $credits;
                } elseif ($cursor->lessThan($today)) {
                    $row['missed']++;
                } else {
                    $row['pending']++;
                }
            }

            foreach ($weekly as $task) {
                $credits = (float) $task->credit_hours;

                if ($task->scheduled_date->toDateString() === $date) {
                    $row['plannedCredits'] += $credits;
                    if ($task->status === 'missed') {
                        $row['missed']++;
                    } elseif ($task->status === 'pending') {
                        $row['pending']++;
                    }
                }

                if ($task->status === 'completed' && $task->completed_on?->toDateString() === $date) {
                    $row['completed']++;
                    $row['completedCredits'] += $credits;
                }
            }

            foreach (array_keys($overview) as $key) {
                $overview[$key] += $row[$key];
            }
            $trend[] = $row;
        }

        $closed = $overview['completed'] + $overview['missed'];
        $overview['completionRate'] = $closed > 0 ? round(($overview['completed'] / $closed) * 100) : 0;
        $overview['mcDays'] = ChecklistDayStatus::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->where('is_unavailable', true)
            ->count();
        $overview['postponements'] = WeeklyTaskPostponement::query()
            ->whereDate('from_date', '>=', $from->toDateString())
            ->whereDate('from_date', '<=', $to->toDateString())
            ->count();

        $sessionRows = $sessions->map(function (TaskSession $session) use ($dailyTasks, $weekly, $today, $from, $to): array {
            $daily = $dailyTasks->where('task_session_id', $session->id);
            $weeklies = $weekly->where('task_session_id', $session->id);
            $plannedWeeklies = $weeklies->filter(fn ($task) => $task->scheduled_date->betweenIncluded($from, $to));
            $completedWeeklies = $weeklies->filter(fn ($task) => $task->status === 'completed' && $task->completed_on?->betweenIncluded($from, $to));

            return [
                'id' => $session->id,
                'name' => $session->name,
                'plannedCredits' => round(
                    $daily->sum(fn ($task) => (float) $task->credit_hours)
                    + $plannedWeeklies->sum(fn ($task) => (float) $task->credit_hours),
                    2,
                ),
                'completedCredits' => round(
                    $daily->where('is_completed', true)->sum(fn ($task) => (float) $task->credit_hours)
                    + $completedWeeklies->sum(fn ($task) => (float) $task->credit_hours),
                    2,
                ),
                'completed' => $daily->where('is_completed', true)->count() + $completedWeeklies->count(),
                'missed' => $daily->filter(fn ($task) => ! $task->is_completed && $task->date->lessThan($today))->count()
                    + $plannedWeeklies->where('status', 'missed')->count(),
            ];
        })->values()->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'trackingStart' => $this->trackingStart(),
            'overview' => $overview,
            'trend' => $trend,
            'sessions' => $sessionRows,
            'weeklyStatus' => [
                'completed' => $weekly->filter(fn ($task) => $task->status === 'completed' && $task->completed_on?->betweenIncluded($from, $to))->count(),
                'pending' => $weekly->filter(fn ($task) => $task->status === 'pending' && $task->scheduled_date->betweenIncluded($from, $to))->count(),
                'missed' => $weekly->filter(fn ($task) => $task->status === 'missed' && $task->scheduled_date->betweenIncluded($from, $to))->count(),
            ],
        ];
    }
}
