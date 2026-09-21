<?php

namespace App\Enums;

enum ApiTokenScope: string
{
    case Read = 'read';
    case Write = 'write';

    public function description(): string
    {
        return match ($this) {
            self::Read => 'Read your household\'s ingredients, dinners, plans and shopping lists',
            self::Write => 'Create, change and delete your household\'s ingredients, dinners, plans and shopping lists',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $scope) => [$scope->value => $scope->description()])
            ->all();
    }
}
