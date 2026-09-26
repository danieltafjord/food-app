<?php

namespace App\Actions\Notifications;

use App\Enums\AppLocale;
use App\Enums\HouseholdActivityKind;
use App\Enums\NotificationTopic;
use App\Models\Dinner;
use App\Models\DinnerPlanEntry;
use App\Models\Household;
use App\Models\HouseholdActivity;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Notifications\HouseholdActivityNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Bundles recorded household activity into push notifications for the other
 * members. Runs as SendHouseholdNotifications jobs, which RecordHouseholdActivity
 * dispatches for each batch it stores.
 *
 * Activity is grouped per member and per thing (a shopping list, the plan,
 * the household), and most groups wait until that member has been quiet for
 * QUIET_SECONDS, so eight items typed one by one become "Kari added milk,
 * eggs and 6 more". Two things go out without waiting:
 *
 *  - The first tick of a shopping trip ("Kari started shopping"), so others
 *    can still add to the list while she is in the shop. Ticks within
 *    TRIP_GAP_MINUTES of a notified one belong to the same trip; once they
 *    go quiet with the whole list ticked, "Kari checked off everything".
 *  - Someone joining the household.
 *
 * Plan changes only notify about today and tomorrow (in the recipient's time
 * zone), except a bulk plan of PLANNED_WEEK_MIN or more new dinners, which is
 * one "Kari planned 5 dinners" summary.
 *
 * Recipients are the other members who have the household active in the app,
 * a registered install, and the topic turned on. Activity is marked notified
 * whether or not anyone was told, and anything older than STALE_MINUTES (the
 * queue was down) is dropped rather than sent late.
 */
class SendHouseholdActivityNotifications
{
    public const QUIET_SECONDS = 120;

    public const TRIP_GAP_MINUTES = 120;

    public const PLANNED_WEEK_MIN = 3;

    public const STALE_MINUTES = 60;

    /** Items or dinners named in one notification before "and N more". */
    private const NAMED = 3;

    private CarbonImmutable $now;

    /**
     * @param  int|null  $householdId  One household, or every household with pending activity.
     * @return int Notifications sent.
     */
    public function handle(?int $householdId = null): int
    {
        $this->now = CarbonImmutable::now();
        $pendingActivity = fn () => HouseholdActivity::query()
            ->whereNull('notified_at')
            ->when($householdId !== null, fn ($query) => $query->where('household_id', $householdId));

        // Deleted, not marked notified: a dropped tick must not count as a
        // trip underway and silence the next "started shopping".
        $pendingActivity()
            ->where('created_at', '<', $this->now->subMinutes(self::STALE_MINUTES))
            ->delete();

        $pending = $pendingActivity()->orderBy('id')->get();

        $sent = 0;
        foreach ($pending->groupBy(fn (HouseholdActivity $activity): string => $activity->household_id.'|'.$activity->user_id.'|'.$this->groupOf($activity)) as $group) {
            $household = Household::query()->find($group->first()->household_id);
            $actor = User::query()->find($group->first()->user_id);
            if ($household === null || $actor === null) {
                $this->markNotified($group);

                continue;
            }

            $sent += match ($this->groupOf($group->first())) {
                'member' => $this->memberJoined($household, $actor, $group),
                'plan' => $this->planChanged($household, $actor, $group),
                default => $this->listActivity($household, $actor, $group),
            };
        }

        return $sent;
    }

    private function groupOf(HouseholdActivity $activity): string
    {
        return match (true) {
            $activity->kind === HouseholdActivityKind::MemberJoined => 'member',
            $activity->kind->isPlan() => 'plan',
            default => 'list.'.$activity->shopping_list_id,
        };
    }

    /**
     * @param  Collection<int, HouseholdActivity>  $group
     */
    private function memberJoined(Household $household, User $actor, Collection $group): int
    {
        $this->markNotified($group);

        return $this->notify($household, $actor, NotificationTopic::Household, null, fn (User $recipient): array => [
            $household->name,
            __('notifications.member_joined', ['name' => $this->firstName($actor)], $this->locale($recipient)),
            ['url' => '/account'],
        ]);
    }

    /**
     * @param  Collection<int, HouseholdActivity>  $group
     */
    private function listActivity(Household $household, User $actor, Collection $group): int
    {
        $list = ShoppingList::query()->whereKey($group->first()->shopping_list_id)->whereNull('archived_at')->first();
        if ($list === null) {
            $this->markNotified($group);

            return 0;
        }

        $sent = 0;
        $added = $group->where('kind', HouseholdActivityKind::ItemAdded);
        if ($added->isNotEmpty() && $this->isQuiet($added)) {
            $this->markNotified($added);
            $sent += $this->itemsAdded($household, $actor, $list, $added);
        }

        $checked = $group->where('kind', HouseholdActivityKind::ItemChecked);
        if ($checked->isNotEmpty()) {
            $sent += $this->shoppingTrip($household, $actor, $list, $checked);
        }

        return $sent;
    }

    /**
     * @param  Collection<int, HouseholdActivity>  $added
     */
    private function itemsAdded(Household $household, User $actor, ShoppingList $list, Collection $added): int
    {
        // Only what is still on the list and still to buy.
        $names = ShoppingListItem::query()
            ->whereKey($added->pluck('subject_id')->unique()->all())
            ->where('shopping_list_id', $list->id)
            ->where('is_checked', false)
            ->with('ingredient')
            ->orderBy('id')
            ->get()
            ->map(fn (ShoppingListItem $item): ?string => $item->name ?? $item->ingredient?->name)
            ->filter()
            ->unique(fn (string $name): string => Str::lower($name))
            ->values();
        if ($names->isEmpty()) {
            return 0;
        }

        return $this->notify($household, $actor, NotificationTopic::ListItems, $list->uuid, function (User $recipient) use ($actor, $list, $names): array {
            $locale = $this->locale($recipient);
            $name = $this->firstName($actor);
            $body = match (true) {
                $names->count() === 1 => __('notifications.items_added_one', ['name' => $name, 'item' => $names->first()], $locale),
                $names->count() <= self::NAMED => __('notifications.items_added_few', ['name' => $name, 'items' => $this->join($names, $locale)], $locale),
                default => __('notifications.items_added_more', [
                    'name' => $name,
                    'items' => $names->take(self::NAMED - 1)->join(', '),
                    'count' => $names->count() - (self::NAMED - 1),
                ], $locale),
            };

            return [$list->name, $body, $this->listData($list)];
        });
    }

    /**
     * @param  Collection<int, HouseholdActivity>  $checked
     */
    private function shoppingTrip(Household $household, User $actor, ShoppingList $list, Collection $checked): int
    {
        $tripUnderway = HouseholdActivity::query()
            ->where('household_id', $household->id)
            ->where('user_id', $actor->id)
            ->where('shopping_list_id', $list->id)
            ->where('kind', HouseholdActivityKind::ItemChecked)
            ->where('notified_at', '>=', $this->now->subMinutes(self::TRIP_GAP_MINUTES))
            ->exists();
        $quiet = $this->isQuiet($checked);
        $items = ShoppingListItem::query()->where('shopping_list_id', $list->id);
        $left = (clone $items)->where('is_checked', false)->count();
        $finished = $left === 0 && $items->exists();

        // Ticks mid-trip wait for the end of the trip; a trip ticked off
        // entirely before the first notification went out is only "finished".
        if (! $quiet && ($tripUnderway || $finished)) {
            return 0;
        }
        $this->markNotified($checked);
        if ($tripUnderway && ! $finished) {
            return 0;
        }

        return $this->notify($household, $actor, NotificationTopic::Shopping, $list->uuid, fn (User $recipient): array => [
            $list->name,
            $finished
                ? __('notifications.shopping_finished', ['name' => $this->firstName($actor)], $this->locale($recipient))
                : trans_choice('notifications.shopping_started', $left, ['name' => $this->firstName($actor)], $this->locale($recipient)),
            $this->listData($list),
        ]);
    }

    /**
     * @param  Collection<int, HouseholdActivity>  $group
     */
    private function planChanged(Household $household, User $actor, Collection $group): int
    {
        if (! $this->isQuiet($group)) {
            return 0;
        }
        $this->markNotified($group);

        $entries = DinnerPlanEntry::withTrashed()->whereKey($group->pluck('subject_id')->unique()->all())->get()->keyBy('id');
        $added = $group->where('kind', HouseholdActivityKind::PlanEntryAdded)
            ->map(fn (HouseholdActivity $activity): ?DinnerPlanEntry => $entries->get($activity->subject_id))
            ->filter(fn (?DinnerPlanEntry $entry): bool => $entry !== null && ! $entry->trashed())
            ->unique('id')
            ->sortBy(fn (DinnerPlanEntry $entry): string => $entry->scheduled_date->toDateString())
            ->values();

        if ($added->count() >= self::PLANNED_WEEK_MIN) {
            return $this->weekPlanned($household, $actor, $added);
        }

        $days = [];
        foreach ($group as $activity) {
            $entry = $entries->get($activity->subject_id);
            if ($entry !== null && ! $entry->trashed()) {
                $days[] = $entry->scheduled_date->toDateString();
            }
            if (is_string($activity->payload['date'] ?? null)) {
                $days[] = $activity->payload['date'];
            }
        }
        $days = array_unique($days);

        return $this->notify($household, $actor, NotificationTopic::Plan, null, function (User $recipient) use ($household, $actor, $days): ?array {
            $today = $this->now->setTimezone($this->timezone($recipient));
            $locale = $this->locale($recipient);
            $summary = [];
            foreach (['plan_today' => $today, 'plan_tomorrow' => $today->addDay()] as $key => $day) {
                if (in_array($day->toDateString(), $days, true)) {
                    $dinners = $this->dinnersOn($household, $day->toDateString());
                    $summary[] = __("notifications.{$key}", [
                        'dinners' => $dinners->isEmpty() ? __('notifications.plan_nothing', [], $locale) : $this->join($dinners, $locale),
                    ], $locale);
                }
            }
            if ($summary === []) {
                return null;
            }

            return [
                __('notifications.plan_title', [], $locale),
                __('notifications.plan_changed', ['name' => $this->firstName($actor), 'days' => implode(' ', array_map(fn (string $line): string => $line.'.', $summary))], $locale),
                $this->weekData($today),
            ];
        });
    }

    /**
     * @param  Collection<int, DinnerPlanEntry>  $added
     */
    private function weekPlanned(Household $household, User $actor, Collection $added): int
    {
        $names = Dinner::withTrashed()->whereKey($added->pluck('dinner_id')->unique()->all())->pluck('name', 'id');
        $dinners = $added->map(fn (DinnerPlanEntry $entry): ?string => $names->get($entry->dinner_id))->filter()->values();
        $first = CarbonImmutable::parse($added->first()->scheduled_date->toDateString());

        return $this->notify($household, $actor, NotificationTopic::Plan, null, fn (User $recipient): array => [
            __('notifications.plan_title', [], $this->locale($recipient)),
            trans_choice('notifications.plan_planned', $added->count(), [
                'name' => $this->firstName($actor),
                'dinners' => $dinners->take(self::NAMED)->join(', ').($dinners->count() > self::NAMED ? ' …' : ''),
            ], $this->locale($recipient)),
            $this->weekData($first),
        ]);
    }

    /**
     * Tell each interested member. The message callback returns
     * `[title, body, data]`, or null to skip that member.
     *
     * @param  callable(User): (array{0: string, 1: string, 2: array<string, string>}|null)  $message
     */
    private function notify(Household $household, User $actor, NotificationTopic $topic, ?string $shoppingListUuid, callable $message): int
    {
        $recipients = $household->members()
            ->whereKeyNot($actor->id)
            ->where('current_household_id', $household->id)
            ->whereNull('deactivated_at')
            ->whereHas('pushTokens', fn ($query) => $query->deliverable())
            ->with(['pushTokens' => fn ($query) => $query->deliverable()->latest('updated_at')])
            ->get()
            ->filter(fn (User $recipient): bool => $recipient->wantsNotification($topic, $shoppingListUuid));

        $sent = 0;
        foreach ($recipients as $recipient) {
            $content = $message($recipient);
            if ($content === null) {
                continue;
            }
            [$title, $body, $data] = $content;
            $recipient->notify(new HouseholdActivityNotification($title, $body, $data, $data['scope'] ?? null));
            $sent++;
        }

        return $sent;
    }

    /**
     * @param  Collection<int, HouseholdActivity>  $activities
     */
    private function isQuiet(Collection $activities): bool
    {
        return $activities->max('created_at')->lessThanOrEqualTo($this->now->subSeconds(self::QUIET_SECONDS));
    }

    /**
     * @param  Collection<int, HouseholdActivity>  $activities
     */
    private function markNotified(Collection $activities): void
    {
        HouseholdActivity::query()->whereKey($activities->pluck('id')->all())->update(['notified_at' => $this->now]);
    }

    /** @return Collection<int, string> */
    private function dinnersOn(Household $household, string $date): Collection
    {
        $dinnerIds = DinnerPlanEntry::query()
            ->whereHas('dinnerPlan', fn (Builder $query) => $query->where('household_id', $household->id))
            ->whereDate('scheduled_date', $date)
            ->orderBy('id')
            ->pluck('dinner_id');
        $names = Dinner::withTrashed()->whereKey($dinnerIds->unique()->all())->pluck('name', 'id');

        return $dinnerIds
            ->map(fn (int $id): ?string => $names->get($id))
            ->filter()
            ->unique()
            ->values();
    }

    /** @return array<string, string> */
    private function listData(ShoppingList $list): array
    {
        return ['url' => '/shopping/'.$list->uuid, 'scope' => 'list.'.$list->uuid];
    }

    /** @return array<string, string> */
    private function weekData(CarbonImmutable $day): array
    {
        $monday = $day->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

        return ['url' => '/', 'week' => $monday, 'scope' => 'week.'.$monday];
    }

    /**
     * @param  Collection<int, string>  $names
     */
    private function join(Collection $names, string $locale): string
    {
        return $names->join(', ', ' '.__('notifications.and', [], $locale).' ');
    }

    private function firstName(User $user): string
    {
        return Str::of($user->name)->trim()->before(' ')->toString() ?: $user->name;
    }

    private function locale(User $user): string
    {
        return $user->locale === AppLocale::Norwegian ? 'no' : 'en';
    }

    /** The time zone of the recipient's most recently registered install. */
    private function timezone(User $user): string
    {
        $timezone = $user->pushTokens->first()?->timezone;

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Europe/Oslo';
    }
}
