<?php

use App\Enums\HouseholdRole;
use App\Events\HouseholdDataChanged;
use App\Events\HouseholdMemberRemoved;
use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

beforeEach(function () {
    [$this->user, $this->household] = ownerWithHousehold();
    Passport::actingAs($this->user);
});

/** Switch broadcasting to a Reverb connection (no server needed to sign channel auth). */
function useReverb(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'app-key',
        'broadcasting.connections.reverb.secret' => 'app-secret',
        'broadcasting.connections.reverb.app_id' => 'app-id',
        'broadcasting.connections.reverb.options.host' => 'reverb.internal',
        'broadcasting.connections.reverb.public' => ['host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https'],
    ]);
    // Channels register on the broadcaster that was default at boot.
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
}

function authorizeChannel(string $channel): TestResponse
{
    return test()->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

it('announces a sync push to the household once, with the committed version', function () {
    Event::fake([HouseholdDataChanged::class]);

    $response = $this->withHeader('X-Socket-ID', '111.222')->postJson('/api/v1/sync', [
        'cursor' => null,
        'household_id' => $this->household->id,
        'changes' => ['shopping_lists' => [[
            'id' => (string) Str::uuid(),
            'name' => 'Weekly shop',
            'updated_at' => now()->toISOString(),
            'created_at' => now()->toISOString(),
        ]]],
    ])->assertOk();

    Event::assertDispatchedTimes(HouseholdDataChanged::class, 1);
    Event::assertDispatched(HouseholdDataChanged::class, fn (HouseholdDataChanged $event) => $event->householdId === $this->household->id
        && $event->version === $response->json('cursor')
        && $event->socket === '111.222');
});

it('does not announce a pull that changed nothing', function () {
    Event::fake([HouseholdDataChanged::class]);
    $cursor = $this->postJson('/api/v1/sync', ['cursor' => null, 'household_id' => $this->household->id])->json('cursor');

    $this->postJson('/api/v1/sync', ['cursor' => $cursor, 'household_id' => $this->household->id])->assertOk();

    Event::assertNotDispatched(HouseholdDataChanged::class);
});

it('announces REST writes too', function () {
    Event::fake([HouseholdDataChanged::class]);

    $this->postJson('/api/v1/shopping-lists', ['name' => 'Weekly shop'])->assertCreated();

    Event::assertDispatched(HouseholdDataChanged::class, fn (HouseholdDataChanged $event) => $event->householdId === $this->household->id
        && $event->version === (int) $this->household->fresh()->sync_version);
});

it('does not announce a write that rolled back', function () {
    Event::fake([HouseholdDataChanged::class]);

    try {
        DB::transaction(function () {
            ShoppingList::factory()->for($this->household)->create();

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    defer()->invoke();

    Event::assertNotDispatched(HouseholdDataChanged::class);
});

it('keeps the write when the broadcast fails', function () {
    Event::listen(HouseholdDataChanged::class, fn () => throw new RuntimeException('Reverb is down'));

    $this->postJson('/api/v1/shopping-lists', ['name' => 'Weekly shop'])->assertCreated();

    expect($this->household->shoppingLists()->count())->toBe(1);
});

it('lets members listen to their household and see who is on a list or week', function () {
    useReverb();

    authorizeChannel('private-household.'.$this->household->id)->assertOk()->assertJsonStructure(['auth']);

    $list = authorizeChannel('presence-household.'.$this->household->id.'.list.'.Str::uuid())->assertOk();
    expect(json_decode($list->json('channel_data'), true))->toBe([
        'user_id' => (string) $this->user->id,
        'user_info' => ['id' => $this->user->id, 'name' => $this->user->name],
    ]);

    authorizeChannel('presence-household.'.$this->household->id.'.week.2026-09-21')->assertOk();
});

it('keeps other households and malformed channels closed', function () {
    useReverb();
    $other = Household::factory()->create();
    $other->members()->attach(User::factory()->create(), ['role' => 'owner']);

    authorizeChannel('private-household.'.$other->id)->assertForbidden();
    authorizeChannel('presence-household.'.$other->id.'.list.'.Str::uuid())->assertForbidden();
    authorizeChannel('presence-household.'.$this->household->id.'.list.not-a-uuid')->assertForbidden();
    authorizeChannel('presence-household.'.$this->household->id.'.week.monday')->assertForbidden();
});

it('tells the household when a member is removed or leaves, and keeps them off its channel', function (bool $leaves) {
    Event::fake([HouseholdMemberRemoved::class]);
    useReverb();
    $member = User::factory()->create(['current_household_id' => $this->household->id]);
    $this->household->members()->attach($member, ['role' => HouseholdRole::Member->value]);
    Passport::actingAs($member);
    authorizeChannel('private-household.'.$this->household->id)->assertOk();

    Passport::actingAs($leaves ? $member : $this->user);
    $this->deleteJson("/api/v1/household/members/{$member->id}")->assertNoContent();

    Event::assertDispatchedTimes(HouseholdMemberRemoved::class, 1);
    Event::assertDispatched(HouseholdMemberRemoved::class, fn (HouseholdMemberRemoved $event): bool => $event->broadcastOn()[0]->name === 'private-household.'.$this->household->id
        && $event->broadcastAs() === 'household.member-removed'
        && $event->broadcastWith() === ['user_id' => $member->id]);

    Passport::actingAs($member);
    authorizeChannel('private-household.'.$this->household->id)->assertForbidden();
})->with(['removed' => false, 'leaves' => true]);

it('refuses channel auth without an app token', function () {
    useReverb();
    app('auth')->forgetGuards();

    $this->withToken('nope')->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-household.'.$this->household->id,
    ])->assertUnauthorized();
});

it('tells the app where to open its socket', function () {
    $this->getJson('/api/v1/realtime')->assertOk()->assertExactJson(['data' => null]);

    useReverb();

    $this->getJson('/api/v1/realtime')->assertOk()->assertExactJson(['data' => [
        'key' => 'app-key',
        'host' => 'ws.example.test',
        'port' => 443,
        'scheme' => 'https',
    ]]);
});
