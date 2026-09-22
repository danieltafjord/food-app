<?php

namespace App\Http\Requests\Admin;

use App\Actions\Ai\AiConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiLimitsUpdateRequest extends FormRequest
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
            'user' => ['required', 'integer', 'min:0', 'max:1000000'],
            'household' => ['required', 'integer', 'min:0', 'max:1000000', 'gte:user'],
            'global' => ['required', 'integer', 'min:0', 'max:10000000', 'gte:household'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'household.gte' => 'The household limit cannot be lower than the per-user limit.',
            'global.gte' => 'The global limit cannot be lower than the per-household limit.',
        ];
    }
}
