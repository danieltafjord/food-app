<?php

namespace App\Http\Requests\Admin;

use App\Actions\Ai\AiConfiguration;
use App\Enums\ReasoningEffort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'feature' => ['required', Rule::in(array_keys(AiConfiguration::FEATURES))],
            // OpenRouter ids look like vendor/model[:variant]; System One ids may be bare (jev-latest).
            'model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\-]+(\/[A-Za-z0-9._\-]+)*(:[A-Za-z0-9._\-]+)?$/'],
            'reasoning' => ['nullable', Rule::enum(ReasoningEffort::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'model.regex' => 'Enter a valid model id such as google/gemini-3.5-flash-lite.',
        ];
    }
}
