<?php

use App\Models\AiRequest;
use App\Models\ApiRequest;
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

Artisan::command('ai:prune-request-bodies', function () {
    AiRequest::query()
        ->where('created_at', '<', now()->subDays(AiRequest::BODY_RETENTION_DAYS))
        ->where(fn ($query) => $query->whereNotNull('request')->orWhereNotNull('response')->orWhereNotNull('error'))
        ->update(['request' => null, 'response' => null, 'error' => null]);
})->purpose('Clear stored AI request and response bodies older than the retention period');

Schedule::command('ai:prune-usage')->dailyAt('02:00')->timezone('UTC')->withoutOverlapping();
Schedule::command('ai:prune-request-bodies')->dailyAt('02:10')->timezone('UTC')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [ApiRequest::class]])->dailyAt('02:20')->timezone('UTC')->withoutOverlapping();
