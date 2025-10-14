<?php

use App\Http\Controllers\API\OCRController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http; // Bu satırı ekle
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

    Route::get('/user/premium-status', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'is_premium' => $user->is_premium,
                'subscription_type' => $user->subscription_type,
                'premium_expires_at' => $user->premium_expires_at,
                'can_access_premium_features' => $user->canAccessPremiumFeatures(),
            ],
        ]);
    });

    Route::post('/ocr/upload', [OCRController::class, 'processScreenshot']);

    // TEST ENDPOINT - geçici
    Route::get('/test-openai', function() {
        $apiKey = env('OPENAI_API_KEY');

        if (!$apiKey) {
            return response()->json(['error' => 'No API key found']);
        }

        try {
            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [
                    ['role' => 'user', 'content' => 'Hello, respond with just OK']
                ],
                'max_tokens' => 10
            ]);

            return response()->json([
                'api_key_length' => strlen($apiKey),
                'status' => $response->status(),
                'body' => $response->json(),
                'success' => $response->successful()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'api_key_length' => strlen($apiKey)
            ]);
        }
    });

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
