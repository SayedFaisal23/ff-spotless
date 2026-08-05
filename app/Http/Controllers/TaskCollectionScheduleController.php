<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskCollectionScheduleRequest;
use App\Models\TaskCollectionSchedule;
use App\Services\ChecklistMaterializer;
use App\Services\OperationalDate;
use App\Services\WeeklyTaskScheduler;
use Carbon\CarbonImmutable;

class TaskCollectionScheduleController extends Controller
{
    public function store(
        StoreTaskCollectionScheduleRequest $request,
        ChecklistMaterializer $daily,
        WeeklyTaskScheduler $weekly,
        OperationalDate $dates,
    ) {
        $data = $request->validated();
        $daily->catchUpThrough($dates->today());
        $weekly->advanceThrough($dates->today());
        $schedule = TaskCollectionSchedule::query()->create($data);
        $start = $dates->fromDateString($schedule->starts_on->toDateString())->startOfWeek(CarbonImmutable::MONDAY);

        $daily->refreshMaterializedDatesFrom($start);
        $weekly->refreshMaterializedWeeksFrom($start);

        return to_route('admin.index');
    }

    public function destroy(
        TaskCollectionSchedule $taskCollectionSchedule,
        ChecklistMaterializer $daily,
        WeeklyTaskScheduler $weekly,
        OperationalDate $dates,
    ) {
        $daily->catchUpThrough($dates->today());
        $weekly->advanceThrough($dates->today());
        $start = $taskCollectionSchedule->starts_on->startOfWeek(CarbonImmutable::MONDAY);

        $taskCollectionSchedule->delete();
        $daily->refreshMaterializedDatesFrom($start);
        $weekly->refreshMaterializedWeeksFrom($start);

        return to_route('admin.index');
    }
}
