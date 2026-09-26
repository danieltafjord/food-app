<?php

namespace App\Http\Requests;

use App\Actions\Ai\DinnerIdeas;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SuggestWeekRequest extends FormRequest
{
    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

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
            'shortcuts' => ['present', 'array', 'max:'.count(DinnerIdeas::SHORTCUTS)],
            'shortcuts.*' => ['required', 'distinct', Rule::in(DinnerIdeas::SHORTCUTS)],
            // The weekday of each requested dinner, in order, so Friday can be taco night.
            'days' => ['sometimes', 'array', 'list'],
            'days.*' => ['required', Rule::in(self::WEEKDAYS)],
            // How many dinners to take from `available`; the rest are new recipes.
            'reuse' => ['sometimes', 'integer', 'min:0', 'max:7'],
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

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $count = $this->integer('count');
            if (is_array($this->input('days')) && count($this->input('days')) !== $count) {
                $validator->errors()->add('days', 'Give one weekday per requested dinner.');
            }
            if ($this->has('reuse') && $this->integer('reuse') > $count) {
                $validator->errors()->add('reuse', 'Cannot reuse more dinners than requested.');
            }
            $shortcuts = (array) $this->input('shortcuts', []);
            if (in_array('vegetarian', $shortcuts, true) && in_array('fish', $shortcuts, true)) {
                $validator->errors()->add('shortcuts', 'Vegetarian and more fish cannot be combined.');
            }
        }];
    }
}
