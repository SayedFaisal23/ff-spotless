<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReopenTaskRequest;
use App\Models\DailyChecklist;
use App\Models\WeeklyTaskOccurrence;
use App\Services\TaskReopenService;

class AdminTaskReopenController extends Controller
{
    public function daily(ReopenTaskRequest $request, DailyChecklist $dailyChecklist, TaskReopenService $service)
    {
        $service->reopenDaily($dailyChecklist, $request->validated('reason'));

        return to_route('admin.index');
    }

    public function weekly(ReopenTaskRequest $request, WeeklyTaskOccurrence $weeklyTaskOccurrence, TaskReopenService $service)
    {
        $service->reopenWeekly($weeklyTaskOccurrence, $request->validated('reason'));

        return to_route('admin.index');
    }
}
