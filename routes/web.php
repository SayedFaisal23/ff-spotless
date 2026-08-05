<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminSessionController;
use App\Http\Controllers\AdminTaskReopenController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistOrderController;
use App\Http\Controllers\DayAvailabilityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\TaskCompletionController;
use App\Http\Controllers\TaskCollectionController;
use App\Http\Controllers\TaskCollectionScheduleController;
use App\Http\Controllers\TaskSessionManagementController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\WeeklyTaskTemplateController;
use App\Http\Middleware\EnsureMasterAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('home');

// Cleaner access is intentionally anonymous. Write safety remains enforced by
// CSRF protection, request validation, throttling, and server-side date checks.
Route::get('/checklist', [ChecklistController::class, 'index'])->name('checklist.index');
Route::post('/tasks/daily/{dailyChecklist}/complete', [TaskCompletionController::class, 'daily'])
    ->middleware('throttle:10,1')
    ->name('tasks.daily.complete');
Route::post('/tasks/weekly/{weeklyTaskOccurrence}/complete', [TaskCompletionController::class, 'weekly'])
    ->middleware('throttle:10,1')
    ->name('tasks.weekly.complete');
Route::post('/checklist/availability', [DayAvailabilityController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('checklist.availability');
Route::post('/checklist/order', [ChecklistOrderController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('checklist.order');

Route::post('/admin/login', [AdminSessionController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('admin.login');

Route::middleware(EnsureMasterAdmin::class)->prefix('admin')->name('admin.')->group(function (): void {
    Route::post('/logout', [AdminSessionController::class, 'destroy'])->name('logout');
    Route::get('/', [AdminDashboardController::class, 'index'])->name('index');
    Route::post('/templates', [TaskTemplateController::class, 'store'])->name('templates.store');
    Route::patch('/templates/{taskTemplate}', [TaskTemplateController::class, 'update'])->name('templates.update');
    Route::delete('/templates/{taskTemplate}', [TaskTemplateController::class, 'destroy'])->name('templates.destroy');
    Route::post('/collections', [TaskCollectionController::class, 'store'])->name('collections.store');
    Route::delete('/collections/{taskCollection}', [TaskCollectionController::class, 'destroy'])->name('collections.destroy');
    Route::post('/collection-schedules', [TaskCollectionScheduleController::class, 'store'])->name('collection-schedules.store');
    Route::delete('/collection-schedules/{taskCollectionSchedule}', [TaskCollectionScheduleController::class, 'destroy'])->name('collection-schedules.destroy');
    Route::post('/weekly-templates', [WeeklyTaskTemplateController::class, 'store'])->name('weekly-templates.store');
    Route::patch('/weekly-templates/{weeklyTaskTemplate}', [WeeklyTaskTemplateController::class, 'update'])->name('weekly-templates.update');
    Route::delete('/weekly-templates/{weeklyTaskTemplate}', [WeeklyTaskTemplateController::class, 'destroy'])->name('weekly-templates.destroy');
    Route::post('/sessions', [TaskSessionManagementController::class, 'store'])->name('sessions.store');
    Route::patch('/sessions/reorder', [TaskSessionManagementController::class, 'reorder'])->name('sessions.reorder');
    Route::patch('/sessions/{taskSession}', [TaskSessionManagementController::class, 'update'])->name('sessions.update');
    Route::delete('/sessions/{taskSession}', [TaskSessionManagementController::class, 'destroy'])->name('sessions.destroy');
    Route::get('/evidence/daily/{evidence}', [EvidenceController::class, 'daily'])->name('evidence.daily');
    Route::get('/evidence/weekly/{evidence}', [EvidenceController::class, 'weekly'])->name('evidence.weekly');
    Route::patch('/tasks/daily/{dailyChecklist}/reopen', [AdminTaskReopenController::class, 'daily'])->name('tasks.daily.reopen');
    Route::patch('/tasks/weekly/{weeklyTaskOccurrence}/reopen', [AdminTaskReopenController::class, 'weekly'])->name('tasks.weekly.reopen');
});
