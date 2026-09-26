<?php

namespace App\Models;

use Database\Factories\PushTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Expo push token for one install of the mobile app.
 */
class PushToken extends Model
{
    /** @use HasFactory<PushTokenFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['user_id', 'token', 'platform', 'timezone', 'access_token_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
