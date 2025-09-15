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
            'notes' => 'nullable|string|max:1000',
            'base_pay' => 'nullable|numeric|min:0',
            'tips' => 'nullable|numeric|min:0',
            'service_type' => 'nullable|in:logistics,whole_foods,fresh'
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
            'notes' => 'nullable|string|max:1000',
            'base_pay' => 'nullable|numeric|min:0',
            'tips' => 'nullable|numeric|min:0',
            'service_type' => 'nullable|in:logistics,whole_foods,fresh'
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

        // ROLLING 7-DAY WINDOW (bugün dahil, 7 gün geriye)
        $startDate = $now->copy()->subDays(6)->toDateString(); // 6 gün önce + bugün = 7 gün
        $endDate = $now->toDateString();

        $entries = WorkEntry::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        // Weekly totals (rolling 7-day)
        $totalHours = $entries->sum('hours_worked');
        $totalEarnings = $entries->sum('earnings');

        // Today entries (direct from database)
        $today = $now->toDateString();
        $todayEntries = WorkEntry::where('user_id', $userId)
            ->where('date', $today)
            ->get();
        $todayHours = $todayEntries->sum('hours_worked');
        $todayEarnings = $todayEntries->sum('earnings');

        // Constants
        $weeklyLimit = 40.0;
        $dailyLimit = 8.0;

        // TODAY AVAILABLE CALCULATION
        // Basit: Günlük limitten bugün çalışılan saati çıkar
        $todayAvailable = max(0, $dailyLimit - $todayHours);

        // TOMORROW AVAILABLE CALCULATION
        // Yarın düşecek olan entry (7 gün önceki yarın = bugünden 6 gün önce)
        $tomorrowDropDate = $now->copy()->addDay()->subDays(6)->toDateString();
        $tomorrowDropHours = WorkEntry::where('user_id', $userId)
            ->where('date', $tomorrowDropDate)
            ->sum('hours_worked');

        // Yarın için projected weekly hours
        $tomorrowProjectedWeekly = $totalHours - $tomorrowDropHours;
        $tomorrowWeeklyAvailable = max(0, $weeklyLimit - $tomorrowProjectedWeekly);
        $tomorrowAvailable = min($dailyLimit, $tomorrowWeeklyAvailable);
        // Recent activity (sadece bugünkü entries)
        $todayRecentEntries = WorkEntry::where('user_id', $userId)
            ->where('date', $today)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'date' => $entry->date,
                    'hours_worked' => round($entry->hours_worked, 2),
                    'earnings' => round($entry->earnings, 2),
                    'base_pay' => $entry->base_pay ? round($entry->base_pay, 2) : null,
                    'tips' => $entry->tips ? round($entry->tips, 2) : null,
                    'service_type' => $entry->service_type,
                    'created_at' => $entry->created_at->format('g:i A')
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'weekly' => [
                    'total_hours' => round($totalHours, 2),
                    'total_earnings' => round($totalEarnings, 2),
                    'total_miles' => round($entries->sum('miles'), 2),
                    'total_gas_cost' => round($entries->sum('gas_cost'), 2),
                    'remaining_hours' => round($weeklyLimit - $totalHours, 2),
                    'progress_percentage' => round(($totalHours / $weeklyLimit) * 100, 1),
                    'date_range' => [
                        'start' => Carbon::parse($startDate)->format('M j'),
                        'end' => $now->format('M j')
                    ]
                ],
                'today' => [
                    'hours' => round($todayHours, 2),
                    'earnings' => round($todayEarnings, 2),
                    'hourly_rate' => $todayHours > 0 ? round($todayEarnings / $todayHours, 2) : 0,
                    'available_hours' => round($todayAvailable, 2)
                ],
                'tomorrow' => [
                    'available_hours' => round($tomorrowAvailable, 2),
                    'debug' => [
                        'current_weekly' => round($totalHours, 2),
                        'tomorrow_drop_date' => $tomorrowDropDate,
                        'tomorrow_drop_hours' => round($tomorrowDropHours, 2),
                        'projected_weekly' => round($tomorrowProjectedWeekly, 2),
                        'weekly_available' => round($tomorrowWeeklyAvailable, 2)
                    ]
                ],
                'recent_entries' => $todayRecentEntries
            ]
        ]);
    }
    public function analytics(Request $request): JsonResponse
    {
        $period = $request->get('period', '30d');
        $userId = auth()->id();

        // Period'a göre gün sayısı
        switch($period) {
            case '7d':
                $days = 7;
                break;
            case '30d':
                $days = 30;
                break;
            case '3m':
                $days = 90;
                break;
            default:
                $days = 30;
        }

        $startDate = Carbon::now()->subDays($days);

        // Entries al
        $entries = WorkEntry::where('user_id', $userId)
            ->where('date', '>=', $startDate)
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'total_entries' => $entries->count(),
                'total_earnings' => $entries->sum('earnings'),
                'total_hours' => $entries->sum('hours_worked'),
                'entries_by_date' => $entries->groupBy('date'),
            ]
        ]);
    }
}
