<?php

namespace App\Actions\Notifications;

use App\Enums\HouseholdActivityKind;
use App\Jobs\SendHouseholdNotifications;
use App\Models\DinnerPlan;
use App\Models\HouseholdActivity;
use App\Models\ShoppingList;
use Illuminate\Support\Facades\DB;

/**
 * Collects what the signed-in member did to shared data during a request and
 * stores it once the surrounding transaction commits, for
 * SendHouseholdActivityNotifications to bundle into pushes.
 *
 * The observers call this for every saved row, so it does no queries per row:
 * the households are resolved in one query per parent table when the rows are
 * stored, and a household with a single member stores nothing — nobody is
 * left to tell.
 *
 * Each stored batch schedules the household's notifications: right away when
 * it holds something that goes out at once, and again once the quiet period
 * has passed, when its edits can be bundled.
 *
 * Registered as a singleton so one request shares the buffer. A rolled-back
 * transaction drops it (see AppServiceProvider).
 */
class RecordHouseholdActivity
{
    /** @var list<array{kind: HouseholdActivityKind, user_id: int, household_id: ?int, shopping_list_id: ?int, dinner_plan_id: ?int, subject_id: ?int, payload: ?array<string, mixed>, created_at: string}> */
    private array $pending = [];

    public function item(HouseholdActivityKind $kind, int $userId, int $shoppingListId, int $itemId): void
    {
        $this->push($kind, $userId, shoppingListId: $shoppingListId, subjectId: $itemId);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function planEntry(HouseholdActivityKind $kind, int $userId, int $dinnerPlanId, int $entryId, ?array $payload = null): void
    {
        $this->push($kind, $userId, dinnerPlanId: $dinnerPlanId, subjectId: $entryId, payload: $payload);
    }

    public function memberJoined(int $householdId, int $userId): void
    {
        $this->push(HouseholdActivityKind::MemberJoined, $userId, householdId: $householdId);
    }

    /** Drop everything buffered (the transaction that wrote it rolled back). */
    public function forget(): void
    {
        $this->pending = [];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function push(HouseholdActivityKind $kind, int $userId, ?int $householdId = null, ?int $shoppingListId = null, ?int $dinnerPlanId = null, ?int $subjectId = null, ?array $payload = null): void
    {
        $this->pending[] = [
            'kind' => $kind,
            'user_id' => $userId,
            'household_id' => $householdId,
            'shopping_list_id' => $shoppingListId,
            'dinner_plan_id' => $dinnerPlanId,
            'subject_id' => $subjectId,
            'payload' => $payload,
            'created_at' => now()->format('Y-m-d H:i:s.u'),
        ];

        if (count($this->pending) === 1) {
            DB::afterCommit(fn () => $this->store());
        }
    }

    private function store(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        if ($pending === []) {
            return;
        }

        $listHouseholds = $this->householdsOf(ShoppingList::class, array_column($pending, 'shopping_list_id'));
        $planHouseholds = $this->householdsOf(DinnerPlan::class, array_column($pending, 'dinner_plan_id'));

        $rows = [];
        foreach ($pending as $activity) {
            $householdId = $activity['household_id']
                ?? $listHouseholds[$activity['shopping_list_id']] ?? $planHouseholds[$activity['dinner_plan_id']] ?? null;
            if ($householdId === null) {
                continue;
            }
            $rows[] = [
                'household_id' => $householdId,
                'user_id' => $activity['user_id'],
                'kind' => $activity['kind']->value,
                'shopping_list_id' => $activity['shopping_list_id'],
                'subject_id' => $activity['subject_id'],
                'payload' => $activity['payload'] !== null ? json_encode($activity['payload']) : null,
                'created_at' => $activity['created_at'],
            ];
        }

        $shared = DB::table('household_user')
            ->whereIn('household_id', array_unique(array_column($rows, 'household_id')))
            ->groupBy('household_id')
            ->havingRaw('count(*) > 1')
            ->pluck('household_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $rows = array_values(array_filter($rows, fn (array $row): bool => in_array($row['household_id'], $shared, true)));

        foreach (array_chunk($rows, 500) as $chunk) {
            HouseholdActivity::query()->insert($chunk);
        }

        $immediate = [HouseholdActivityKind::ItemChecked->value, HouseholdActivityKind::MemberJoined->value];
        foreach (collect($rows)->groupBy('household_id') as $householdId => $householdRows) {
            if ($householdRows->contains(fn (array $row): bool => in_array($row['kind'], $immediate, true))) {
                SendHouseholdNotifications::dispatch((int) $householdId);
            }
            SendHouseholdNotifications::dispatch((int) $householdId)
                ->delay(now()->addSeconds(SendHouseholdActivityNotifications::QUIET_SECONDS + 5));
        }
    }

    /**
     * @param  class-string<ShoppingList|DinnerPlan>  $model
     * @param  list<int|null>  $ids
     * @return array<int, int>
     */
    private function householdsOf(string $model, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        return $model::withTrashed()->whereKey($ids)->pluck('household_id', 'id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
