<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    /**
     * Revoke the current access token and its refresh credentials.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->user()->token();
        $token->getConnection()->transaction(function () use ($token): void {
            $token->revoke();
            $token->refreshToken()->update(['revoked' => true]);
        });

        return response()->json(['message' => 'Logged out.']);
    }
}
