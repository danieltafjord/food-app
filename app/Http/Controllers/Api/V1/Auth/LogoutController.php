<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    /**
     * Revoke the current access token and its refresh credentials, and stop
     * push notifications to this device (sent as `push_token`, or known from
     * the token it last registered with).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $pushToken = $request->input('push_token');
        $token = $request->user()->token();
        $token->getConnection()->transaction(function () use ($token, $request, $pushToken): void {
            $token->revoke();
            $token->refreshToken()->update(['revoked' => true]);
            PushToken::query()->where('user_id', $request->user()->id)
                ->where(fn ($query) => $query->where('access_token_id', $token->id)
                    ->when(is_string($pushToken), fn ($query) => $query->orWhere('token', $pushToken)))
                ->delete();
        });

        return response()->json(['message' => 'Logged out.']);
    }
}
