<?php

namespace App\Enums;

/**
 * Identity providers a user can sign in with instead of a password.
 */
enum SocialProvider: string
{
    case Apple = 'apple';
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::Apple => 'Apple',
            self::Google => 'Google',
        };
    }

    /**
     * Providers that sign in on the website through Socialite and have
     * credentials configured. Apple signs in natively in the iOS app instead.
     *
     * @return list<self>
     */
    public static function availableOnWeb(): array
    {
        return filled(config('services.google.client_id')) ? [self::Google] : [];
    }
}
