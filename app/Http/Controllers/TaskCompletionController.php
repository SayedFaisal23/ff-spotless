<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteTaskRequest;
use App\Models\DailyChecklist;
use App\Models\WeeklyTaskOccurrence;
use App\Services\TaskCompletionService;

class TaskCompletionController extends Controller
{
    public function daily(CompleteTaskRequest $request, DailyChecklist $dailyChecklist, TaskCompletionService $service)
    {
        $data = $request->validated();
        $service->completeDaily($dailyChecklist, $data['date'], $request->file('photos', []), $data['note'] ?? null);

        return to_route('checklist.index', ['date' => $data['date']]);
    }

    public function weekly(CompleteTaskRequest $request, WeeklyTaskOccurrence $weeklyTaskOccurrence, TaskCompletionService $service)
    {
        $data = $request->validated();
        $service->completeWeekly($weeklyTaskOccurrence, $data['date'], $request->file('photos', []), $data['note'] ?? null);

        return to_route('checklist.index', ['date' => $data['date']]);
    }
}
