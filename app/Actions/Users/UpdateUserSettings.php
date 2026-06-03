<?php

namespace App\Actions\Users;

use App\Data\UserSettingsData;
use App\Models\User;

class UpdateUserSettings
{
    public function handle(User $user, UserSettingsData $data): User
    {
        $user->update([
            'theme' => $data->theme,
            'locale' => $data->locale,
        ]);

        return $user;
    }
}
