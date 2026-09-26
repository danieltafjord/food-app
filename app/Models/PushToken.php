<?php

namespace App\Models;

use Database\Factories\PushTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Laravel\Passport\Passport;

/**
 * An Expo push token for one install of the mobile app.
 *
 * `access_token_id` is the session the install registered under. It follows
 * the session through token refreshes (see MovePushTokensToRefreshedToken),
 * and the app re-registers at launch and on returning to the foreground. An
 * install whose session was revoked or no longer exists, and that has not
 * registered for SESSION_GRACE_DAYS, was signed out without telling us (or
 * revoked from another device): it is not pushed to, and is pruned.
 */
class PushToken extends Model
{
    /** @use HasFactory<PushTokenFactory> */
    use HasFactory, MassPrunable;

    /** How long an install whose session is gone keeps receiving pushes, waiting for it to register again. */
    public const SESSION_GRACE_DAYS = 3;

    /** @var list<string> */
    protected $fillable = ['user_id', 'token', 'platform', 'timezone', 'access_token_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Installs worth pushing to: registered recently, or under a session that
     * is still live (an expired one can still be refreshed). One primary-key
     * lookup per token.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function deliverable(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('access_token_id')
            ->orWhere('updated_at', '>=', now()->subDays(self::SESSION_GRACE_DAYS))
            ->orWhereExists(fn (QueryBuilder $session) => $session
                ->selectRaw('1')
                ->from(Passport::token()->getTable())
                ->whereColumn(Passport::token()->qualifyColumn('id'), $this->qualifyColumn('access_token_id'))
                ->where(Passport::token()->qualifyColumn('revoked'), false)));
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->whereNot(fn (Builder $query) => $query->deliverable());
    }
}
