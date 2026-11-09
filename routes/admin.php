<?php

use App\Http\Controllers\Admin\AdminAiLogController;
use App\Http\Controllers\Admin\AdminActivityController;
use App\Http\Controllers\Admin\AdminCoachController;
use App\Http\Controllers\Admin\AdminCorosProbeController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminImpersonationController;
use App\Http\Controllers\Admin\AdminContactMessageController;
use App\Http\Controllers\Admin\AdminInvitationController;
use App\Http\Controllers\Admin\AdminNewsletterController;
use App\Http\Controllers\Admin\AdminOnboardingTextController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminStrengthExerciseController;
use App\Http\Controllers\Admin\AdminSupportController;
use App\Http\Controllers\Admin\AdminSystemController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWikiController;
use App\Http\Controllers\Admin\AdminWorkoutController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/dashboard');

Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

Route::get('/users',                               [AdminUserController::class, 'index'])              ->name('users.index');
Route::get('/users/{user}',                        [AdminUserController::class, 'show'])               ->name('users.show');
Route::patch('/users/{user}/toggle-admin',         [AdminUserController::class, 'toggleAdmin'])        ->name('users.toggle-admin');
Route::patch('/users/{user}/toggle-active',        [AdminUserController::class, 'toggleActive'])       ->name('users.toggle-active');
Route::patch('/users/{user}/toggle-strava',        [AdminUserController::class, 'toggleStrava'])       ->name('users.toggle-strava');
Route::post('/users/{user}/reset-recommendation',    [AdminUserController::class, 'resetRecommendation'])   ->name('users.reset-recommendation');
Route::post('/users/{user}/trigger-weekly-review',  [AdminUserController::class, 'triggerWeeklyReview'])  ->name('users.trigger-weekly-review');
Route::post('/users/{user}/recalculate-threshold',  [AdminUserController::class, 'recalculateThreshold']) ->name('users.recalculate-threshold');
Route::post('/users/{user}/reset-password',         [AdminUserController::class, 'resetPassword'])        ->name('users.reset-password');
Route::patch('/users/{user}/ai-limit',              [AdminUserController::class, 'updateAiLimit'])         ->name('users.ai-limit');
Route::delete('/users/{user}',                     [AdminUserController::class, 'destroy'])             ->name('users.destroy');
// Als Athlet anmelden. Das BEENDEN liegt in web.php — waehrend der
// Uebernahme ist man kein Admin mehr und kaeme hier nicht mehr durch.
Route::post('/users/{user}/impersonate',           [AdminImpersonationController::class, 'start'])      ->name('users.impersonate');

// Einladungen — Registrieren geht nur mit einem gueltigen Link.
Route::get('/invitations',                         [AdminInvitationController::class, 'index'])  ->name('invitations.index');
Route::post('/invitations',                        [AdminInvitationController::class, 'store'])  ->name('invitations.store');
Route::post('/invitations/mode',                   [AdminInvitationController::class, 'mode'])   ->name('invitations.mode');
Route::post('/waitlist/{user}/approve',            [AdminInvitationController::class, 'approve'])->name('waitlist.approve');
Route::delete('/waitlist/{user}',                  [AdminInvitationController::class, 'reject']) ->name('waitlist.reject');
Route::post('/invitations/{invitation}/revoke',    [AdminInvitationController::class, 'revoke']) ->name('invitations.revoke');

// Aktivitaeten ueber alle Athleten — inklusive Loeschen, das es im
// Admin-Bereich bis hierher nicht gab.
// Geteilte Workouts — wer hat was freigegeben. Ein Admin kann eine
// Freigabe zuruecknehmen, aber kein fremdes Workout aendern.
Route::get('/workouts',                            [AdminWorkoutController::class, 'index'])     ->name('workouts.index');
Route::post('/workouts/{workout}/unpublish',       [AdminWorkoutController::class, 'unpublish']) ->name('workouts.unpublish');

// Kraft-Katalog — Übungen mit Bild aus wger. Nie löschen, nur abschalten.
Route::get('/onboarding-texts',   [AdminOnboardingTextController::class, 'index'])  ->name('onboarding-texts.index');
Route::put('/onboarding-texts',   [AdminOnboardingTextController::class, 'update']) ->name('onboarding-texts.update');

Route::get('/strength-exercises',                         [AdminStrengthExerciseController::class, 'index'])        ->name('strength-exercises.index');
Route::post('/strength-exercises',                        [AdminStrengthExerciseController::class, 'store'])        ->name('strength-exercises.store');
Route::get('/strength-exercises/wger',                    [AdminStrengthExerciseController::class, 'wgerSearch'])   ->name('strength-exercises.wger');
Route::patch('/strength-exercises/{exercise}',            [AdminStrengthExerciseController::class, 'update'])       ->name('strength-exercises.update');
Route::patch('/strength-exercises/{exercise}/toggle',     [AdminStrengthExerciseController::class, 'toggle'])       ->name('strength-exercises.toggle');
Route::post('/strength-exercises/{exercise}/image',       [AdminStrengthExerciseController::class, 'refreshImage']) ->name('strength-exercises.image');
Route::delete('/strength-exercises/{exercise}/image',     [AdminStrengthExerciseController::class, 'removeImage'])  ->name('strength-exercises.image.destroy');

Route::get('/activities',                          [AdminActivityController::class, 'index'])           ->name('activities.index');
Route::delete('/activities/{activity}',            [AdminActivityController::class, 'destroy'])         ->name('activities.destroy');

// Systemstatus — Queue, fehlgeschlagene Aufgaben, Plan-Luecken, Anbindungen.
Route::get('/coros-probe',                    [AdminCorosProbeController::class, 'index'])     ->name('coros-probe.index');
Route::get('/coros-probe/{file}',             [AdminCorosProbeController::class, 'show'])      ->name('coros-probe.show');
Route::get('/system',                         [AdminSystemController::class, 'index'])         ->name('system.index');
Route::get('/system/logs',                    [AdminSystemController::class, 'logs'])          ->name('system.logs');
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

// Kontaktformular aus dem Impressum
Route::get('/contact',                        [AdminContactMessageController::class, 'index'])  ->name('contact.index');
Route::patch('/contact/{contact}/handled',    [AdminContactMessageController::class, 'handle']) ->name('contact.handle');
Route::delete('/contact/{contact}',           [AdminContactMessageController::class, 'destroy'])->name('contact.destroy');

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
