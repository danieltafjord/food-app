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
        $request->user()->tokens()->whereKey($token)->firstOrFail()->revoke();

        return response()->json(['message' => 'Device revoked.']);
    }
}
