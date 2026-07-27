<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderChecklistItemsRequest;
use App\Services\ChecklistOrderingService;

class ChecklistOrderController extends Controller
{
    public function store(ReorderChecklistItemsRequest $request, ChecklistOrderingService $service)
    {
        $data = $request->validated();

        $service->reorder($data['date'], (int) $data['task_session_id'], $data['items']);

        return to_route('checklist.index', ['date' => $data['date']]);
    }
}
