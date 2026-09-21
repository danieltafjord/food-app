<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiTokenStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'household_id' => [
                'required',
                'integer',
                Rule::exists('household_user', 'household_id')->where('user_id', $this->user()->id),
            ],
            'can_write' => ['required', 'boolean'],
        ];
    }
}
