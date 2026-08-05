<?php

namespace App\Services;

use App\Models\TaskCollection;
use App\Models\TaskCollectionSchedule;
use Carbon\CarbonImmutable;
use LogicException;

class TaskCollectionResolver
{
    /**
     * @var array<string, TaskCollection>
     */
    private array $activeByDate = [];

    private ?TaskCollection $defaultCollection = null;

    public function default(): TaskCollection
    {
        if ($this->defaultCollection instanceof TaskCollection) {
            return $this->defaultCollection;
        }

        $collection = TaskCollection::query()
            ->where('is_default', true)
            ->first();

        if (! $collection instanceof TaskCollection) {
            throw new LogicException('The default task collection is missing.');
        }

        return $this->defaultCollection = $collection;
    }

    public function forDate(CarbonImmutable $date): TaskCollection
    {
        $dateString = $date->toDateString();

        if (isset($this->activeByDate[$dateString])) {
            return $this->activeByDate[$dateString];
        }

        $schedule = TaskCollectionSchedule::query()
            ->with('taskCollection')
            ->whereDate('starts_on', '<=', $dateString)
            ->whereDate('ends_on', '>=', $dateString)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->first();

        return $this->activeByDate[$dateString] = $schedule?->taskCollection ?? $this->default();
    }
}
