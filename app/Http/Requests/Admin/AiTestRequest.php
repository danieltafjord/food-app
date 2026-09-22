<?php

namespace App\Http\Requests\Admin;

/**
 * The model and reasoning effort to try, exactly as they would be saved,
 * plus an optional sample ingredient or dinner name.
 */
class AiTestRequest extends AiSettingsUpdateRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + ['sample' => ['nullable', 'string', 'max:120']];
    }
}
