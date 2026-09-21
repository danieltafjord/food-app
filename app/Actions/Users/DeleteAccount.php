<?php

namespace App\Actions\Users;

use App\Actions\Households\DeleteHousehold;
use App\Actions\Sync\AllocateSyncVersion;
use App\Enums\HouseholdRole;
use App\Models\Dinner;
use App\Models\DinnerItem;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\Household;
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
    /** @var list<class-string<Model>> */
    private const CONTENT_MODELS = [Ingredient::class, Dinner::class, DinnerItem::class, DinnerPlan::class, DinnerPlanEntry::class, ShoppingList::class, ShoppingListItem::class];

    public function __construct(private DeleteHousehold $deleteHousehold, private AllocateSyncVersion $allocateVersion) {}

    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $householdIds = $user->households()->pluck('households.id');

            foreach (self::CONTENT_MODELS as $model) {
                $this->contributions($model, $user)->eachById(function (Model $resource) use (&$householdIds): void {
                    $householdIds->push($resource->syncHouseholdId());
                });
            }
            $householdIds = $householdIds->merge(Household::query()
                ->whereNotNull('content_authors->user_'.$user->id)->pluck('id'));

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

                $this->eraseContributions($household, $user);
            }

            foreach (self::CONTENT_MODELS as $model) {
                $this->contributions($model, $user)->eachById(function (Model $resource) use ($user): void {
                    if ($resource->created_by_user_id === $user->id) {
                        $this->eraseResource($resource);
                    } else {
                        $this->eraseContributions($resource, $user);
                    }
                });
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

            $user->delete();
        });
    }

    /** @param class-string<Model> $model */
    private function contributions(string $model, User $user): Builder
    {
        return $model::withTrashed()->where(fn (Builder $query) => $query
            ->where('created_by_user_id', $user->id)
            ->orWhereNotNull('content_authors->user_'.$user->id));
    }

    private function eraseContributions(Model $resource, User $user): void
    {
        $authors = $resource->content_authors ?? [];
        $fields = $authors['user_'.$user->id] ?? [];
        if ($fields === []) {
            return;
        }
        $attributes = array_intersect_key($resource->contentErasureDefaults(), array_flip($fields));
        foreach ($authors as $authorId => $authoredFields) {
            $authors[$authorId] = array_values(array_diff($authoredFields, $fields));
            if ($authors[$authorId] === []) {
                unset($authors[$authorId]);
            }
        }
        if ($resource instanceof ShoppingListItem && in_array('name', $fields, true) && $resource->ingredient_id === null) {
            $attributes['name'] = 'Item';
        }
        $resource->forceFill($attributes + ['content_authors' => $authors ?: null])->withoutContentAttribution();
        if ($resource instanceof Household) {
            $resource->erasure_version = $resource->erasure_version + 1;
        } else {
            $version = $this->allocateVersion->handle($resource->syncHouseholdId());
            $resource->forceFill(['erasure_version' => $version])->stampSync($version);
        }
        $resource->save();
    }

    private function eraseResource(Model $resource): void
    {
        if ($resource instanceof Dinner || $resource instanceof ShoppingList) {
            $resource->items()->withTrashed()->eachById(fn (Model $child) => $this->eraseResource($child));
        }
        if ($resource instanceof Dinner || $resource instanceof DinnerPlan) {
            $entries = $resource instanceof Dinner ? $resource->planEntries() : $resource->entries();
            $entries->withTrashed()->eachById(fn (Model $child) => $this->eraseResource($child));
        }
        if ($resource instanceof Ingredient) {
            foreach ([$resource->dinnerItems(), $resource->shoppingListItems()] as $items) {
                $items->withTrashed()->eachById(fn (Model $child) => $this->eraseResource($child));
            }
        }
        $attributes = $resource->contentErasureDefaults();
        if ($resource instanceof Dinner || $resource instanceof DinnerPlan || $resource instanceof ShoppingList) {
            $attributes['name'] = '';
        }
        if ($resource instanceof ShoppingList) {
            $attributes['dinner_plan_id'] = null;
        }
        if ($resource instanceof ShoppingListItem) {
            $attributes['ingredient_id'] = null;
        }
        $version = $this->allocateVersion->handle($resource->syncHouseholdId());
        $resource->forceFill($attributes + [
            'created_by_user_id' => null,
            'content_authors' => null,
            'erasure_version' => $version,
        ])->withoutContentAttribution()->stampSync($version)->save();
        $resource->delete();
    }
}
