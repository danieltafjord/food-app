<?php

namespace App\Observers;

use App\Actions\Notifications\RecordHouseholdActivity;
use App\Enums\HouseholdActivityKind;
use App\Models\DinnerPlanEntry;
use Illuminate\Support\Facades\Auth;

/**
 * Notes plan entries a member adds, moves, swaps or removes, for the rest of
 * the household's notifications. A removal or move keeps the day it left, so
 * the notification can say what that day looks like now.
 */
class DinnerPlanEntryObserver
{
    public function __construct(private RecordHouseholdActivity $record) {}

    public function created(DinnerPlanEntry $entry): void
    {
        if (! $entry->trashed()) {
            $this->note(HouseholdActivityKind::PlanEntryAdded, $entry);
        }
    }

    public function updated(DinnerPlanEntry $entry): void
    {
        if ($entry->wasChanged('deleted_at')) {
            $entry->trashed()
                ? $this->note(HouseholdActivityKind::PlanEntryRemoved, $entry, ['date' => $this->originalDate($entry)])
                : $this->note(HouseholdActivityKind::PlanEntryAdded, $entry);

            return;
        }
        if (! $entry->trashed() && $entry->wasChanged(['dinner_id', 'scheduled_date', 'meal_type'])) {
            $this->note(HouseholdActivityKind::PlanEntryChanged, $entry, ['date' => $this->originalDate($entry)]);
        }
    }

    /** A delete through the REST API (the sync batch tombstones through `updated`). */
    public function trashed(DinnerPlanEntry $entry): void
    {
        $this->note(HouseholdActivityKind::PlanEntryRemoved, $entry, ['date' => $this->originalDate($entry)]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function note(HouseholdActivityKind $kind, DinnerPlanEntry $entry, ?array $payload = null): void
    {
        $userId = Auth::id();
        if ($userId === null) {
            return;
        }

        $this->record->planEntry($kind, (int) $userId, (int) $entry->dinner_plan_id, (int) $entry->getKey(), $payload);
    }

    private function originalDate(DinnerPlanEntry $entry): ?string
    {
        $date = $entry->getRawOriginal('scheduled_date');

        return is_string($date) ? substr($date, 0, 10) : null;
    }
}
