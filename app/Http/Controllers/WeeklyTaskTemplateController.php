<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWeeklyTaskTemplateRequest;
use App\Http\Requests\UpdateWeeklyTaskTemplateRequest;
use App\Models\TaskSession;
use App\Models\WeeklyTaskTemplate;
use App\Services\ChecklistMaterializer;
use App\Services\OperationalDate;
use App\Services\WeeklyTaskScheduler;
use Illuminate\Validation\ValidationException;

class WeeklyTaskTemplateController extends Controller
{
    public function store(
        StoreWeeklyTaskTemplateRequest $request,
        ChecklistMaterializer $daily,
        WeeklyTaskScheduler $scheduler,
        OperationalDate $dates,
    ) {
        $data = $request->validated();
        $daily->catchUpThrough($dates->today());
        $scheduler->advanceThrough($dates->today());
        $session = TaskSession::query()->active()->find($data['task_session_id']);

        if ($session === null) {
            throw ValidationException::withMessages(['task_session_id' => 'Task session is not active.']);
        }

        $today = $dates->today();
        $startsOn = $data['due_weekday'] >= $today->dayOfWeekIso
            ? $today->startOfWeek()
            : $today->addWeek()->startOfWeek();

        WeeklyTaskTemplate::query()->create([
            ...$data,
            'sort_order' => (int) WeeklyTaskTemplate::query()->max('sort_order') + 1,
            'starts_on' => $startsOn->toDateString(),
            'is_active' => true,
        ]);

        $scheduler->materializeWeek($startsOn, true);
        $scheduler->refreshMaterializedWeeksFrom($startsOn);

        return to_route('admin.index');
    }

    public function update(
        UpdateWeeklyTaskTemplateRequest $request,
        WeeklyTaskTemplate $weeklyTaskTemplate,
        ChecklistMaterializer $daily,
        WeeklyTaskScheduler $scheduler,
        OperationalDate $dates,
    ) {
        $data = $request->validated();
        $daily->catchUpThrough($dates->today());
        $scheduler->advanceThrough($dates->today());

        if (! $weeklyTaskTemplate->is_active || ! TaskSession::query()->active()->whereKey($data['task_session_id'])->exists()) {
            throw ValidationException::withMessages(['task' => 'Weekly template or session is not active.']);
        }

        $weeklyTaskTemplate->forceFill($data)->save();
        $scheduler->updateTemplateSnapshots($weeklyTaskTemplate);

        return to_route('admin.index');
    }

    public function destroy(
        WeeklyTaskTemplate $weeklyTaskTemplate,
        ChecklistMaterializer $daily,
        WeeklyTaskScheduler $scheduler,
        OperationalDate $dates,
    ) {
        $daily->catchUpThrough($dates->today());
        $scheduler->advanceThrough($dates->today());
        $scheduler->deactivateTemplate($weeklyTaskTemplate);

        return to_route('admin.index');
    }
}
