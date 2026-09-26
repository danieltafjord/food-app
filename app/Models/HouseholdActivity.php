<?php

namespace App\Models;

use App\Enums\HouseholdActivityKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a member did to shared data, kept until it has been bundled into
 * notifications for the rest of the household (see SendHouseholdActivityNotifications).
 */
class HouseholdActivity extends Model
{
    use MassPrunable;

    /** Hours an activity is kept, notified or not. Older ones are never sent. */
    public const RETENTION_HOURS = 48;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['household_id', 'user_id', 'kind', 'shopping_list_id', 'subject_id', 'payload', 'created_at', 'notified_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => HouseholdActivityKind::class,
            'payload' => 'array',
            'created_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
