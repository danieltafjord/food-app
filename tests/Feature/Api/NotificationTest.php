<?php

use App\Actions\Notifications\SendHouseholdActivityNotifications;
use App\Enums\HouseholdRole;
use App\Jobs\SendHouseholdNotifications;
use App\Models\Dinner;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\HouseholdActivity;
use App\Models\HouseholdInvitation;
use App\Models\PushToken;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use App\Notifications\HouseholdActivityNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

beforeEach(function () {
    [$this->kari, $this->household] = ownerWithHousehold();
    $this->kari->update(['name' => 'Kari Nordmann']);
    $this->ola = User::factory()->create(['name' => 'Ola', 'current_household_id' => $this->household->id]);
    $this->household->members()->attach($this->ola, ['role' => HouseholdRole::Member->value]);
    PushToken::factory()->for($this->ola)->create(['timezone' => 'Europe/Oslo']);
    $this->list = ShoppingList::factory()->for($this->household)->create(['name' => 'Groceries']);

    Notification::fake();
    Queue::fake();
    Passport::actingAs($this->kari);
});

/**
 * Push new items to the list through sync, as the app does.
 *
 * @param  list<string>  $names
 */
function syncNewItems(ShoppingList $list, array $names): void
{
    $now = now()->toISOString();
    test()->postJson('/api/v1/sync', [
        'cursor' => null,
        'changes' => ['shopping_list_items' => array_map(fn (string $name): array => [
            'id' => (string) Str::uuid(),
            'shopping_list_id' => $list->uuid,
            'ingredient_id' => null,
            'name' => $name,
            'quantity' => null,
            'unit' => null,
            'is_checked' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $names)],
    ])->assertSuccessful();
}

function sendHouseholdActivity(): int
{
    return app(SendHouseholdActivityNotifications::class)->handle();
}

function afterQuietPeriod(): void
{
    test()->travel(SendHouseholdActivityNotifications::QUIET_SECONDS + 1)->seconds();
}

it('bundles items another member adds into one notification once they go quiet', function () {
    syncNewItems($this->list, ['Milk', 'Eggs']);
    syncNewItems($this->list, ['Bread', 'Butter', 'Cheese']);

    expect(sendHouseholdActivity())->toBe(0);

    afterQuietPeriod();
    expect(sendHouseholdActivity())->toBe(1)
        ->and(sendHouseholdActivity())->toBe(0);

    Notification::assertSentTo($this->ola, HouseholdActivityNotification::class, fn (HouseholdActivityNotification $notification): bool => $notification->title === 'Groceries'
        && $notification->body === 'Kari added Milk, Eggs and 3 more'
        && $notification->data === ['url' => '/shopping/'.$this->list->uuid, 'scope' => 'list.'.$this->list->uuid]);
    Notification::assertNotSentTo($this->kari, HouseholdActivityNotification::class);
});

it('schedules the household\'s notifications for after the quiet period, and at once for a first tick', function () {
    syncNewItems($this->list, ['Milk']);

    Queue::assertPushed(SendHouseholdNotifications::class, 1);
    Queue::assertPushed(SendHouseholdNotifications::class, fn (SendHouseholdNotifications $job): bool => $job->householdId === $this->household->id && $job->delay !== null);

    ShoppingListItem::query()->where('name', 'Milk')->first()->update(['is_checked' => true]);

    Queue::assertPushed(SendHouseholdNotifications::class, fn (SendHouseholdNotifications $job): bool => $job->delay === null);
});

it('sends the household\'s pending notifications when the job runs', function () {
    syncNewItems($this->list, ['Milk']);
    afterQuietPeriod();

    (new SendHouseholdNotifications($this->household->id))->handle(app(SendHouseholdActivityNotifications::class));

    Notification::assertSentTo($this->ola, HouseholdActivityNotification::class);
});

it('words notifications in the recipient\'s language', function () {
    $this->ola->update(['locale' => 'nb']);
    syncNewItems($this->list, ['Melk', 'Egg']);

    afterQuietPeriod();
    sendHouseholdActivity();

    Notification::assertSentTo($this->ola, HouseholdActivityNotification::class, fn (HouseholdActivityNotification $notification): bool => $notification->body === 'Kari la til Melk og Egg');
});

it('leaves out items that were ticked off or removed before the notification', function () {
    syncNewItems($this->list, ['Milk', 'Eggs']);
    ShoppingListItem::query()->where('name', 'Milk')->first()->delete();

    afterQuietPeriod();
    sendHouseholdActivity();

    Notification::assertSentTo($this->ola, HouseholdActivityNotification::class, fn (HouseholdActivityNotification $notification): bool => $notification->body === 'Kari added Eggs');
});

it('respects turned-off topics and muted lists', function () {
    $this->ola->forceFill(['notification_preferences' => ['muted_lists' => [$this->list->uuid]]])->save();
    syncNewItems($this->list, ['Milk']);
    afterQuietPeriod();
    expect(sendHouseholdActivity())->toBe(0);

    $this->ola->forceFill(['notification_preferences' => ['list_items' => false]])->save();
    syncNewItems($this->list, ['Eggs']);
    afterQuietPeriod();
    expect(sendHouseholdActivity())->toBe(0);

    Notification::assertNothingSent();
});

it('skips members who have another household open or no app install', function () {
    $this->ola->update(['current_household_id' => null]);
    syncNewItems($this->list, ['Milk']);
    afterQuietPeriod();

    expect(sendHouseholdActivity())->toBe(0);
});

it('records nothing in a household with a single member', function () {
    $this->household->members()->detach($this->ola);
    syncNewItems($this->list, ['Milk']);

    expect(HouseholdActivity::query()->count())->toBe(0);
});

it('leaves out items generated from the plan', function () {
    ShoppingListItem::factory()->for($this->list)->create(['is_generated' => true]);

    expect(HouseholdActivity::query()->count())->toBe(0);
});

it('announces a shopping trip when it starts and again when everything is ticked off', function () {
    $items = collect(['Milk', 'Eggs', 'Bread'])->map(fn (string $name) => ShoppingListItem::factory()->for($this->list)->create(['name' => $name, 'ingredient_id' => null]));
    HouseholdActivity::query()->delete();

    $items[0]->update(['is_checked' => true]);
    expect(sendHouseholdActivity())->toBe(1);
    Notification::assertSentTo($this->ola, HouseholdActivityNotification::class, fn (HouseholdActivityNotification $notification): bool => $notification->body === 'Kari started shopping. 2 items left.');

    $items[1]->update(['is_checked' => true]);
    afterQuietPeriod();
    expect(sendHouseholdActivity())->toBe(0);

    $items[2]->update(['is_checked' => true]);
    expect(sendHouseholdActivity())->toBe(0);
    afterQuietPeriod();
    expect(sendHouseholdActivity())->toBe(1);
    Notification::assertSentTo($this->ola, HouseholdActivityNotification::class, fn (HouseholdActivityNotification $notification): bool => $notification->body === 'Kari checked off everything.');
});

it('summarises a planned week in one notification', function () {
    $plan = DinnerPlan::factory()->for($this->household)->create();
    foreach (['Tacos', 'Pasta', 'Soup', 'Curry'] as $offset => $name) {
        DinnerPlanEntry::factory()->for($plan)->for(Dinner::factory()->for($this->household)->create(['name' => $name]))
            ->create(['scheduled_date' => today()->addDays(10 + $offset)]);
    }

    afterQuietPeriod();
    expect(sendHouseholdActivity())->toBe(1);

    Notification::assertSentTo($this->ola, HouseholdActivityNotification::class, fn (HouseholdActivityNotification $notification): bool => $notification->title === 'Dinner plan'
        && $notification->body === 'Kari planned 4 dinners: Tacos, Pasta, Soup …'
        && $notification->data['week'] === today()->addDays(10)->startOfWeek()->toDateString());
});

it('tells about changes to today\'s and tomorrow\'s dinners, but not later ones', function () {
    $plan = DinnerPlan::factory()->for($this->household)->create();
    $tacos = Dinner::factory()->for($this->household)->create(['name' => 'Tacos']);
    $pasta = Dinner::factory()->for($this->household)->create(['name' => 'Pasta']);
    $today = today('Europe/Oslo')->toDateString();
    $tonight = DinnerPlanEntry::factory()->for($plan)->for($tacos)->create(['scheduled_date' => $today]);
    $later = DinnerPlanEntry::factory()->for($plan)->for($tacos)->create(['scheduled_date' => today()->addMonth()]);
    HouseholdActivity::query()->delete();

    $later->update(['dinner_id' => $pasta->id]);
    afterQuietPeriod();
    expect(sendHouseholdActivity())->toBe(0);

    $tonight->update(['dinner_id' => $pasta->id]);
    afterQuietPeriod();
    expect(sendHouseholdActivity())->toBe(1);
    Notification::assertSentTo($this->ola, HouseholdActivityNotification::class, fn (HouseholdActivityNotification $notification): bool => $notification->body === 'Kari changed the plan. Today: Pasta.');

    $tonight->delete();
    afterQuietPeriod();
    expect(sendHouseholdActivity())->toBe(1);
    Notification::assertSentTo($this->ola, HouseholdActivityNotification::class, fn (HouseholdActivityNotification $notification): bool => $notification->body === 'Kari changed the plan. Today: nothing planned.');
});

it('tells the household when someone joins', function () {
    PushToken::factory()->for($this->kari)->create();
    $per = User::factory()->create(['name' => 'Per Hansen', 'email' => 'per@example.com']);
    $invitation = HouseholdInvitation::factory()->for($this->household)->create(['email' => 'per@example.com', 'role' => HouseholdRole::Member]);

    Passport::actingAs($per);
    $this->postJson("/api/v1/invitations/{$invitation->token}/accept")->assertSuccessful();

    expect(sendHouseholdActivity())->toBe(2);
    Notification::assertSentTo($this->kari, HouseholdActivityNotification::class, fn (HouseholdActivityNotification $notification): bool => $notification->body === 'Per joined the household.');
});

it('drops activity that was never sent in time', function () {
    syncNewItems($this->list, ['Milk']);
    $this->travel(SendHouseholdActivityNotifications::STALE_MINUTES + 1)->minutes();

    expect(sendHouseholdActivity())->toBe(0)
        ->and(HouseholdActivity::query()->whereNull('notified_at')->count())->toBe(0);
});

it('registers an install, moves it to whoever signs in there, and forgets it again', function () {
    $token = 'ExponentPushToken[abc123]';
    $this->putJson('/api/v1/me/push-token', ['token' => $token, 'platform' => 'ios', 'timezone' => 'Europe/Oslo'])->assertNoContent();
    expect(PushToken::query()->where('token', $token)->value('user_id'))->toBe($this->kari->id);

    Passport::actingAs($this->ola);
    $this->putJson('/api/v1/me/push-token', ['token' => $token, 'platform' => 'ios'])->assertNoContent();
    expect(PushToken::query()->where('token', $token)->value('user_id'))->toBe($this->ola->id);

    $this->deleteJson('/api/v1/me/push-token', ['token' => $token])->assertNoContent();
    expect(PushToken::query()->where('token', $token)->exists())->toBeFalse();

    $this->putJson('/api/v1/me/push-token', ['token' => 'not-a-token', 'platform' => 'ios'])->assertUnprocessable();
});

it('forgets the install when the device signs out', function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test Personal Access Client', '--no-interaction' => true]);
    $user = User::factory()->create();
    $accessToken = $user->createToken('Phone')->accessToken;
    PushToken::factory()->for($user)->create(['token' => 'ExponentPushToken[phone]']);
    app('auth')->forgetGuards();

    $this->withToken($accessToken)
        ->postJson('/api/v1/auth/logout', ['push_token' => 'ExponentPushToken[phone]'])
        ->assertSuccessful();

    expect(PushToken::query()->where('token', 'ExponentPushToken[phone]')->exists())->toBeFalse();
});

it('reads and updates notification preferences', function () {
    $this->getJson('/api/v1/me/notifications')
        ->assertSuccessful()
        ->assertExactJson(['data' => ['list_items' => true, 'shopping' => true, 'plan' => true, 'household' => true, 'muted_lists' => []]]);

    $this->patchJson('/api/v1/me/notifications', ['plan' => false, 'muted_lists' => [$this->list->uuid]])
        ->assertSuccessful()
        ->assertJsonPath('data.plan', false)
        ->assertJsonPath('data.shopping', true)
        ->assertJsonPath('data.muted_lists', [$this->list->uuid]);

    $this->patchJson('/api/v1/me/notifications', ['shopping' => false])
        ->assertJsonPath('data.plan', false)
        ->assertJsonPath('data.muted_lists', [$this->list->uuid]);

    $this->patchJson('/api/v1/me/notifications', ['muted_lists' => ['nope']])->assertUnprocessable();
});

it('sends through Expo and forgets installs Expo no longer knows', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [
        ['status' => 'ok', 'id' => 'ticket'],
        ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
    ]])]);
    $user = User::factory()->create();
    PushToken::factory()->for($user)->create(['token' => 'ExponentPushToken[live]']);
    $gone = PushToken::factory()->for($user)->create(['token' => 'ExponentPushToken[gone]']);

    app(ExpoPushChannel::class)->send($user, new HouseholdActivityNotification('Groceries', 'Kari added Milk', ['url' => '/'], 'list.x'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://exp.host/--/api/v2/push/send'
        && $request[0]['to'] === 'ExponentPushToken[live]'
        && $request[0]['title'] === 'Groceries'
        && $request[0]['threadId'] === 'list.x'
        && count($request->data()) === 2);
    expect(PushToken::query()->whereKey($gone->id)->exists())->toBeFalse()
        ->and($user->pushTokens()->count())->toBe(1);
});
