<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderTaskSessionsRequest;
use App\Http\Requests\StoreTaskSessionRequest;
use App\Models\TaskSession;
use App\Models\WeeklyTaskOccurrence;
use App\Services\ChecklistMaterializer;
use App\Services\OperationalDate;
use App\Services\WeeklyTaskScheduler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskSessionManagementController extends Controller
{
    public function store(StoreTaskSessionRequest $request)
    {
        $name = $request->validated('name');
        TaskSession::query()->create([
            'name' => $name,
            'sort_order' => (int) TaskSession::query()->max('sort_order') + 1,
            'is_active' => true,
        ]);

        return to_route('admin.index');
    }

    public function update(
        StoreTaskSessionRequest $request,
        TaskSession $taskSession,
        ChecklistMaterializer $materializer,
        OperationalDate $dates,
        WeeklyTaskScheduler $weekly,
    ) {
        $materializer->catchUpThrough($dates->today());
        $weekly->advanceThrough($dates->today());
        $name = $request->validated('name');

        DB::transaction(function () use ($taskSession, $name, $materializer, $dates): void {
            $taskSession->forceFill(['name' => $name])->save();
            $materializer->renameSessionSnapshots($taskSession->id, $name);
            WeeklyTaskOccurrence::query()
                ->where('task_session_id', $taskSession->id)
                ->where('status', 'pending')
                ->whereDate('week_start', '>=', $dates->today()->startOfWeek()->toDateString())
                ->update(['session_name' => $name]);
        }, 3);

        return to_route('admin.index');
    }

    public function reorder(ReorderTaskSessionsRequest $request)
    {
        $ids = array_map('intval', $request->validated('session_ids'));
        $active = TaskSession::query()->active()->orderBy('sort_order')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $submitted = $ids;
        sort($active);
        sort($submitted);

        if ($active !== $submitted) {
            throw ValidationException::withMessages(['session_ids' => 'The order must contain every active session.']);
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $id) {
                TaskSession::query()->whereKey($id)->increment('sort_order', 10000);
            }
            foreach ($ids as $index => $id) {
                TaskSession::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        }, 3);

        return to_route('admin.index');
    }

    public function destroy(Request $request, TaskSession $taskSession)
    {
        if (! $taskSession->is_active) {
            return to_route('admin.index');
        }

        if (TaskSession::query()->active()->count() <= 1) {
            throw ValidationException::withMessages(['session' => 'At least one active session must be kept.']);
        }

        if ($taskSession->taskTemplates()->active()->exists() || $taskSession->weeklyTaskTemplates()->active()->exists()) {
            throw ValidationException::withMessages(['session' => 'Move every active task before archiving this session.']);
        }

        $taskSession->forceFill(['is_active' => false])->save();

        return to_route('admin.index');
    }
}
