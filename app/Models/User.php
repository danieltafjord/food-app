<?php

namespace App\Models;

use App\Enums\AppLocale;
use App\Enums\NotificationTopic;
use App\Enums\Theme;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'current_household_id', 'theme', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'ai_categorization_enabled' => false,
        'ai_suggestions_enabled' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
            'deactivated_at' => 'datetime',
            'ai_categorization_enabled' => 'boolean',
            'ai_suggestions_enabled' => 'boolean',
            'theme' => Theme::class,
            'locale' => AppLocale::class,
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Which push notifications the user wants. Every topic is on until turned
     * off; `muted_lists` holds shopping list uuids to stay quiet about.
     *
     * @return array{list_items: bool, shopping: bool, plan: bool, household: bool, muted_lists: list<string>}
     */
    public function notificationPreferences(): array
    {
        $stored = $this->notification_preferences ?? [];
        $preferences = [];
        foreach (NotificationTopic::cases() as $topic) {
            $preferences[$topic->value] = (bool) ($stored[$topic->value] ?? true);
        }
        $preferences['muted_lists'] = array_values(array_filter($stored['muted_lists'] ?? [], 'is_string'));

        return $preferences;
    }

    public function wantsNotification(NotificationTopic $topic, ?string $shoppingListUuid = null): bool
    {
        $preferences = $this->notificationPreferences();

        return $preferences[$topic->value]
            && ($shoppingListUuid === null || ! in_array($shoppingListUuid, $preferences['muted_lists'], true));
    }

    /**
     * The user's app installs that can receive push notifications.
     *
     * @return HasMany<PushToken, $this>
     */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * The Apple and Google identities this user can sign in with.
     *
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * False for people who signed up with Apple or Google and never set one.
     */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * The households this user belongs to.
     *
     * @return BelongsToMany<Household, $this>
     */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The household currently active for this user.
     *
     * @return BelongsTo<Household, $this>
     */
    public function currentHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'current_household_id');
    }
}
