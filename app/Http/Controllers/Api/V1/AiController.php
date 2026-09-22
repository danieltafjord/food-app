<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ai\AiConfiguration;
use App\Actions\Ai\AiResult;
use App\Actions\Ai\AiUsage;
use App\Actions\Ai\ClassifyIngredient;
use App\Actions\Ai\RunAiRequest;
use App\Actions\Ai\SuggestDinnerIngredients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AiController extends ApiController
{
    public function settings(Request $request, AiUsage $usage, RunAiRequest $runner): JsonResponse
    {
        return response()->json(['data' => [
            'categorization_enabled' => $request->user()->ai_categorization_enabled,
            'suggestions_enabled' => $request->user()->ai_suggestions_enabled,
            'available' => $runner->available(),
            'email_verified' => $request->user()->hasVerifiedEmail(),
            'usage' => $usage->status($request->user(), $this->currentHousehold($request)),
        ]]);
    }

    public function updateSettings(Request $request, AiUsage $usage, RunAiRequest $runner): JsonResponse
    {
        $input = $request->validate([
            'categorization_enabled' => ['required', 'boolean'],
            'suggestions_enabled' => ['required', 'boolean'],
        ]);
        $request->user()->forceFill([
            'ai_categorization_enabled' => $input['categorization_enabled'],
            'ai_suggestions_enabled' => $input['suggestions_enabled'],
        ])->save();

        return $this->settings($request, $usage, $runner);
    }

    public function categorize(Request $request, RunAiRequest $runner, ClassifyIngredient $classifier): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'locale' => ['required', Rule::in(['en', 'nb'])],
        ]);
        $input['name'] = mb_strtolower(trim($input['name']));
        $result = $runner->handle($request->user(), $this->currentHousehold($request), 'categorization', $input,
            fn () => $classifier->handle($input['name'], $input['locale']));

        return response()->json(['data' => $result]);
    }

    public function suggest(Request $request, RunAiRequest $runner, SuggestDinnerIngredients $agent, AiConfiguration $configuration): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'ingredients' => ['present', 'array', 'max:40'],
            'ingredients.*' => ['required', 'string', 'max:120'],
            'locale' => ['required', Rule::in(['en', 'nb'])],
        ]);
        $household = $this->currentHousehold($request);
        $input['name'] = trim($input['name']);
        $input['ingredients'] = array_values(array_unique(array_map(fn (string $name) => mb_strtolower(trim($name)), $input['ingredients'])));
        sort($input['ingredients']);
        $input['catalogue'] = $household->ingredients()->orderBy('name')->limit(100)->pluck('name')->map(fn (string $name) => mb_substr($name, 0, 120))->all();
        $result = $runner->handle($request->user(), $household, 'suggestions', $input, function () use ($agent, $input, $configuration): AiResult {
            $response = $agent->prompt(json_encode($input, JSON_THROW_ON_ERROR), provider: 'openrouter',
                model: $configuration->model('suggestions'), timeout: 15);
            $validated = Validator::make(['ingredients' => $response['ingredients']], [
                'ingredients' => ['present', 'array', 'max:3'],
                'ingredients.*' => ['required', 'string', 'max:80', 'not_regex:/[\\r\\n<>]/'],
            ])->validate();
            $existing = array_fill_keys($input['ingredients'], true);
            $suggestions = [];
            foreach ($validated['ingredients'] as $name) {
                $name = trim($name);
                $key = mb_strtolower($name);
                if ($name !== '' && ! isset($existing[$key])) {
                    $suggestions[] = $name;
                    $existing[$key] = true;
                }
            }

            return new AiResult(
                ['ingredients' => $suggestions],
                $response->usage->promptTokens,
                $response->usage->completionTokens + $response->usage->reasoningTokens,
            );
        });

        return response()->json(['data' => $result]);
    }
}
