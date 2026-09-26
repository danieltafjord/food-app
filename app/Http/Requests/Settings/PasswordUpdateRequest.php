<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Someone who signed up with Apple or Google sets a first password.
        return [
            'current_password' => $this->user()->hasPassword() ? $this->currentPasswordRules() : ['prohibited'],
            'password' => $this->passwordRules(),
        ];
    }
}
