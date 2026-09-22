<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Ai\AiConfiguration;
use App\Actions\Ai\AiUsage;
use App\Actions\Ai\RunAiRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AiAssistanceUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiAssistanceController extends Controller
{
    /**
     * Show the AI opt-in switches together with today's remaining budget.
     */
    public function edit(Request $request, AiUsage $usage, RunAiRequest $runner, AiConfiguration $configuration): Response
    {
        $user = $request->user();
        $household = $user->currentHousehold;

        return Inertia::render('settings/AiAssistance', [
            'settings' => [
                'categorization_enabled' => $user->ai_categorization_enabled,
                'suggestions_enabled' => $user->ai_suggestions_enabled,
            ],
            'available' => $runner->available(),
            'features' => collect(AiConfiguration::FEATURES)->map(fn (array $feature, string $key) => [
                'key' => $key,
                'label' => $feature['label'],
                'daily_limit' => $configuration->limit($key, 'user'),
            ])->values()->all(),
            'usage' => $household ? $usage->status($user, $household) : null,
        ]);
    }

    /**
     * Turn the AI features on or off for this account.
     */
    public function update(AiAssistanceUpdateRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'ai_categorization_enabled' => $request->boolean('categorization_enabled'),
            'ai_suggestions_enabled' => $request->boolean('suggestions_enabled'),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI assistance settings saved.')]);

        return to_route('ai-assistance.edit');
    }
}
