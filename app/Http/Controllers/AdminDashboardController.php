<?php

namespace App\Http\Controllers;

use App\Exceptions\ChecklistDateOutsideMaterializationWindow;
use App\Http\Requests\AdminHistoryRequest;
use App\Models\TaskCollection;
use App\Models\TaskCollectionSchedule;
use App\Models\TaskTemplate;
use App\Models\WeeklyTaskTemplate;
use App\Services\ChecklistWorkflow;
use App\Services\DashboardPresenter;
use App\Services\OperationalDate;
use App\Services\StatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    public function index(
        AdminHistoryRequest $request,
        DashboardPresenter $presenter,
        OperationalDate $dates,
        ChecklistWorkflow $workflow,
        StatisticsService $statistics,
    ) {
        $date = $request->selectedDate($dates);

        try {
            $checklist = $workflow->forDate($date);
        } catch (ChecklistDateOutsideMaterializationWindow) {
            throw ValidationException::withMessages([
                'date' => 'A new checklist can only be created within 365 days from today.',
            ]);
        }

        $validated = $request->validated();
        $trackingStart = $statistics->trackingStart();
        $to = isset($validated['stats_to']) ? $dates->fromDateString($validated['stats_to']) : $dates->today();
        $to = $to->greaterThan($dates->today()) ? $dates->today() : $to;
        $from = isset($validated['stats_from']) ? $dates->fromDateString($validated['stats_from']) : $to->subDays(29);
        $minimum = CarbonImmutable::parse($trackingStart, $dates->timezone())->startOfDay();
        $from = $from->lessThan($minimum) ? $minimum : $from;
        $from = $from->lessThan($to->subDays(364)) ? $to->subDays(364) : $from;

        $templates = TaskTemplate::query()
            ->active()
            ->with(['taskSession:id,name', 'taskCollections:id,name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $weeklyTemplates = WeeklyTaskTemplate::query()
            ->active()
            ->with(['taskSession:id,name', 'taskCollections:id,name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $collections = TaskCollection::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $collectionSchedules = TaskCollectionSchedule::query()
            ->with('taskCollection:id,name,is_default')
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        return Inertia::render('Dashboard', $presenter->admin(
            $request,
            $date,
            $templates,
            $weeklyTemplates,
            $collections,
            $collectionSchedules,
            $checklist,
            $statistics->build($from, $to),
        ));
    }
}
