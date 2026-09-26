<?php

namespace App\Actions\Users;

use App\Actions\Auth\AppleTokens;
use App\Actions\Households\DeleteHousehold;
use App\Actions\Sync\AllocateSyncVersion;
use App\Enums\HouseholdRole;
use App\Enums\SocialProvider;
use App\Models\AiRequest;
use App\Models\ApiRequest;
use App\Models\Dinner;
use App\Models\DinnerItem;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\Household;
use App\Models\HouseholdDinnerCategory;
use App\Models\HouseholdInvitation;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Passport\Passport;

class DeleteAccount
{
    /**
     * Free text that is personal to whoever wrote it, cleared even from rows
     * the rest of the household keeps.
     *
     * @var list<string>
     */
    private const PERSONAL_FIELDS = ['notes'];

    /** @var list<class-string<Model>> */
    private const CONTENT_MODELS = [HouseholdDinnerCategory::class, Ingredient::class, Dinner::class, DinnerItem::class, DinnerPlan::class, DinnerPlanEntry::class, ShoppingList::class, ShoppingListItem::class];

    public function __construct(private DeleteHousehold $deleteHousehold, private AllocateSyncVersion $allocateVersion, private AppleTokens $appleTokens) {}

    public function handle(User $user): void
    {
        $appleRefreshTokens = $user->socialAccounts()->where('provider', SocialProvider::Apple)
            ->whereNotNull('refresh_token')->get()->pluck('refresh_token');

        DB::transaction(function () use ($user): void {
            $householdIds = $user->households()->pluck('households.id');

            foreach (self::CONTENT_MODELS as $model) {
                $this->contributions($model, $user)->eachById(function (Model $resource) use (&$householdIds): void {
                    $householdIds->push($resource->syncHouseholdId());
                });
            }
            $householdIds = $householdIds->merge(Household::query()
                ->where(fn (Builder $query) => $this->authoredBy($query, $user))->pluck('id'));

            $households = Household::query()->whereKey($householdIds->unique())
                ->orderBy('id')->lockForUpdate()->get();

            foreach ($households as $household) {
                $members = $household->members()->orderBy('users.id')->get();
                $remainingMembers = $members->where('id', '!=', $user->id);

                if ($remainingMembers->isEmpty()) {
                    $this->deleteHousehold->handle($household);

                    continue;
                }

                if (! $remainingMembers->contains(fn (User $member): bool => $member->pivot->role === HouseholdRole::Owner->value)) {
                    $household->members()->updateExistingPivot($remainingMembers->first()->id, [
                        'role' => HouseholdRole::Owner->value,
                    ]);
                }

                $this->anonymise($household, $user);
            }

            // Every row left belongs to a household that other people still
            // use (households the user was last in are gone). Their shopping
            // list and recipes keep working: only the user's name comes off.
            foreach (self::CONTENT_MODELS as $model) {
                $this->contributions($model, $user)->eachById(fn (Model $resource) => $this->anonymise($resource, $user));
            }

            $clientIds = $user->oauthApps()->pluck('id');
            $tokens = Passport::token()->newQuery()->where('user_id', $user->id)->orWhereIn('client_id', $clientIds);
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokens->select('id'))->delete();
            $tokens->delete();
            Passport::authCode()->newQuery()->where('user_id', $user->id)->orWhereIn('client_id', $clientIds)->delete();
            Passport::deviceCode()->newQuery()->where('user_id', $user->id)->orWhereIn('client_id', $clientIds)->delete();
            $user->oauthApps()->delete();

            HouseholdInvitation::query()->where('invited_by_user_id', $user->id)
                ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])->delete();
            Password::broker()->deleteToken($user);
            DB::connection(config('session.connection'))->table(config('session.table'))
                ->where('user_id', $user->id)->delete();

            // The request logs hold what the user sent and got back. AI rows keep
            // their anonymous usage and cost figures for the totals.
            ApiRequest::query()->where('user_id', $user->id)->delete();
            AiRequest::query()->where('user_id', $user->id)->update(['request' => null, 'response' => null, 'error' => null]);

            $user->delete();
        });

        // Apple requires ending the app's Sign in with Apple access too. This
        // runs after the commit so a slow Apple never holds the transaction.
        foreach ($appleRefreshTokens as $refreshToken) {
            $this->appleTokens->revoke($refreshToken);
        }
    }

    /** @param class-string<Model> $model */
    private function contributions(string $model, User $user): Builder
    {
        return $model::withTrashed()->where(fn (Builder $query) => $query
            ->where('created_by_user_id', $user->id)
            ->orWhere(fn (Builder $query) => $this->authoredBy($query, $user)));
    }

    /**
     * Rows whose content the user authored. On PostgreSQL the key-exists
     * operator uses the GIN index on the jsonb column (`??` is PDO's escape
     * for a literal `?`); a JSON path comparison could not use it.
     */
    private function authoredBy(Builder $query, User $user): void
    {
        $key = 'user_'.$user->id;
        if ($query->getConnection()->getDriverName() === 'pgsql') {
            $query->whereRaw('content_authors ?? ?', [$key]);
        } else {
            $query->whereNotNull('content_authors->'.$key);
        }
    }

    /**
     * Detach the user from a row others still use: drop them as its creator
     * and author, and clear the personal free text they wrote (notes). Names,
     * amounts and links stay, so other members lose nothing they rely on.
     * A cleared field bumps the erasure version, so offline copies cannot
     * restore the text; every change is stamped for sync.
     */
    private function anonymise(Model $resource, User $user): void
    {
        $authors = $resource->content_authors ?? [];
        $cleared = array_values(array_intersect($authors['user_'.$user->id] ?? [], self::PERSONAL_FIELDS));
        unset($authors['user_'.$user->id]);
        // Someone who edited a cleared field after the user may have kept their text.
        foreach ($authors as $authorId => $authoredFields) {
            $authors[$authorId] = array_values(array_diff($authoredFields, $cleared));
            if ($authors[$authorId] === []) {
                unset($authors[$authorId]);
            }
        }

        $attributes = array_intersect_key($resource->contentErasureDefaults(), array_flip($cleared));
        $attributes['content_authors'] = $authors ?: null;
        if (! $resource instanceof Household && $resource->created_by_user_id === $user->id) {
            $attributes['created_by_user_id'] = null;
        }
        $resource->forceFill($attributes)->withoutContentAttribution();

        if ($resource instanceof Household) {
            if ($cleared !== []) {
                $resource->erasure_version = $resource->erasure_version + 1;
            }
        } else {
            $version = $this->allocateVersion->handle($resource->syncHouseholdId());
            if ($cleared !== []) {
                $resource->erasure_version = $version;
            }
            $resource->stampSync($version);
        }
        $resource->save();
    }
}
