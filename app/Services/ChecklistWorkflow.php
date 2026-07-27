<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class ChecklistWorkflow
{
    public function __construct(
        private readonly ChecklistMaterializer $daily,
        private readonly WeeklyTaskScheduler $weekly,
    ) {}

    /**
     * @return array{daily: \Illuminate\Database\Eloquent\Collection, weekly: \Illuminate\Database\Eloquent\Collection}
     */
    public function forDate(CarbonImmutable $date): array
    {
        $today = app(OperationalDate::class)->today();
        $catchUpDate = $date->lessThan($today) ? $date : $today;
        $this->daily->catchUpThrough($catchUpDate);
        $this->weekly->advanceThrough($catchUpDate);

        return [
            'daily' => $this->daily->forDate($date),
            'weekly' => $this->weekly->forChecklistDate($date),
        ];
    }
}
