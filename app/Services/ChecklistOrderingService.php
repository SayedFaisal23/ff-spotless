<?php

namespace App\Services;

use App\Models\ChecklistItemPosition;
use App\Models\ChecklistDayStatus;
use App\Models\DailyChecklist;
use App\Models\WeeklyTaskOccurrence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChecklistOrderingService
{
    public function __construct(
        private readonly OperationalDate $dates,
        private readonly ChecklistMaterializer $materializer,
    ) {}

    /**
     * @param  list<array{type: string, id: int}>  $items
     */
    public function reorder(string $date, int $sessionId, array $items): void
    {
        if (! $this->dates->isToday($date)) {
            abort(403, 'Hanya tugasan hari ini boleh disusun semula.');
        }

        DB::transaction(function () use ($date, $sessionId, $items): void {
            $this->materializer->acquireTemplateSynchronizationLock();

            if (ChecklistDayStatus::query()->whereDate('date', $date)->where('is_unavailable', true)->exists()) {
                throw ValidationException::withMessages(['date' => 'Hari ini ditandakan MC/tidak tersedia.']);
            }

            $expected = $this->expectedKeys($date, $sessionId)->sort()->values()->all();
            $submitted = collect($items)
                ->map(static fn (array $item): string => $item['type'].':'.(int) $item['id'])
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($expected !== $submitted || count($items) !== count($submitted)) {
                throw ValidationException::withMessages([
                    'items' => 'Susunan mesti mengandungi semua tugasan dalam sesi yang sama.',
                ]);
            }

            ChecklistItemPosition::query()
                ->whereDate('date', $date)
                ->where('task_session_id', $sessionId)
                ->delete();

            foreach ($items as $position => $item) {
                ChecklistItemPosition::query()->create([
                    'date' => $date,
                    'task_session_id' => $sessionId,
                    'item_type' => $item['type'],
                    'item_id' => (int) $item['id'],
                    'position' => $position + 1,
                ]);
            }
        }, 3);
    }

    /**
     * @return Collection<int, string>
     */
    private function expectedKeys(string $date, int $sessionId): Collection
    {
        $daily = DailyChecklist::query()
            ->whereDate('date', $date)
            ->where('task_session_id', $sessionId)
            ->pluck('id')
            ->map(static fn (int $id): string => 'daily:'.$id);

        $weekStart = $this->dates->fromDateString($date)->startOfWeek()->toDateString();
        $weekly = WeeklyTaskOccurrence::query()
            ->whereDate('week_start', $weekStart)
            ->where('task_session_id', $sessionId)
            ->where(function ($query) use ($date): void {
                $query->where('status', 'pending')->orWhereDate('completed_on', $date);
            })
            ->pluck('id')
            ->map(static fn (int $id): string => 'weekly:'.$id);

        return $daily->concat($weekly);
    }
}
