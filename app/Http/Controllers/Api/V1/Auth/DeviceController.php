<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Data\AccessTokenData;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Token;
use Spatie\LaravelData\DataCollection;

class DeviceController extends Controller
{
    /**
     * List the user's active tokens (one per signed-in device).
     */
    public function index(Request $request): DataCollection
    {
        $currentTokenId = $request->user()->token()?->id;

        $tokens = $request->user()->tokens()
            ->where('revoked', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get()
            ->map(fn (Token $token) => AccessTokenData::fromToken($token, $currentTokenId));

        return AccessTokenData::collect($tokens, DataCollection::class);
    }

    /**
     * Revoke a specific device's token.
     */
    public function destroy(Request $request, string $token): JsonResponse
    {
        $deviceToken = $request->user()->tokens()->whereKey($token)->firstOrFail();
        $deviceToken->getConnection()->transaction(function () use ($deviceToken): void {
            $deviceToken->revoke();
            $deviceToken->refreshToken()->update(['revoked' => true]);
        });

        return response()->json(['message' => 'Device revoked.']);
    }
}
