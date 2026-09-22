<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
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
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($target->id)],
            'is_admin' => ['required', 'boolean'],
            'email_verified' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                /** @var User $target */
                $target = $this->route('user');
                if ($target->is($this->user()) && ! $this->boolean('is_admin')) {
                    $validator->errors()->add('is_admin', __('You cannot remove your own admin access.'));
                }
            },
        ];
    }
}
