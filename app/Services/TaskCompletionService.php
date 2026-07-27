<?php

namespace App\Services;

use App\Models\ChecklistDayStatus;
use App\Models\DailyChecklist;
use App\Models\DailyTaskEvidence;
use App\Models\WeeklyTaskEvidence;
use App\Models\WeeklyTaskOccurrence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TaskCompletionService
{
    public function __construct(
        private readonly OperationalDate $dates,
        private readonly WeeklyTaskScheduler $weekly,
        private readonly ChecklistMaterializer $materializer,
    ) {}

    /**
     * @param  list<UploadedFile>  $photos
     */
    public function completeDaily(DailyChecklist $task, string $date, array $photos): void
    {
        $this->assertWritableDate($date);
        $storedPaths = [];

        try {
            DB::transaction(function () use ($task, $date, $photos, &$storedPaths): void {
                $this->materializer->acquireTemplateSynchronizationLock();
                $this->assertAvailableDate($date);
                $locked = DailyChecklist::query()->lockForUpdate()->findOrFail($task->id);

                if (! hash_equals($locked->date->toDateString(), $date)) {
                    throw ValidationException::withMessages(['date' => 'Tugasan tidak sepadan dengan tarikh ini.']);
                }

                if ($locked->is_completed) {
                    throw ValidationException::withMessages(['task' => 'Tugasan ini telah selesai dan tidak boleh dibuka semula.']);
                }

                foreach ($photos as $photo) {
                    $stored = $this->storePhoto($photo, $date, 'daily');
                    $storedPaths[] = $stored['path'];
                    DailyTaskEvidence::query()->create([
                        'daily_checklist_id' => $locked->id,
                        ...$stored,
                    ]);
                }

                $locked->forceFill([
                    'is_completed' => true,
                    'completed_at' => $this->dates->nowUtc(),
                    'completed_by_user_id' => null,
                ])->save();
            }, 3);
        } catch (Throwable $exception) {
            $this->deleteStored($storedPaths);
            throw $exception;
        }
    }

    /**
     * @param  list<UploadedFile>  $photos
     */
    public function completeWeekly(WeeklyTaskOccurrence $occurrence, string $date, array $photos): void
    {
        $this->assertWritableDate($date);
        $today = $this->dates->fromDateString($date);
        $this->weekly->advanceThrough($today);
        $storedPaths = [];

        try {
            DB::transaction(function () use ($occurrence, $date, $photos, &$storedPaths): void {
                $this->materializer->acquireTemplateSynchronizationLock();
                $this->assertAvailableDate($date);
                $locked = WeeklyTaskOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);

                if (
                    $locked->status !== 'pending'
                    || $locked->week_start->greaterThan($this->dates->today())
                    || $locked->week_start->endOfWeek()->lessThan($this->dates->today())
                ) {
                    throw ValidationException::withMessages(['task' => 'Tugasan mingguan ini tidak boleh diselesaikan pada hari ini.']);
                }

                foreach ($photos as $photo) {
                    $stored = $this->storePhoto($photo, $date, 'weekly');
                    $storedPaths[] = $stored['path'];
                    WeeklyTaskEvidence::query()->create([
                        'weekly_task_occurrence_id' => $locked->id,
                        ...$stored,
                    ]);
                }

                $locked->forceFill([
                    'status' => 'completed',
                    'completed_at' => $this->dates->nowUtc(),
                    'completed_on' => $date,
                ])->save();
            }, 3);
        } catch (Throwable $exception) {
            $this->deleteStored($storedPaths);
            throw $exception;
        }
    }

    private function assertWritableDate(string $date): void
    {
        if (! $this->dates->isToday($date)) {
            abort(403, 'Hanya senarai semak hari ini boleh dikemas kini.');
        }

    }

    private function assertAvailableDate(string $date): void
    {
        if (ChecklistDayStatus::query()->whereDate('date', $date)->where('is_unavailable', true)->exists()) {
            throw ValidationException::withMessages(['date' => 'Hari ini ditandakan MC/tidak tersedia.']);
        }
    }

    /**
     * @return array{disk: string, path: string, mime_type: string, size_bytes: int}
     */
    private function storePhoto(UploadedFile $photo, string $date, string $type): array
    {
        $mime = (string) $photo->getMimeType();
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (! isset($extensions[$mime])) {
            throw ValidationException::withMessages(['photos' => 'Format foto bukti tidak disokong.']);
        }

        $directory = sprintf('evidence/%s/%s/%s', $date, $type, substr(bin2hex(random_bytes(8)), 0, 2));
        $filename = bin2hex(random_bytes(24)).'.'.$extensions[$mime];
        $path = Storage::disk('local')->putFileAs($directory, $photo, $filename);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Foto bukti tidak dapat disimpan.');
        }

        return [
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $mime,
            'size_bytes' => (int) $photo->getSize(),
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteStored(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk('local')->delete($paths);
        }
    }
}
