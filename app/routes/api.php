<?php

use App\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

Route::post('/agent/evaluate', [AgentController::class, 'evaluate']);
Route::get('/agent/evaluations', [AgentController::class, 'evaluations']);
Route::get('/agent/api-logs', [AgentController::class, 'apiLogs']);
