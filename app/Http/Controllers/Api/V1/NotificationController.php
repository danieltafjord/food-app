<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationTopic;
use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    /**
     * Which push notifications the user wants.
     */
    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->notificationPreferences()]);
    }

    /**
     * Turn notification topics on or off, or mute shopping lists. Omitted
     * fields keep their value.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $rules = [
            'muted_lists' => ['sometimes', 'array', 'max:200'],
            'muted_lists.*' => ['uuid'],
        ];
        foreach (NotificationTopic::cases() as $topic) {
            $rules[$topic->value] = ['sometimes', 'boolean'];
        }
        $input = $request->validate($rules);

        $user = $request->user();
        $user->forceFill(['notification_preferences' => array_merge($user->notificationPreferences(), $input, [
            'muted_lists' => array_values(array_unique($input['muted_lists'] ?? $user->notificationPreferences()['muted_lists'])),
        ])])->save();

        return $this->preferences($request);
    }

    /**
     * Register (or refresh) this install's Expo push token. A token another
     * account registered moves to this one: only the account signed in on
     * the device should be notified there.
     */
    public function registerDevice(Request $request): Response
    {
        $input = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[[^\]]+\]$/'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        PushToken::query()->updateOrCreate(['token' => $input['token']], [
            'user_id' => $request->user()->id,
            'platform' => $input['platform'],
            'timezone' => $input['timezone'] ?? null,
            'access_token_id' => $request->user()->token()?->id,
        ])->touch();

        return response()->noContent();
    }

    /**
     * Stop notifying this install (notifications turned off in the app).
     */
    public function unregisterDevice(Request $request): Response
    {
        $input = $request->validate(['token' => ['required', 'string', 'max:255']]);

        $request->user()->pushTokens()->where('token', $input['token'])->delete();

        return response()->noContent();
    }
}
