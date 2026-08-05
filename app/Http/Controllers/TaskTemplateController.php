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

        DB::transaction(function () use ($data): void {
            $collectionIds = $data['applies_to_all_collections'] ? [] : array_values(array_unique($data['task_collection_ids'] ?? []));
            $template = TaskTemplate::query()->create([
                'task_name' => $data['task_name'],
                'task_session_id' => $data['task_session_id'],
                'task_collection_id' => $collectionIds[0] ?? null,
                'applies_to_all_collections' => $data['applies_to_all_collections'],
                'credit_hours' => $data['credit_hours'],
                'sort_order' => (int) TaskTemplate::query()->max('sort_order') + 1,
                'is_active' => true,
            ]);

            $template->taskCollections()->sync($collectionIds);
        }, 3);

        $materializer->refreshMaterializedDatesFrom($dates->today());

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

        DB::transaction(function () use ($taskTemplate, $data): void {
            $collectionIds = $data['applies_to_all_collections'] ? [] : array_values(array_unique($data['task_collection_ids'] ?? []));
            $lockedTemplate = TaskTemplate::query()->lockForUpdate()->findOrFail($taskTemplate->getKey());

            if (! $lockedTemplate->is_active) {
                abort(404, 'Task template was not found or has been archived.');
            }

            $lockedTemplate->forceFill([
                'task_name' => $data['task_name'],
                'task_session_id' => $data['task_session_id'],
                'task_collection_id' => $collectionIds[0] ?? null,
                'applies_to_all_collections' => $data['applies_to_all_collections'],
                'credit_hours' => $data['credit_hours'],
            ])->save();

            $lockedTemplate->taskCollections()->sync($collectionIds);
        }, 3);

        $materializer->refreshMaterializedDatesFrom($dates->today());

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
        DB::transaction(function () use ($taskTemplate): void {
            $lockedTemplate = TaskTemplate::query()->lockForUpdate()->findOrFail($taskTemplate->getKey());
            $lockedTemplate->forceFill(['is_active' => false])->save();
        }, 3);
        $materializer->refreshMaterializedDatesFrom($dates->today());

        return to_route('admin.index');
    }
}
