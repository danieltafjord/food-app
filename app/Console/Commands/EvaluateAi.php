<?php

namespace App\Console\Commands;

use App\Actions\Ai\AiConfiguration;
use App\Actions\Ai\ClassifyIngredient;
use App\Actions\Ai\RunAiRequest;
use App\Actions\Ai\SuggestDinnerIngredients;
use App\Enums\ReasoningEffort;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('ai:evaluate {--feature= : categorization or suggestions; omit for both} {--model= : Override the configured model for one feature} {--reasoning= : Override suggestion reasoning effort} {--live : Make billable provider requests; otherwise only preview cases}')]
#[Description('Evaluate Norwegian and English grocery cases and report quality, latency and cost as JSON')]
class EvaluateAi extends Command
{
    public function handle(ClassifyIngredient $classifier, SuggestDinnerIngredients $suggestions, AiConfiguration $configuration, RunAiRequest $runner): int
    {
        $feature = $this->option('feature');
        $model = $this->option('model');
        $reasoning = $this->option('reasoning');
        if (($feature && ! isset(AiConfiguration::FEATURES[$feature])) || ($model && ! $feature)
            || ($reasoning && ($feature !== 'suggestions' || ReasoningEffort::tryFrom($reasoning) === null))) {
            $this->error('Choose a valid feature when overriding a model or reasoning effort.');

            return self::INVALID;
        }
        $cases = collect(json_decode(file_get_contents(resource_path('ai-evaluation.json')), true, flags: JSON_THROW_ON_ERROR))
            ->filter(fn (array $case) => ! $feature || $case['feature'] === $feature)->values();
        if (! $this->option('live')) {
            $this->line(json_encode(['live' => false, 'cases' => $cases->all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        if (! $runner->available()) {
            $this->error('Enable AI assistance and configure the provider key before running a live evaluation.');

            return self::FAILURE;
        }
        $rows = [];
        foreach ($cases as $case) {
            $started = hrtime(true);
            $result = null;
            $error = null;
            $passed = false;
            $selectedModel = $model ?: $configuration->model($case['feature']);
            $effort = $reasoning ? ReasoningEffort::from($reasoning) : $configuration->reasoningEffort($case['feature']);
            try {
                $result = $case['feature'] === 'categorization'
                    ? $classifier->handle($case['input']['name'], $case['input']['locale'], $selectedModel)
                    : $suggestions->handle($case['input'], $selectedModel, $effort);
                $passed = $case['feature'] === 'categorization'
                    ? in_array($result->data['category'], $case['expected'], true)
                    : count($result->data['ingredients']) >= $case['minimum']
                        && array_diff(array_map(fn (string $name) => mb_strtolower(trim($name)), $result->data['ingredients']), $case['acceptable']) === [];
            } catch (Throwable $exception) {
                $error = $runner->describe($exception);
            }
            $rows[] = [
                'id' => $case['id'], 'feature' => $case['feature'], 'model' => $selectedModel, 'reasoning' => $effort?->value,
                'passed' => $passed, 'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                'cost' => $result?->cost ?? 0, 'input_tokens' => $result?->inputTokens ?? 0,
                'output_tokens' => $result?->outputTokens ?? 0, 'data' => $result?->data, 'error' => $error,
            ];
        }
        $passed = count(array_filter($rows, fn (array $row) => $row['passed']));
        $this->line(json_encode([
            'live' => true, 'passed' => $passed, 'total' => count($rows),
            'cost' => array_sum(array_column($rows, 'cost')),
            'average_duration_ms' => (int) round(array_sum(array_column($rows, 'duration_ms')) / count($rows)),
            'results' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $passed === count($rows) ? self::SUCCESS : self::FAILURE;
    }
}
