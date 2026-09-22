<?php

use App\Actions\ApiTokens\CreateApiToken;
use App\Models\AdminAction;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;

test('the users page lists, searches and filters users', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    [$member, $household] = ownerWithHousehold();
    $member->update(['name' => 'Bob Builder', 'email' => 'bob@example.com']);
    User::factory()->deactivated()->create(['name' => 'Dana Dormant']);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Users')
            ->has('users.data', 3)
            ->where('users.data.0.name', 'Dana Dormant')
            ->where('users.data.1.name', 'Bob Builder')
            ->where('users.data.1.households_count', 1)
            ->where('users.data.1.is_admin', false)
            ->where('users.data.1.deactivated_at', null)
            ->where('users.data.2.is_admin', true)
            ->where('filters', ['search' => '', 'status' => 'all', 'household' => null, 'sort' => 'joined', 'direction' => 'desc'])
        );

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['search' => 'bob@']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.email', 'bob@example.com'));

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['status' => 'deactivated']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Dana Dormant'));

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['status' => 'admins']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Ada Admin'));
});

test('users can be found by id, filtered by household and sorted', function () {
    $admin = User::factory()->admin()->create(['name' => 'Zed Admin']);
    [$member, $household] = ownerWithHousehold();
    $member->update(['name' => 'Anna Member']);
    $other = User::factory()->create(['name' => 'Mid User', 'ai_suggestions_enabled' => true]);

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['search' => "#{$member->id}"]))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.id', $member->id));

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['household' => $household->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.id', $member->id)
            ->where('filters.household', ['id' => $household->id, 'name' => $household->name]));

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['status' => 'ai']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.id', $other->id));

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['sort' => 'name', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.data.0.name', 'Anna Member')
            ->where('users.data.2.name', 'Zed Admin')
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc'));

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['sort' => 'households', 'direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page->where('users.data.0.id', $member->id));

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['sort' => 'bogus', 'direction' => 'sideways']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'joined')->where('filters.direction', 'desc'));
});

test('the user detail page shows households, tokens, apps, AI requests and the audit trail', function () {
    $admin = User::factory()->admin()->create();
    [$user, $household] = ownerWithHousehold();
    app(CreateApiToken::class)->handle($user, $household, 'Home Assistant', true);
    AiRequest::factory()->count(2)->for($user)->for($household)->create(['feature' => 'suggestions', 'cost' => 0.001]);
    AiRequest::factory()->failed()->for($user)->for($household)->create();
    AdminAction::factory()->for($admin, 'admin')->for($user, 'subjectUser')->create(['action' => AdminAction::USER_DEACTIVATED, 'changes' => null]);

    $this->actingAs($admin)
        ->get(route('admin.users.show', $user))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/UserShow')
            ->where('user.id', $user->id)
            ->where('user.households_count', 1)
            ->has('households', 1)
            ->where('households.0.name', $household->name)
            ->where('households.0.role', 'owner')
            ->where('households.0.members_count', 1)
            ->where('households.0.is_current', true)
            ->has('tokens', 1)
            ->where('tokens.0.name', 'Home Assistant')
            ->where('tokens.0.can_write', true)
            ->has('connectedApps', 0)
            ->has('aiRequests', 3)
            ->where('aiTotals.requests', 3)
            ->where('aiTotals.last_30_days', 3)
            ->where('aiTotals.cost', 0.002)
            ->has('actions', 1)
            ->where('actions.0.action', AdminAction::USER_DEACTIVATED)
        );

    $this->actingAs(User::factory()->create())->get(route('admin.users.show', $user))->assertForbidden();
});

test('regular users cannot reach user management', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.users.deactivate', $other))->assertForbidden();
    $this->actingAs($user)->patch(route('admin.users.update', $other), ['name' => 'x', 'email' => 'x@example.com', 'is_admin' => true, 'email_verified' => true])->assertForbidden();

    expect($other->refresh()->deactivated_at)->toBeNull()->and($other->name)->not->toBe('x');
});

test('an admin can edit a user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->unverified()->create();
    $originalName = $user->name;

    $this->actingAs($admin)
        ->from(route('admin.users.index'))
        ->patch(route('admin.users.update', $user), ['name' => ' New Name ', 'email' => 'new@example.com', 'is_admin' => true, 'email_verified' => true])
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('new@example.com')
        ->and($user->is_admin)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    $entry = AdminAction::query()->sole();
    expect($entry->action)->toBe(AdminAction::USER_UPDATED)
        ->and($entry->admin_id)->toBe($admin->id)
        ->and($entry->admin_name)->toBe($admin->name)
        ->and($entry->subject_user_id)->toBe($user->id)
        ->and($entry->changes)->toHaveKeys(['name', 'email', 'is_admin', 'email_verified'])
        ->and($entry->changes['name'])->toBe(['from' => $originalName, 'to' => 'New Name'])
        ->and($entry->changes['is_admin'])->toBe(['from' => false, 'to' => true]);

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $user), ['name' => 'New Name', 'email' => 'new@example.com', 'is_admin' => false, 'email_verified' => false])
        ->assertSessionHasNoErrors();
    expect($user->refresh()->email_verified_at)->toBeNull()->and($user->is_admin)->toBeFalse();
});

test('user edits are validated', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $user), ['name' => '', 'email' => 'taken@example.com', 'is_admin' => 'maybe', 'email_verified' => true])
        ->assertSessionHasErrors(['name', 'email', 'is_admin']);

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'is_admin' => false, 'email_verified' => true])
        ->assertSessionHasErrors(['is_admin']);
    expect($admin->refresh()->is_admin)->toBeTrue();
});

test('deactivating a user revokes tokens and sessions and blocks every entry point', function () {
    $admin = User::factory()->admin()->create();
    [$user, $household] = ownerWithHousehold();
    $apiToken = app(CreateApiToken::class)->handle($user, $household, 'Integration', false)->accessToken;
    $mobileToken = $user->createToken('phone')->accessToken;
    DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->withToken($mobileToken)->getJson('/api/v1/me')->assertOk();

    $this->actingAs($admin)->from(route('admin.users.index'))
        ->post(route('admin.users.deactivate', $user))
        ->assertRedirect(route('admin.users.index'));

    expect($user->refresh()->deactivated_at)->not->toBeNull();
    $this->assertDatabaseHas('admin_actions', ['action' => AdminAction::USER_DEACTIVATED, 'admin_id' => $admin->id, 'subject_user_id' => $user->id]);
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    expect($user->tokens()->where('revoked', false)->count())->toBe(0);

    // Guards cache the resolved user within one test; production requests start fresh.
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->withToken($mobileToken)->getJson('/api/v1/me')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->withToken($apiToken)->getJson('/api/public/v1/me')->assertUnauthorized();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account has been deactivated.']);
    // Earlier API requests switched the default guard to "api", so name the web guard explicitly.
    $this->assertGuest('web');

    // A session that somehow survives (e.g. passkey login) is logged out on the next request.
    $this->actingAs($user, 'web')->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest('web');

    // Even a fresh, unrevoked token is refused while deactivated.
    app('auth')->forgetGuards();
    Passport::actingAs($user->refresh());
    $this->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('code', 'deactivated');
});

test('a deactivated user can be reactivated and log in again', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->deactivated()->create();

    $this->actingAs($admin)->post(route('admin.users.reactivate', $user))->assertRedirect();
    expect($user->refresh()->deactivated_at)->toBeNull();
    $this->assertDatabaseHas('admin_actions', ['action' => AdminAction::USER_REACTIVATED, 'subject_user_id' => $user->id]);

    $this->post(route('logout'));
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('admins cannot deactivate or delete themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.users.deactivate', $admin))->assertStatus(422);
    $this->actingAs($admin)->delete(route('admin.users.destroy', $admin), ['confirmation' => $admin->email])->assertStatus(422);
    expect($admin->refresh()->deactivated_at)->toBeNull();
    $this->assertModelExists($admin);
});

test('an admin can delete a user account after typing its e-mail', function () {
    $admin = User::factory()->admin()->create();
    [$user, $household] = ownerWithHousehold();

    $this->actingAs($admin)->from(route('admin.users.index'))
        ->delete(route('admin.users.destroy', $user), ['confirmation' => 'someone-else@example.com'])
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHasErrors(['confirmation']);
    $this->assertModelExists($user);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $user), ['confirmation' => ' '.mb_strtoupper($user->email).' '])
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($user);
    $this->assertModelMissing($household);

    $entry = AdminAction::query()->sole();
    expect($entry->action)->toBe(AdminAction::USER_DELETED)
        ->and($entry->subject_user_id)->toBeNull()
        ->and($entry->subject_label)->toContain($user->email)
        ->and($entry->changes)->toBe(['households' => 1]);
});
