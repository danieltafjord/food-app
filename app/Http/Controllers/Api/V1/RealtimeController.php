<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RealtimeController extends Controller
{
    /**
     * Where the app opens its live-sync WebSocket, or null while live sync is
     * off (the app then keeps polling). Served by the API rather than built
     * into the app, so the socket host can move without an app release.
     */
    public function __invoke(): JsonResponse
    {
        $connection = config('broadcasting.connections.'.config('broadcasting.default'));

        if (($connection['driver'] ?? null) !== 'reverb' || empty($connection['key']) || empty($connection['public']['host'])) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'key' => $connection['key'],
            'host' => $connection['public']['host'],
            'port' => (int) $connection['public']['port'],
            'scheme' => $connection['public']['scheme'],
        ]]);
    }
}
