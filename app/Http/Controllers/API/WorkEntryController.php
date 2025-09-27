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
    /**
     * Get user's timezone from request or default to UTC
     */
    private function getUserTimezone(Request $request): string
    {
        return $request->header('X-User-Timezone', 'UTC');
    }

    /**
     * Get "today" date in user's timezone
     */
    private function getUserToday(Request $request): string
    {
        $userTimezone = $this->getUserTimezone($request);
        return Carbon::now($userTimezone)->format('Y-m-d');
    }

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

    public function weeklyStats(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $userTimezone = $this->getUserTimezone($request);
        $now = Carbon::now($userTimezone);
        $today = $now->format('Y-m-d');

        // En son entry'den 7 gün geriye git
        $latestEntry = WorkEntry::where('user_id', $userId)->orderBy('date', 'desc')->first();

        if (!$latestEntry) {
            // Default values if no entries
            $totalHours = 0;
            $totalEarnings = 0;
            $startDate = $today;
            $endDate = $today;
        } else {
            $latestDate = Carbon::parse($latestEntry->date);
            $endDate = $latestDate->format('Y-m-d');
            $startDate = $latestDate->copy()->subDays(6)->format('Y-m-d');

            $entries = WorkEntry::where('user_id', $userId)
                ->whereBetween('date', [$startDate, $endDate])
                ->get();

            $totalHours = $entries->sum('hours_worked');
            $totalEarnings = $entries->sum('earnings');
        }

        // Today entries
        $todayEntries = WorkEntry::where('user_id', $userId)
            ->where('date', $today)
            ->get();
        $todayHours = $todayEntries->sum('hours_worked');
        $todayEarnings = $todayEntries->sum('earnings');

        $weeklyLimit = 40.0;
        $dailyLimit = 8.0;

        return response()->json([
            'success' => true,
            'data' => [
                'weekly' => [
                    'total_hours' => round($totalHours, 2),
                    'total_earnings' => round($totalEarnings, 2),
                    'remaining_hours' => round($weeklyLimit - $totalHours, 2),
                    'progress_percentage' => round(($totalHours / $weeklyLimit) * 100, 1),
                    'date_range' => [
                        'start' => Carbon::parse($startDate)->format('M j'),
                        'end' => Carbon::parse($endDate)->format('M j')
                    ]
                ],
                'today' => [
                    'hours' => round($todayHours, 2),
                    'earnings' => round($todayEarnings, 2),
                    'hourly_rate' => $todayHours > 0 ? round($todayEarnings / $todayHours, 2) : 0,
                    'available_hours' => round(max(0, $dailyLimit - $todayHours), 2)
                ],
                'tomorrow' => [
                    'available_hours' => 8.0
                ],
                'recent_entries' => []
            ]
        ]);
    }
    public function stats(Request $request): JsonResponse
    {
        $period = $request->get('period', '30d');
        $userId = auth()->id();
        $userTimezone = $this->getUserTimezone($request);

        // Period based on user timezone
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

        $startDate = Carbon::now($userTimezone)->subDays($days)->format('Y-m-d');

        $entries = WorkEntry::where('user_id', $userId)
            ->where('date', '>=', $startDate)
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'user_timezone' => $userTimezone,
                'start_date' => $startDate,
                'total_entries' => $entries->count(),
                'total_earnings' => $entries->sum('earnings'),
                'total_hours' => $entries->sum('hours_worked'),
            ]
        ]);
    }

    public function getAvailableYears(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $years = WorkEntry::where('user_id', $userId)
            ->selectRaw('DISTINCT YEAR(date) as year')
            ->orderBy('year', 'desc')
            ->pluck('year')
            ->toArray();

        if (empty($years)) {
            $userTimezone = $this->getUserTimezone($request);
            $years = [Carbon::now($userTimezone)->year];
        }

        return response()->json([
            'success' => true,
            'data' => $years,
        ]);
    }

    public function getMonthlyData(Request $request, $year): JsonResponse
    {
        $userId = auth()->id();

        $entries = WorkEntry::where('user_id', $userId)
            ->whereYear('date', $year)
            ->orderBy('date', 'desc')
            ->get();

        $monthlyData = [];

        for ($month = 1; $month <= 12; $month++) {
            $monthEntries = $entries->filter(function ($entry) use ($month) {
                return Carbon::parse($entry->date)->month == $month;
            });

            $totalEarnings = $monthEntries->sum('earnings');
            $totalHours = $monthEntries->sum('hours_worked');
            $totalEntries = $monthEntries->count();

            $monthlyData[] = [
                'month' => $month,
                'month_name' => Carbon::create()->month($month)->format('M'),
                'total_earnings' => round($totalEarnings, 2),
                'total_hours' => round($totalHours, 2),
                'total_entries' => $totalEntries,
                'avg_hourly_rate' => $totalHours > 0 ? round($totalEarnings / $totalHours, 2) : 0,
                'has_entries' => $totalEntries > 0,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'months' => $monthlyData,
                'yearly_totals' => [
                    'total_earnings' => round($entries->sum('earnings'), 2),
                    'total_hours' => round($entries->sum('hours_worked'), 2),
                    'total_entries' => $entries->count(),
                ]
            ],
        ]);
    }

    public function getDailyEntries(Request $request, $year, $month): JsonResponse
    {
        $request->validate([
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
        ]);

        $userId = auth()->id();
        $perPage = $request->get('per_page', 30);
        $page = $request->get('page', 1);
        $userTimezone = $this->getUserTimezone($request);

        $entries = WorkEntry::where('user_id', $userId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->orderBy('date', 'desc')
            ->get();

        $groupedEntries = $entries->groupBy(function ($entry) {
            return Carbon::parse($entry->date)->format('Y-m-d');
        });

        $formattedGroups = $groupedEntries->map(function ($dateEntries, $date) use ($userTimezone) {
            return [
                'date' => $date,
                'formatted_date' => Carbon::parse($date)->format('M j, Y'),
                'day_name' => Carbon::parse($date)->format('l'),
                'total_earnings' => round($dateEntries->sum('earnings'), 2),
                'total_hours' => round($dateEntries->sum('hours_worked'), 2),
                'entry_count' => $dateEntries->count(),
                'entries' => $dateEntries->map(function ($entry) use ($userTimezone) {
                    return [
                        'id' => $entry->id,
                        'hours_worked' => $entry->hours_worked,
                        'earnings' => $entry->earnings,
                        'base_pay' => $entry->base_pay ?? 0,
                        'tips' => $entry->tips ?? 0,
                        'notes' => $entry->notes ?? '',
                        'created_at' => Carbon::parse($entry->created_at)->setTimezone($userTimezone),
                    ];
                }),
            ];
        })->values();

        $total = $formattedGroups->count();
        $offset = ($page - 1) * $perPage;
        $paginatedGroups = $formattedGroups->slice($offset, $perPage)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'daily_groups' => $paginatedGroups,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'has_more_pages' => ($offset + $perPage) < $total,
                ],
                'month_summary' => [
                    'year' => $year,
                    'month' => $month,
                    'month_name' => Carbon::create()->month($month)->format('F Y'),
                    'total_entries' => $entries->count(),
                    'total_earnings' => round($entries->sum('earnings'), 2),
                    'total_hours' => round($entries->sum('hours_worked'), 2),
                ],
            ],
        ]);
    }

    public function searchEntries(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'string|nullable',
            'start_date' => 'date|nullable',
            'end_date' => 'date|nullable',
            'min_earnings' => 'numeric|nullable',
            'max_earnings' => 'numeric|nullable',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
        ]);

        $userId = auth()->id();
        $perPage = $request->get('per_page', 30);
        $page = $request->get('page', 1);

        $query = WorkEntry::where('user_id', $userId);

        if ($request->start_date) {
            $query->where('date', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->where('date', '<=', $request->end_date);
        }
        if ($request->min_earnings) {
            $query->where('earnings', '>=', $request->min_earnings);
        }
        if ($request->max_earnings) {
            $query->where('earnings', '<=', $request->max_earnings);
        }
        if ($request->search) {
            $query->where('notes', 'LIKE', '%' . $request->search . '%');
        }

        $entries = $query->orderBy('date', 'desc')->get();

        $total = $entries->count();
        $offset = ($page - 1) * $perPage;
        $paginatedEntries = $entries->slice($offset, $perPage)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'entries' => $paginatedEntries,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'has_more_pages' => ($offset + $perPage) < $total,
                ],
                'filters_applied' => [
                    'search' => $request->search,
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date,
                    'min_earnings' => $request->min_earnings,
                    'max_earnings' => $request->max_earnings,
                ],
                'summary' => [
                    'total_earnings' => round($entries->sum('earnings'), 2),
                    'total_hours' => round($entries->sum('hours_worked'), 2),
                    'total_entries' => $entries->count(),
                ],
            ],
        ]);
    }
}
