<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyTaskTemplateRequest;
use App\Http\Requests\StoreTaskTemplateRequest;
use App\Http\Requests\UpdateTaskTemplateRequest;
use App\Models\TaskTemplate;
use App\Models\TaskSession;
use App\Services\ChecklistMaterializer;
use App\Services\OperationalDate;
use App\Services\WeeklyTaskScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskTemplateController extends Controller
{
    public function store(
        StoreTaskTemplateRequest $request,
        ChecklistMaterializer $materializer,
        OperationalDate $dates,
        WeeklyTaskScheduler $weekly,
    )
    {
        $data = $request->validated();
        $materializer->catchUpThrough($dates->today());
        $weekly->advanceThrough($dates->today());
        $session = TaskSession::query()->active()->find($data['task_session_id']);

        if ($session === null) {
            throw ValidationException::withMessages(['task_session_id' => 'Task session is not active.']);
        }

        DB::transaction(function () use ($data, $materializer): void {
            $template = TaskTemplate::query()->create([
                'task_name' => $data['task_name'],
                'task_session_id' => $data['task_session_id'],
                'credit_hours' => $data['credit_hours'],
                'sort_order' => (int) TaskTemplate::query()->max('sort_order') + 1,
                'is_active' => true,
            ]);

            $materializer->appendTemplateToCurrentAndFutureSheets($template);
        }, 3);

        return to_route('admin.index');
    }

    public function update(
        UpdateTaskTemplateRequest $request,
        TaskTemplate $taskTemplate,
        ChecklistMaterializer $materializer,
        OperationalDate $dates,
        WeeklyTaskScheduler $weekly,
    ) {
        $data = $request->validated();
        $materializer->catchUpThrough($dates->today());
        $weekly->advanceThrough($dates->today());
        $session = TaskSession::query()->active()->find($data['task_session_id']);

        if ($session === null) {
            throw ValidationException::withMessages(['task_session_id' => 'Task session is not active.']);
        }

        $updated = $materializer->updateTemplateAndCurrentAndFutureIncompleteSnapshots(
            $taskTemplate,
            $data['task_name'],
            $session->id,
            $session->name,
            (string) $data['credit_hours'],
        );

        if (! $updated) {
            abort(404, 'Task template was not found or has been archived.');
        }

        return to_route('admin.index');
    }

    public function destroy(
        DestroyTaskTemplateRequest $request,
        TaskTemplate $taskTemplate,
        ChecklistMaterializer $materializer,
        OperationalDate $dates,
        WeeklyTaskScheduler $weekly,
    ) {
        $materializer->catchUpThrough($dates->today());
        $weekly->advanceThrough($dates->today());
        $materializer->deactivateTemplateAndRemoveCurrentAndFutureIncompleteSnapshots($taskTemplate);

        return to_route('admin.index');
    }
}
