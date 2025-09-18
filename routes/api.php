<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\WorkEntryController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Analytics
    Route::get('/analytics/stats', [WorkEntryController::class, 'stats']);
    Route::get('/analytics/weekly', [WorkEntryController::class, 'weeklyStats']);

    // Work Entries - SPECIFIC ROUTES ÖNCE
    Route::get('/work-entries/years', [WorkEntryController::class, 'getAvailableYears']);
    Route::get('/work-entries/monthly/{year}', [WorkEntryController::class, 'getMonthlyData']);
    Route::get('/work-entries/daily/{year}/{month}', [WorkEntryController::class, 'getDailyEntries']);
    Route::get('/work-entries/search', [WorkEntryController::class, 'searchEntries']);

    // Work Entries - GENERIC RESOURCE SONRA
    Route::apiResource('work-entries', WorkEntryController::class);
});
