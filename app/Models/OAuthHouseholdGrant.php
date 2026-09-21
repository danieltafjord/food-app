<?php

namespace App\Models;

use App\Models\Passport\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a user approved for a third-party OAuth client (such as an MCP
 * connector): which household it may access and whether it may write. Every
 * access token later issued to that client for that user inherits this.
 */
class OAuthHouseholdGrant extends Model
{
    protected $table = 'oauth_household_grants';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'client_id',
        'household_id',
        'can_write',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_write' => 'boolean',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
