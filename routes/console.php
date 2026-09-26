<?php

use App\Actions\DinnerImages\PruneDinnerImages;
use App\Actions\Notifications\SendHouseholdActivityNotifications;
use App\Models\AiRequest;
use App\Models\ApiRequest;
use App\Models\HouseholdActivity;
use App\Models\HouseholdInvitation;
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

Artisan::command('cache:prune-expired', function () {
    // The database store only drops an expired entry when that key is read
    // again; AI results and per-IP rate-limit keys often never are.
    $store = config('cache.stores.'.config('cache.default'));
    if (($store['driver'] ?? null) !== 'database') {
        return;
    }
    $removed = DB::connection($store['connection'] ?? null)->table($store['table'] ?? 'cache')
        ->where('expiration', '<=', now()->getTimestamp())
        ->delete();
    $this->info("Removed {$removed} expired cache entries.");
})->purpose('Delete expired entries from the database cache store');

Artisan::command('dinner-images:prune', function (PruneDinnerImages $prune) {
    $this->info('Removed '.$prune->handle().' unused dinner images.');
})->purpose('Delete dinner pictures that no dinner uses any more');

Artisan::command('notifications:household-activity', function (SendHouseholdActivityNotifications $send) {
    $this->info('Sent '.$send->handle().' notifications.');
})->purpose('Send any household activity notifications still pending (normally sent by queued jobs)');

Schedule::command('ai:prune-usage')->dailyAt('02:00')->timezone('UTC')->withoutOverlapping();
Schedule::command('ai:prune-request-bodies')->dailyAt('02:10')->timezone('UTC')->withoutOverlapping();
Schedule::command('dinner-images:prune')->dailyAt('02:30')->timezone('UTC')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [ApiRequest::class, HouseholdInvitation::class, HouseholdActivity::class]])->dailyAt('02:20')->timezone('UTC')->withoutOverlapping();
Schedule::command('passport:purge')->dailyAt('02:40')->timezone('UTC')->withoutOverlapping();
Schedule::command('queue:prune-failed', ['--hours' => 24 * 7])->dailyAt('02:50')->timezone('UTC')->withoutOverlapping();
Schedule::command('cache:prune-expired')->dailyAt('03:00')->timezone('UTC')->withoutOverlapping();
