<?php

use App\Http\Controllers\Admin\AdminAiLogController;
use App\Http\Controllers\Admin\AdminCoachController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminNewsletterController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminSupportController;
use App\Http\Controllers\Admin\AdminSystemController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWikiController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/dashboard');

Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

Route::get('/users',                               [AdminUserController::class, 'index'])              ->name('users.index');
Route::get('/users/{user}',                        [AdminUserController::class, 'show'])               ->name('users.show');
Route::patch('/users/{user}/toggle-admin',         [AdminUserController::class, 'toggleAdmin'])        ->name('users.toggle-admin');
Route::patch('/users/{user}/toggle-active',        [AdminUserController::class, 'toggleActive'])       ->name('users.toggle-active');
Route::post('/users/{user}/reset-recommendation',    [AdminUserController::class, 'resetRecommendation'])   ->name('users.reset-recommendation');
Route::post('/users/{user}/trigger-weekly-review',  [AdminUserController::class, 'triggerWeeklyReview'])  ->name('users.trigger-weekly-review');
Route::post('/users/{user}/recalculate-threshold',  [AdminUserController::class, 'recalculateThreshold']) ->name('users.recalculate-threshold');
Route::post('/users/{user}/reset-password',         [AdminUserController::class, 'resetPassword'])        ->name('users.reset-password');
Route::patch('/users/{user}/ai-limit',              [AdminUserController::class, 'updateAiLimit'])         ->name('users.ai-limit');
Route::delete('/users/{user}',                     [AdminUserController::class, 'destroy'])             ->name('users.destroy');

// Systemstatus — Queue, fehlgeschlagene Aufgaben, Plan-Luecken, Anbindungen.
Route::get('/system',                         [AdminSystemController::class, 'index'])         ->name('system.index');
Route::post('/system/failed/retry-all',       [AdminSystemController::class, 'retryAllFailed'])->name('system.failed.retry-all');
Route::delete('/system/failed',               [AdminSystemController::class, 'flushFailed'])   ->name('system.failed.flush');
Route::post('/system/failed/{uuid}/retry',    [AdminSystemController::class, 'retryFailed'])   ->name('system.failed.retry');
Route::delete('/system/failed/{uuid}',        [AdminSystemController::class, 'forgetFailed'])  ->name('system.failed.forget');
Route::post('/system/plan-gaps/{user}',       [AdminSystemController::class, 'fillPlanGaps'])  ->name('system.plan-gaps.fill');

Route::get('/ai-logs',           [AdminAiLogController::class, 'index']) ->name('ai-logs.index');
Route::get('/ai-logs/{aiLog}',   [AdminAiLogController::class, 'show'])  ->name('ai-logs.show');

Route::get('/coaches',           [AdminCoachController::class, 'index'])  ->name('coaches.index');
Route::get('/coaches/{coach}',   [AdminCoachController::class, 'show'])   ->name('coaches.show');
Route::put('/coaches/{coach}',   [AdminCoachController::class, 'update']) ->name('coaches.update');

Route::get('/settings',                    [AdminSettingsController::class, 'index'])          ->name('settings.index');
Route::post('/settings/test-push',         [AdminSettingsController::class, 'sendTestPush'])   ->name('settings.test-push');
Route::post('/settings/maintenance',       [AdminSettingsController::class, 'toggleMaintenance'])->name('settings.maintenance');
Route::post('/settings/broadcast-push',    [AdminSettingsController::class, 'broadcastPush'])  ->name('settings.broadcast-push');

// Support Tickets
Route::get('/support',                     [AdminSupportController::class, 'index'])       ->name('support.index');
Route::get('/support/{ticket}',            [AdminSupportController::class, 'show'])        ->name('support.show');
Route::post('/support/{ticket}/reply',     [AdminSupportController::class, 'reply'])       ->name('support.reply');
Route::patch('/support/{ticket}/status',   [AdminSupportController::class, 'updateStatus'])->name('support.status');

// Newsletter
Route::get('/newsletter',                           [AdminNewsletterController::class, 'index'])  ->name('newsletter.index');
Route::post('/newsletter',                          [AdminNewsletterController::class, 'store'])  ->name('newsletter.store');
Route::put('/newsletter/{newsletter}',              [AdminNewsletterController::class, 'update']) ->name('newsletter.update');
Route::post('/newsletter/{newsletter}/send',        [AdminNewsletterController::class, 'send'])   ->name('newsletter.send');
Route::delete('/newsletter/{newsletter}',           [AdminNewsletterController::class, 'destroy'])->name('newsletter.destroy');

// Wiki
Route::get('/wiki',                  [AdminWikiController::class, 'index'])   ->name('wiki.index');
Route::get('/wiki/changelog',        [AdminWikiController::class, 'changelog'])->name('wiki.changelog');
Route::post('/wiki',                 [AdminWikiController::class, 'store'])   ->name('wiki.store');
Route::get('/wiki/{page}',           [AdminWikiController::class, 'page'])    ->name('wiki.page');
Route::put('/wiki/{page}',           [AdminWikiController::class, 'update'])  ->name('wiki.update');
Route::delete('/wiki/{page}',        [AdminWikiController::class, 'destroy']) ->name('wiki.destroy');
