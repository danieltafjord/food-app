<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:prune-usage', function () {
    DB::table('ai_daily_usage')->where('day', '<', now('UTC')->subDays(7)->toDateString())->delete();
})->purpose('Remove AI usage counters older than seven days');

Schedule::command('ai:prune-usage')->dailyAt('02:00')->timezone('UTC')->withoutOverlapping();
