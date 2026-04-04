<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/dashboard');

Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

Route::get('/users',                          [AdminUserController::class, 'index'])        ->name('users.index');
Route::get('/users/{user}',                   [AdminUserController::class, 'show'])         ->name('users.show');
Route::patch('/users/{user}/toggle-admin',    [AdminUserController::class, 'toggleAdmin'])  ->name('users.toggle-admin');
Route::patch('/users/{user}/toggle-active',   [AdminUserController::class, 'toggleActive']) ->name('users.toggle-active');
Route::delete('/users/{user}',                [AdminUserController::class, 'destroy'])      ->name('users.destroy');
