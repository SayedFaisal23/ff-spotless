<?php

namespace App\Http\Controllers;

use App\Exceptions\ChecklistDateOutsideMaterializationWindow;
use App\Http\Requests\ChecklistDateRequest;
use App\Services\ChecklistWorkflow;
use App\Services\DashboardPresenter;
use App\Services\OperationalDate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ChecklistController extends Controller
{
    public function index(
        ChecklistDateRequest $request,
        ChecklistWorkflow $workflow,
        DashboardPresenter $presenter,
        OperationalDate $dates,
    ) {
        $date = $request->selectedDate($dates);

        try {
            $checklist = $workflow->forDate($date);
        } catch (ChecklistDateOutsideMaterializationWindow) {
            throw ValidationException::withMessages([
                'date' => 'Senarai semak baharu hanya boleh dicipta dalam tempoh 365 hari dari hari ini.',
            ]);
        }

        return Inertia::render('Dashboard', $presenter->checklist($request, $date, $checklist));
    }
}
