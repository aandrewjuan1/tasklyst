<?php

namespace App\Http\Controllers;

use App\Services\UserAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, UserAnalyticsService $userAnalytics): View
    {
        $user = $request->user();
        $timezone = (string) config('app.timezone');

        $periodEnd = CarbonImmutable::now($timezone);
        $periodStart = $periodEnd->subDays(29);

        $overview = $userAnalytics->overview($user, $periodStart, $periodEnd);

        $labels = [];
        $values = [];
        for ($day = $overview->periodStart; $day->lte($overview->periodEnd); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $labels[] = $day->format('M j');
            $values[] = $overview->tasksCompletedByDay[$key] ?? 0;
        }

        return view('dashboard', [
            'analyticsChart' => [
                'labels' => $labels,
                'values' => $values,
            ],
            'analyticsTotals' => [
                'tasksCompleted' => $overview->tasksCompletedCount,
                'tasksCreated' => $overview->tasksCreatedCount,
                'focusWorkMinutes' => (int) round($overview->focusWorkSecondsTotal / 60),
                'focusSessions' => $overview->focusWorkSessionsCount,
            ],
            'analyticsPeriodLabel' => $overview->periodStart->format('M j, Y')
                .' – '
                .$overview->periodEnd->format('M j, Y'),
        ]);
    }
}
