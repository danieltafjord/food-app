<?php

namespace App\Actions\Households;

use App\Models\ApiTokenDetail;
use App\Models\DinnerItem;
use App\Models\DinnerPlanEntry;
use App\Models\Household;
use App\Models\ShoppingListItem;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

class DeleteHousehold
{
    /**
     * Delete a household and everything it owns, hard.
     *
     * Rows are removed leaf-first and explicitly rather than through database
     * cascades: `dinner_items.ingredient_id` is RESTRICT, and on Postgres the
     * household→ingredients cascade fires before household→dinners, so a bare
     * `$household->delete()` fails for any household with a recipe. Tombstoned
     * rows go too — the household is gone, so there is nothing left to sync.
     * Members who had it active have their current_household_id nulled by the
     * foreign key.
     */
    public function handle(Household $household): void
    {
        DB::transaction(function () use ($household): void {
            // Sync batches write under this lock (see AllocateSyncVersion): one
            // that adds a recipe mid-delete would trip the RESTRICT on
            // dinner_items.ingredient_id.
            Household::query()->lockForUpdate()->findOrFail($household->id);

            ShoppingListItem::withTrashed()
                ->whereIn('shopping_list_id', $household->shoppingLists()->withTrashed()->select('id'))
                ->forceDelete();
            $household->shoppingLists()->withTrashed()->forceDelete();

            DinnerPlanEntry::withTrashed()
                ->whereIn('dinner_plan_id', $household->dinnerPlans()->withTrashed()->select('id'))
                ->forceDelete();
            $household->dinnerPlans()->withTrashed()->forceDelete();

            DinnerItem::withTrashed()
                ->whereIn('dinner_id', $household->dinners()->withTrashed()->select('id'))
                ->forceDelete();
            $household->dinners()->withTrashed()->forceDelete();

            $household->ingredients()->withTrashed()->forceDelete();

            // API and MCP tokens are told apart from app tokens by their pin to
            // this household, which the foreign key deletes with it. Revoke them
            // first, or they would pass as unrestricted mobile-app tokens.
            $pinned = ApiTokenDetail::query()->where('household_id', $household->id)->select('token_id');
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', clone $pinned)->update(['revoked' => true]);
            Passport::token()->newQuery()->whereIn('id', $pinned)->update(['revoked' => true]);

            $household->delete();
        });
    }
}
