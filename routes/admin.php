<?php

use App\Http\Controllers\Admin\AdminAiLogController;
use App\Http\Controllers\Admin\AdminCoachController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminSettingsController;
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
Route::delete('/users/{user}',                     [AdminUserController::class, 'destroy'])             ->name('users.destroy');

Route::get('/ai-logs',           [AdminAiLogController::class, 'index']) ->name('ai-logs.index');
Route::get('/ai-logs/{aiLog}',   [AdminAiLogController::class, 'show'])  ->name('ai-logs.show');

Route::get('/coaches',           [AdminCoachController::class, 'index'])  ->name('coaches.index');
Route::get('/coaches/{coach}',   [AdminCoachController::class, 'show'])   ->name('coaches.show');
Route::put('/coaches/{coach}',   [AdminCoachController::class, 'update']) ->name('coaches.update');

Route::get('/settings',              [AdminSettingsController::class, 'index'])       ->name('settings.index');
Route::post('/settings/test-push',   [AdminSettingsController::class, 'sendTestPush'])->name('settings.test-push');

// Wiki
Route::get('/wiki',                  [AdminWikiController::class, 'index'])   ->name('wiki.index');
Route::get('/wiki/changelog',        [AdminWikiController::class, 'changelog'])->name('wiki.changelog');
Route::post('/wiki',                 [AdminWikiController::class, 'store'])   ->name('wiki.store');
Route::get('/wiki/{page}',           [AdminWikiController::class, 'page'])    ->name('wiki.page');
Route::put('/wiki/{page}',           [AdminWikiController::class, 'update'])  ->name('wiki.update');
Route::delete('/wiki/{page}',        [AdminWikiController::class, 'destroy']) ->name('wiki.destroy');
