<?php

use App\Actions\Sync\AllocateSyncVersion;
use App\Actions\Users\DeleteAccount;
use App\Enums\HouseholdRole;
use App\Models\AiRequest;
use App\Models\ApiRequest;
use App\Models\Dinner;
use App\Models\DinnerItem;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\HouseholdInvitation;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

it('allows an unverified user to delete their account', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()->assertRedirect(route('home'));

    $this->assertGuest();
    $this->assertModelMissing($user);
});

it('permanently deletes a sole member household including previously deleted resources', function () {
    [$user, $household] = ownerWithHousehold();
    $ingredient = Ingredient::factory()->for($household)->create();
    $dinner = Dinner::factory()->for($household)->create();
    $item = DinnerItem::factory()->for($dinner)->for($ingredient)->create();
    $plan = DinnerPlan::factory()->for($household)->create();
    $entry = DinnerPlanEntry::factory()->for($plan)->for($dinner)->create();
    $list = ShoppingList::factory()->for($household)->for($plan)->create();
    $listItem = ShoppingListItem::factory()->for($list)->for($ingredient)->create();
    $invitation = HouseholdInvitation::factory()->for($household)->create();
    $dinner->delete();
    $list->delete();
    $plan->delete();
    $ingredient->delete();
    [, $unrelatedHousehold] = ownerWithHousehold();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()->assertRedirect(route('home'));

    foreach ([$user, $household, $ingredient, $dinner, $item, $plan, $entry, $list, $listItem, $invitation] as $model) {
        $this->assertDatabaseMissing($model->getTable(), ['id' => $model->id]);
    }
    $this->assertModelExists($unrelatedHousehold);
});

it('preserves shared households and promotes the lowest id member when the last owner deletes their account', function () {
    [$user, $household] = ownerWithHousehold();
    $successor = User::factory()->create();
    $otherMember = User::factory()->create();
    $household->members()->attach($otherMember, ['role' => HouseholdRole::Member->value]);
    $household->members()->attach($successor, ['role' => HouseholdRole::Member->value]);
    $dinner = Dinner::factory()->for($household)->create(['created_by_user_id' => $otherMember->id]);

    app(DeleteAccount::class)->handle($user);

    $this->assertModelExists($household);
    $this->assertModelExists($dinner);
    expect($household->isOwnedBy($successor))->toBeTrue()
        ->and($household->isOwnedBy($otherMember))->toBeFalse()
        ->and($household->hasMember($user))->toBeFalse();
});

it('keeps existing shared household owners without promoting other members', function () {
    [$user, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $owner = User::factory()->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member->value]);
    $household->members()->attach($owner, ['role' => HouseholdRole::Owner->value]);

    app(DeleteAccount::class)->handle($user);

    expect($household->isOwnedBy($owner))->toBeTrue()
        ->and($household->isOwnedBy($member))->toBeFalse();
});

it('anonymises authored shared content and stamps it for sync even for households the user has left', function () {
    $this->travelTo(now()->setMicrosecond(123456));
    [$remainingOwner, $household] = ownerWithHousehold();
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->for($household)->create();
    $dinner = Dinner::factory()->for($household)->make(['created_by_user_id' => $user->id, 'name' => 'Pancakes', 'notes' => 'Private notes']);
    $dinner->attributeContentTo($user->id)->save();
    $plan = DinnerPlan::factory()->for($household)->make(['created_by_user_id' => $user->id, 'name' => 'Week 40']);
    $plan->attributeContentTo($user->id)->save();
    $entry = DinnerPlanEntry::factory()->for($plan)->for($dinner)->make(['notes' => 'Private entry']);
    $entry->attributeContentTo($user->id)->save();
    $list = ShoppingList::factory()->for($household)->for($plan)->make(['created_by_user_id' => $user->id, 'name' => 'Groceries']);
    $list->attributeContentTo($user->id)->save();
    $listItem = ShoppingListItem::factory()->for($list)->for($ingredient)->make(['name' => 'Milk']);
    $listItem->attributeContentTo($user->id)->save();
    $retained = Dinner::factory()->for($household)->create(['created_by_user_id' => $remainingOwner->id]);
    $dinner->delete();
    app(AllocateSyncVersion::class)->forget();
    $cursor = $household->fresh()->sync_version;

    app(DeleteAccount::class)->handle($user);

    foreach ([$dinner, $plan, $entry, $list, $listItem] as $model) {
        $model->refresh();
        expect($model->sync_version)->toBeGreaterThan($cursor)
            ->and($model->content_authors)->toBeNull()
            ->and($model->created_by_user_id)->toBeNull()
            ->and($model->synced_at->format('u'))->toBe('123456');
    }
    expect($dinner->trashed())->toBeTrue()
        ->and($plan->trashed() || $list->trashed() || $listItem->trashed())->toBeFalse()
        ->and($dinner->name)->toBe('Pancakes')->and($dinner->notes)->toBeNull()
        ->and($plan->name)->toBe('Week 40')
        ->and($entry->notes)->toBeNull()->and($entry->erasure_version)->toBeGreaterThan($cursor)
        ->and($list->name)->toBe('Groceries')->and($list->dinner_plan_id)->toBe($plan->id)
        ->and($listItem->name)->toBe('Milk')->and($listItem->erasure_version)->toBe(0);
    expect($retained->fresh()->trashed())->toBeFalse();
    $this->assertModelExists($ingredient);
});

it('removes credentials sessions reset tokens and invitations without deleting other users data', function () {
    [$user, $household] = ownerWithHousehold();
    $otherUser = User::factory()->create();
    $household->members()->attach($otherUser, ['role' => HouseholdRole::Member->value]);
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Mobile', redirectUris: ['foodapp://oauth/callback'], confidential: false,
    );
    $tokens = [];
    foreach ([$user, $otherUser] as $account) {
        $token = Passport::token()->newQuery()->create([
            'id' => Str::random(80), 'user_id' => $account->id, 'client_id' => $client->id,
            'scopes' => [], 'revoked' => false, 'expires_at' => now()->addDay(),
        ]);
        $refresh = Passport::refreshToken()->newQuery()->create([
            'id' => Str::random(80), 'access_token_id' => $token->id, 'revoked' => false,
        ]);
        Passport::authCode()->newQuery()->create([
            'id' => Str::random(80), 'user_id' => $account->id, 'client_id' => $client->id, 'revoked' => false,
        ]);
        Passport::deviceCode()->newQuery()->create([
            'id' => Str::random(80), 'user_id' => $account->id, 'client_id' => $client->id,
            'user_code' => Str::random(8), 'scopes' => [], 'revoked' => false,
        ]);
        DB::table('sessions')->insert([
            'id' => Str::random(40), 'user_id' => $account->id,
            'payload' => 'session data', 'last_activity' => now()->timestamp,
        ]);
        Password::broker()->createToken($account);
        $tokens[] = [$token, $refresh];
    }
    $sent = HouseholdInvitation::factory()->for($household)->create(['invited_by_user_id' => $user->id]);
    $received = HouseholdInvitation::factory()->for($household)->accepted()->create(['email' => mb_strtoupper($user->email)]);
    $unrelated = HouseholdInvitation::factory()->for($household)->create(['invited_by_user_id' => $otherUser->id]);

    app(DeleteAccount::class)->handle($user);

    foreach (['oauth_access_tokens', 'oauth_auth_codes', 'oauth_device_codes', 'sessions'] as $table) {
        $this->assertDatabaseMissing($table, ['user_id' => $user->id]);
        $this->assertDatabaseHas($table, ['user_id' => $otherUser->id]);
    }
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $otherUser->email]);
    $this->assertModelMissing($tokens[0][1]);
    $this->assertModelExists($tokens[1][1]);
    $this->assertModelMissing($sent);
    $this->assertModelMissing($received);
    $this->assertModelExists($unrelated);
    expect(app(AccessTokenRepository::class)->isAccessTokenRevoked($tokens[0][0]->id))->toBeTrue()
        ->and(app(RefreshTokenRepository::class)->isRefreshTokenRevoked($tokens[0][1]->id))->toBeTrue();
});

it('rolls back deletion and ownership changes if cleanup fails', function () {
    [$user, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member->value]);
    $dinner = Dinner::factory()->for($household)->create(['created_by_user_id' => $user->id]);
    Password::shouldReceive('broker')->once()->andThrow(new RuntimeException('Cleanup failed'));

    expect(fn () => app(DeleteAccount::class)->handle($user))->toThrow(RuntimeException::class, 'Cleanup failed');

    $this->assertModelExists($user);
    expect($household->isOwnedBy($user))->toBeTrue()
        ->and($household->isOwnedBy($member))->toBeFalse()
        ->and($dinner->fresh()->trashed())->toBeFalse()
        ->and($dinner->fresh()->name)->toBe($dinner->name);
});

it('deletes owned oauth clients and their grants without affecting first party clients', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Owned app', redirectUris: ['https://example.test/callback'], confidential: true, user: $owner,
    );
    $firstPartyClient = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Mobile', redirectUris: ['foodapp://oauth/callback'], confidential: false,
    );
    $token = Passport::token()->newQuery()->create([
        'id' => Str::random(80), 'user_id' => $otherUser->id, 'client_id' => $client->id,
        'scopes' => [], 'revoked' => false,
    ]);
    $refresh = Passport::refreshToken()->newQuery()->create([
        'id' => Str::random(80), 'access_token_id' => $token->id, 'revoked' => false,
    ]);

    app(DeleteAccount::class)->handle($owner);

    $this->assertModelMissing($client);
    $this->assertModelMissing($token);
    $this->assertModelMissing($refresh);
    $this->assertModelExists($firstPartyClient);
    $this->assertModelExists($otherUser);
});

it('removes the user\'s request logs and stored AI bodies but keeps anonymous usage totals', function () {
    [$user] = ownerWithHousehold();
    $other = User::factory()->create();
    ApiRequest::factory()->for($user)->create(['request_body' => '{"email": "private@example.com"}']);
    $otherLog = ApiRequest::factory()->for($other)->create();
    $aiRequest = AiRequest::factory()->for($user)->create(['request' => ['name' => 'Pak choi'], 'response' => ['category' => 'produce'], 'cost' => 0.0004]);

    app(DeleteAccount::class)->handle($user);

    expect(ApiRequest::query()->pluck('id')->all())->toBe([$otherLog->id]);
    expect($aiRequest->refresh())
        ->user_id->toBeNull()
        ->request->toBeNull()
        ->response->toBeNull()
        ->cost->toBe(0.0004);
});
