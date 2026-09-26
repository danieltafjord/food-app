<?php

namespace App\Observers;

use App\Actions\Notifications\RecordHouseholdActivity;
use App\Enums\HouseholdActivityKind;
use App\Models\ShoppingListItem;
use Illuminate\Support\Facades\Auth;

/**
 * Notes items a member adds or ticks off, for the rest of the household's
 * notifications. Rows the planner generates are left out: the plan change
 * that caused them is notified on its own.
 */
class ShoppingListItemObserver
{
    public function __construct(private RecordHouseholdActivity $record) {}

    public function created(ShoppingListItem $item): void
    {
        $userId = Auth::id();
        if ($userId === null || $item->trashed() || $item->is_generated || $item->is_checked) {
            return;
        }

        $this->record->item(HouseholdActivityKind::ItemAdded, (int) $userId, (int) $item->shopping_list_id, (int) $item->getKey());
    }

    public function updated(ShoppingListItem $item): void
    {
        $userId = Auth::id();
        if ($userId === null || $item->trashed() || ! $item->wasChanged('is_checked') || ! $item->is_checked) {
            return;
        }

        $this->record->item(HouseholdActivityKind::ItemChecked, (int) $userId, (int) $item->shopping_list_id, (int) $item->getKey());
    }
}
