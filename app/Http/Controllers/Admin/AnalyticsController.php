<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildAnalytics;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    /** Seconds the aggregated numbers are reused before they are recomputed. */
    public const CACHE_SECONDS = 300;

    /**
     * Show app and AI usage over a trailing window of days. The aggregates
     * run a dozen queries, so they are cached briefly; `?refresh=1` forces a
     * recount.
     */
    public function index(Request $request, BuildAnalytics $analytics): Response
    {
        $days = (int) $request->integer('days', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $key = "admin-analytics:{$days}";

        if ($request->boolean('refresh')) {
            Cache::forget($key);
        }

        $cached = Cache::remember($key, self::CACHE_SECONDS, fn () => [
            'analytics' => $analytics->handle($days),
            'generated_at' => now()->toIso8601String(),
        ]);

        return Inertia::render('admin/Analytics', [
            'analytics' => $cached['analytics'],
            'generatedAt' => $cached['generated_at'],
            'cacheSeconds' => self::CACHE_SECONDS,
        ]);
    }
}
