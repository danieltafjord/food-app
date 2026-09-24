<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuggestWeekRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'between:1,7'],
            'servings' => ['required', 'integer', 'between:1,99'],
            'locale' => ['required', Rule::in(['en', 'nb'])],
            'preferences' => ['present', 'nullable', 'string', 'max:600'],
            'excluded_ingredients' => ['sometimes', 'array', 'list', 'max:30'],
            'excluded_ingredients.*' => ['required', 'string', 'max:80', 'not_regex:/[<>\\r\\n]/'],
            'reuse_ingredients' => ['sometimes', 'array', 'list', 'max:140'],
            'reuse_ingredients.*' => ['required', 'string', 'max:120'],
            'shortcuts' => ['present', 'array', 'max:3'],
            'shortcuts.*' => ['required', 'distinct', Rule::in(['quick', 'budget', 'vegetarian'])],
            'exclude' => ['present', 'array', 'max:60'],
            'exclude.*' => ['required', 'string', 'max:120'],
            'available' => ['present', 'array', 'max:20'],
            'available.*' => ['array:id,name,category,ingredients'],
            'available.*.id' => ['required', 'uuid', 'distinct'],
            'available.*.name' => ['required', 'string', 'max:120'],
            'available.*.category' => ['present', 'nullable', 'string', 'max:80'],
            'available.*.ingredients' => ['required', 'array', 'min:1', 'max:20'],
            'available.*.ingredients.*' => ['required', 'string', 'max:120'],
        ];
    }
}
