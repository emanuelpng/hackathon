<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/dashboard'));
Route::get('/dashboard', [DashboardController::class, 'index']);

Route::get('/auth/tokens', [AuthController::class, 'showTokenForm'])->name('auth.tokens.form');
Route::post('/auth/tokens', [AuthController::class, 'storeTokens'])->name('auth.tokens.store');
