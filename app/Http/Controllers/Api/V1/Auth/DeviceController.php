<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Data\AccessTokenData;
use App\Http\Controllers\Controller;
use App\Models\ApiTokenDetail;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Token;
use Spatie\LaravelData\DataCollection;

class DeviceController extends Controller
{
    /**
     * List the user's active tokens (one per signed-in device). User-created
     * API tokens are managed on the web and are not devices.
     */
    public function index(Request $request): DataCollection
    {
        $currentTokenId = $request->user()->token()?->id;

        $tokens = $request->user()->tokens()
            ->whereNotIn('id', ApiTokenDetail::query()->select('token_id'))
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
        $deviceToken = $request->user()->tokens()
            ->whereNotIn('id', ApiTokenDetail::query()->select('token_id'))
            ->whereKey($token)
            ->firstOrFail();
        $deviceToken->getConnection()->transaction(function () use ($deviceToken): void {
            $deviceToken->revoke();
            $deviceToken->refreshToken()->update(['revoked' => true]);
            PushToken::query()->where('access_token_id', $deviceToken->id)->delete();
        });

        return response()->json(['message' => 'Device revoked.']);
    }
}
