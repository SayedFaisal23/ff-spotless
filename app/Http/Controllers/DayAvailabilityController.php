<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDayAvailabilityRequest;
use App\Models\ChecklistDayStatus;
use App\Models\DailyChecklist;
use App\Models\WeeklyTaskOccurrence;
use App\Models\WeeklyTaskPostponement;
use App\Services\ChecklistMaterializer;
use App\Services\OperationalDate;
use App\Services\WeeklyTaskScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DayAvailabilityController extends Controller
{
    public function store(
        UpdateDayAvailabilityRequest $request,
        OperationalDate $dates,
        WeeklyTaskScheduler $scheduler,
        ChecklistMaterializer $materializer,
    )
    {
        $data = $request->validated();

        if (! $dates->isToday($data['date'])) {
            abort(403, 'Hanya status hari ini boleh dikemas kini.');
        }

        $isUnavailable = in_array($data['is_unavailable'], [true, 1, '1'], true);

        DB::transaction(function () use ($data, $isUnavailable, $materializer): void {
            $materializer->acquireTemplateSynchronizationLock();

            if ($isUnavailable) {
                $hasCompletion = DailyChecklist::query()
                    ->whereDate('date', $data['date'])
                    ->where('is_completed', true)
                    ->exists()
                    || WeeklyTaskOccurrence::query()
                        ->whereDate('completed_on', $data['date'])
                        ->where('status', 'completed')
                        ->exists();

                if ($hasCompletion) {
                    throw ValidationException::withMessages([
                        'is_unavailable' => 'Hari yang mempunyai tugasan selesai tidak boleh ditandakan MC/tidak tersedia.',
                    ]);
                }
            }

            $dayStatus = ChecklistDayStatus::query()
                ->whereDate('date', $data['date'])
                ->lockForUpdate()
                ->first();
            $dayStatus ??= new ChecklistDayStatus(['date' => $data['date']]);
            $dayStatus->is_unavailable = $isUnavailable;
            $dayStatus->save();

            if (! $isUnavailable) {
                WeeklyTaskPostponement::query()
                    ->whereDate('from_date', $data['date'])
                    ->where('reason', 'unavailable')
                    ->with('occurrence')
                    ->get()
                    ->each(function (WeeklyTaskPostponement $postponement): void {
                        $occurrence = $postponement->occurrence;

                        if (
                            $occurrence->status === 'pending'
                            && $occurrence->scheduled_date->isSameDay($postponement->to_date)
                        ) {
                            $occurrence->forceFill(['scheduled_date' => $postponement->from_date])->save();
                            $postponement->delete();
                        }
                    });

                WeeklyTaskOccurrence::query()
                    ->whereDate('scheduled_date', $data['date'])
                    ->where('status', 'missed')
                    ->where('missed_reason', 'unavailable')
                    ->update(['status' => 'pending', 'missed_reason' => null]);
            }
        }, 3);

        $scheduler->advanceThrough($dates->today());

        return to_route('checklist.index', ['date' => $data['date']]);
    }
}
