<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/dashboard'));
Route::get('/dashboard', [DashboardController::class, 'index']);

Route::get('/auth/onfly', [AuthController::class, 'redirectToOnfly'])->name('auth.onfly.redirect');
Route::get('/auth/onfly/callback', [AuthController::class, 'handleCallback'])->name('auth.onfly.callback');
