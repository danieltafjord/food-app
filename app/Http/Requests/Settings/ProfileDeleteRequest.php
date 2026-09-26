<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Without a password, ProfileController asks for a recent confirmation
        // with Google instead.
        return [
            'password' => $this->user()->hasPassword() ? $this->currentPasswordRules() : ['prohibited'],
        ];
    }
}
