<?php

namespace App\Models;

use App\Enums\ApiTokenScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/**
 * App-specific metadata for a user-created personal access token: the
 * household it is pinned to and when it was last used. A Passport token with
 * a row here is an "API token"; tokens without one belong to the mobile app.
 */
class ApiTokenDetail extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'token_id',
        'household_id',
        'last_used_at',
        'can_write',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'can_write' => 'boolean',
        ];
    }

    /**
     * Whether this token may act with the given scope. OAuth-issued tokens
     * carry their permission on this row; personal access tokens use their
     * own Passport scopes.
     */
    public function allows(ApiTokenScope $scope): bool
    {
        if ($this->can_write !== null) {
            return $scope === ApiTokenScope::Read || $this->can_write;
        }

        return (bool) $this->token?->can($scope->value);
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Passport::tokenModel(), 'token_id');
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
