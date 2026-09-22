<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class AiAssistanceUpdateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'categorization_enabled' => ['required', 'boolean'],
            'suggestions_enabled' => ['required', 'boolean'],
        ];
    }
}
