<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\RecordAdminAction;
use App\Actions\Ai\AiConfiguration;
use App\Actions\Ai\ListOpenRouterModels;
use App\Actions\Ai\RunAiRequest;
use App\Enums\ReasoningEffort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiLimitsUpdateRequest;
use App\Http\Requests\Admin\AiSettingsUpdateRequest;
use App\Models\AdminAction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    /**
     * Show which model, reasoning effort and daily budgets each AI feature uses.
     */
    public function edit(AiConfiguration $configuration, ListOpenRouterModels $catalogue, RunAiRequest $runner): Response
    {
        return Inertia::render('admin/AiSettings', [
            'features' => $configuration->all(),
            'limits' => $configuration->limits(),
            'reasoningEfforts' => ReasoningEffort::values(),
            'available' => $runner->available(),
            'models' => Inertia::defer(fn () => $catalogue->handle()),
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
