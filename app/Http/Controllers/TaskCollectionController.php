<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskCollectionRequest;
use App\Models\TaskCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskCollectionController extends Controller
{
    public function store(StoreTaskCollectionRequest $request)
    {
        TaskCollection::query()->create([
            'name' => $request->validated('name'),
            'is_default' => false,
        ]);

        return to_route('admin.index');
    }

    public function destroy(TaskCollection $taskCollection)
    {
        if ($taskCollection->is_default) {
            throw ValidationException::withMessages([
                'collection' => 'The default collection cannot be deleted.',
            ]);
        }

        $isUsedBySchedule = $taskCollection->schedules()->exists();
        $isUsedByLegacyTask = $taskCollection->dailyTemplates()->exists() || $taskCollection->weeklyTemplates()->exists();
        $isUsedByScopedTask = DB::table('task_template_task_collection')
            ->where('task_collection_id', $taskCollection->getKey())
            ->exists()
            || DB::table('weekly_task_template_task_collection')
                ->where('task_collection_id', $taskCollection->getKey())
                ->exists();

        if ($isUsedBySchedule || $isUsedByLegacyTask || $isUsedByScopedTask) {
            throw ValidationException::withMessages([
                'collection' => 'This collection is still used by schedules or tasks. Remove those links first.',
            ]);
        }

        $taskCollection->delete();

        return to_route('admin.index');
    }
}
