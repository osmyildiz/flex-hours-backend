<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WorkEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class WorkEntryController extends Controller
{
    public function index(): JsonResponse
    {
        $entries = Auth::user()->workEntries()
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $entries
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'hours_worked' => 'required|numeric|min:0|max:24',
            'total_hours' => 'nullable|numeric|min:0|max:24',
            'earnings' => 'required|numeric|min:0',
            'miles' => 'nullable|numeric|min:0',
            'gas_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000'
        ]);

        $entry = Auth::user()->workEntries()->create($validated);

        return response()->json([
            'success' => true,
            'data' => $entry
        ], 201);
    }

    public function show(WorkEntry $workEntry): JsonResponse
    {
        // Check if entry belongs to authenticated user
        if ($workEntry->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $workEntry
        ]);
    }

    public function update(Request $request, WorkEntry $workEntry): JsonResponse
    {
        // Check if entry belongs to authenticated user
        if ($workEntry->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'date' => 'sometimes|required|date',
            'hours_worked' => 'sometimes|required|numeric|min:0|max:24',
            'total_hours' => 'nullable|numeric|min:0|max:24',
            'earnings' => 'sometimes|required|numeric|min:0',
            'miles' => 'nullable|numeric|min:0',
            'gas_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000'
        ]);

        $workEntry->update($validated);

        return response()->json([
            'success' => true,
            'data' => $workEntry
        ]);
    }

    public function destroy(WorkEntry $workEntry): JsonResponse
    {
        // Check if entry belongs to authenticated user
        if ($workEntry->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $workEntry->delete();

        return response()->json([
            'success' => true,
            'message' => 'Work entry deleted successfully'
        ]);
    }

    public function weeklyStats(): JsonResponse
    {
        $user = Auth::user();
        $userTimezone = $user->timezone ?? 'UTC';

        $now = Carbon::now($userTimezone);
        $userId = $user->id;

        // Rolling 7-day calculation
        $entries = WorkEntry::where('user_id', $userId)
            ->where('date', '>=', $now->copy()->subDays(6))
            ->where('date', '<=', $now)
            ->get();

        // Weekly totals
        $totalHours = $entries->sum('hours_worked');
        $totalEarnings = $entries->sum('earnings');

        // Today entries
        $today = $now->toDateString();
        $todayEntries = $entries->filter(function($entry) use ($today) {
            return Carbon::parse($entry->date)->toDateString() === $today;
        });

        $todayHours = $todayEntries->sum('hours_worked');
        $todayEarnings = $todayEntries->sum('earnings');

        // Weekly limits
        $weeklyLimit = 40.0;
        $dailyLimit = 8.0;
        $remainingWeekly = max(0, $weeklyLimit - $totalHours);

        // ROLLING WINDOW CALCULATION
        $sevenDaysAgo = $now->copy()->subDays(7)->toDateString();
        $expiredHours = WorkEntry::where('user_id', $userId)
            ->where('date', $sevenDaysAgo)
            ->sum('hours_worked');

        // TODAY AVAILABLE CALCULATION
        if ($expiredHours > 0) {
            // Rolling window logic
            $todayAvailable = max(0, min($dailyLimit, $expiredHours - $todayHours));
        } else {
            // Fallback: New user, today quota = remaining today hours
            $todayAvailable = max(0, $dailyLimit - $todayHours); // 8 - 3.6 = 4.4h
        }

        // TOMORROW AVAILABLE CALCULATION
        $sixDaysAgo = $now->copy()->subDays(6)->toDateString();
        $tomorrowExpiredHours = WorkEntry::where('user_id', $userId)
            ->where('date', $sixDaysAgo)
            ->sum('hours_worked');

        $tomorrowExpiredHours = WorkEntry::where('user_id', $userId)
            ->where('date', $sixDaysAgo)
            ->sum('hours_worked');

        $remainingWeeklyAfterToday = $remainingWeekly - $todayHours;
        $tomorrowAvailable = max(0, min($dailyLimit, $remainingWeeklyAfterToday + $tomorrowExpiredHours));

        return response()->json([
            'success' => true,
            'data' => [
                'weekly' => [
                    'total_hours' => round($totalHours, 2),
                    'total_earnings' => round($totalEarnings, 2),
                    'total_miles' => round($entries->sum('miles'), 2),
                    'total_gas_cost' => round($entries->sum('gas_cost'), 2),
                    'remaining_hours' => round($remainingWeekly, 2),
                    'progress_percentage' => round(($totalHours / $weeklyLimit) * 100, 1)
                ],
                'today' => [
                    'hours' => round($todayHours, 2),
                    'earnings' => round($todayEarnings, 2),
                    'hourly_rate' => $todayHours > 0 ? round($todayEarnings / $todayHours, 2) : 0,
                    'available_hours' => round($todayAvailable, 2)
                ],
                'tomorrow' => [
                    'available_hours' => round($tomorrowAvailable, 2)
                ]
            ]
        ]);
    }
}
