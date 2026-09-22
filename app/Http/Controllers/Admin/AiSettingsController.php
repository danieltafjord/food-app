<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\RecordAdminAction;
use App\Actions\Ai\AiConfiguration;
use App\Actions\Ai\AiUsage;
use App\Actions\Ai\ListOpenRouterModels;
use App\Actions\Ai\RunAiRequest;
use App\Actions\Ai\TestAiFeature;
use App\Enums\ReasoningEffort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiLimitsUpdateRequest;
use App\Http\Requests\Admin\AiSettingsUpdateRequest;
use App\Http\Requests\Admin\AiTestRequest;
use App\Models\AdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    /**
     * Show which model, reasoning effort and daily budgets each AI feature
     * uses, along with today's usage and the OpenRouter catalogue.
     */
    public function edit(AiConfiguration $configuration, ListOpenRouterModels $catalogue, RunAiRequest $runner, AiUsage $usage): Response
    {
        return Inertia::render('admin/AiSettings', [
            'features' => $configuration->all(),
            'limits' => $configuration->limits(),
            'reasoningEfforts' => ReasoningEffort::values(),
            'available' => $runner->available(),
            'usage' => $usage->overview(),
            'models' => Inertia::defer(fn () => $catalogue->handle(), 'catalogue'),
            'catalogue' => Inertia::defer(fn () => $catalogue->status(), 'catalogue'),
            'recentActions' => AdminAction::query()
                ->whereIn('action', [AdminAction::AI_MODEL_UPDATED, AdminAction::AI_LIMITS_UPDATED])
                ->latest('id')->limit(10)->get()
                ->map(fn (AdminAction $action) => $action->toRow())->all(),
        ]);
    }

    /**
     * Change the model or reasoning effort of one feature.
     */
    public function update(AiSettingsUpdateRequest $request, AiConfiguration $configuration, RecordAdminAction $audit): RedirectResponse
    {
        $feature = $request->string('feature')->value();
        $effort = AiConfiguration::FEATURES[$feature]['supports_reasoning'] && $request->filled('reasoning')
            ? ReasoningEffort::from($request->string('reasoning')->value())
            : null;
        $before = ['model' => $configuration->model($feature), 'reasoning' => $configuration->reasoningEffort($feature)?->value];

        $configuration->update($feature, $request->string('model')->trim()->value(), $effort);

        $after = ['model' => $configuration->model($feature), 'reasoning' => $configuration->reasoningEffort($feature)?->value];
        if ($before !== $after) {
            $audit->handle($request->user(), AdminAction::AI_MODEL_UPDATED, subjectLabel: AiConfiguration::FEATURES[$feature]['label'], changes: self::diff($before, $after));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':feature updated.', ['feature' => AiConfiguration::FEATURES[$feature]['label']])]);

        return to_route('admin.ai.edit');
    }

    /**
     * Run one real provider call with unsaved settings so an admin can see
     * what a model answers before switching to it.
     */
    public function test(AiTestRequest $request, TestAiFeature $tester, RunAiRequest $runner): JsonResponse
    {
        if (! $runner->available()) {
            return response()->json(['message' => 'Server-side AI is off, so nothing can be sent to the provider.'], 422);
        }

        $feature = $request->string('feature')->value();
        $effort = AiConfiguration::FEATURES[$feature]['supports_reasoning'] && $request->filled('reasoning')
            ? ReasoningEffort::from($request->string('reasoning')->value())
            : null;

        return response()->json($tester->handle($request->user(), $feature, $request->string('model')->trim()->value(), $effort, (string) $request->input('sample', '')));
    }

    /**
     * Drop the cached OpenRouter catalogue so the picker shows a fresh one.
     */
    public function refreshCatalogue(ListOpenRouterModels $catalogue): RedirectResponse
    {
        $catalogue->refresh();

        return back();
    }

    /**
     * Change the daily request budgets of one feature.
     */
    public function updateLimits(AiLimitsUpdateRequest $request, AiConfiguration $configuration, RecordAdminAction $audit): RedirectResponse
    {
        $feature = $request->string('feature')->value();
        $before = collect(AiConfiguration::LIMIT_SCOPES)->mapWithKeys(fn (string $kind) => [$kind => $configuration->limit($feature, $kind)])->all();

        $configuration->updateLimits($feature, [
            'user' => $request->integer('user'),
            'household' => $request->integer('household'),
            'global' => $request->integer('global'),
        ]);

        $after = collect(AiConfiguration::LIMIT_SCOPES)->mapWithKeys(fn (string $kind) => [$kind => $configuration->limit($feature, $kind)])->all();
        if ($before !== $after) {
            $audit->handle($request->user(), AdminAction::AI_LIMITS_UPDATED, subjectLabel: AiConfiguration::FEATURES[$feature]['label'], changes: self::diff($before, $after));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':feature limits updated.', ['feature' => AiConfiguration::FEATURES[$feature]['label']])]);

        return to_route('admin.ai.edit');
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }
}
