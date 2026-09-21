<?php

use App\Actions\ApiTokens\CreateApiToken;
use App\Models\ApiTokenDetail;
use App\Models\Household;
use Inertia\Testing\AssertableInertia as Assert;

test('api tokens page requires password confirmation', function () {
    [$user] = ownerWithHousehold();

    $this->actingAs($user)
        ->get(route('api-tokens.index'))
        ->assertRedirect(route('password.confirm'));
});

test('api tokens page lists the user\'s tokens and households', function () {
    [$user, $household] = ownerWithHousehold();
    app(CreateApiToken::class)->handle($user, $household, 'Home Assistant', false);

    [$otherUser, $otherHousehold] = ownerWithHousehold();
    app(CreateApiToken::class)->handle($otherUser, $otherHousehold, 'Someone else', true);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ApiTokens')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'Home Assistant')
            ->where('tokens.0.household_name', $household->name)
            ->where('tokens.0.scopes', ['read'])
            ->where('households', [['id' => $household->id, 'name' => $household->name]])
        );
});

test('a token can be created and its plain text value is flashed once', function () {
    [$user, $household] = ownerWithHousehold();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('api-tokens.store'), [
            'name' => 'My agent',
            'household_id' => $household->id,
            'can_write' => '1',
        ]);

    $response->assertRedirect(route('api-tokens.index'));

    $apiToken = ApiTokenDetail::query()->sole();
    expect($apiToken->household_id)->toBe($household->id)
        ->and($apiToken->token->name)->toBe('My agent')
        ->and($apiToken->token->user_id)->toBe($user->id)
        ->and($apiToken->token->scopes)->toBe(['read', 'write']);

    $this->followRedirects($response)
        ->assertInertia(fn (Assert $page) => $page->hasFlash('plainTextApiToken'));

    $this->get(route('api-tokens.index'))
        ->assertInertia(fn (Assert $page) => $page->missingFlash('plainTextApiToken'));
});

test('a token cannot be created for a household the user does not belong to', function () {
    [$user] = ownerWithHousehold();
    $foreignHousehold = Household::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('api-tokens.store'), [
            'name' => 'Sneaky',
            'household_id' => $foreignHousehold->id,
            'can_write' => '0',
        ])
        ->assertSessionHasErrors('household_id');

    expect(ApiTokenDetail::query()->count())->toBe(0);
});

test('a token can be revoked', function () {
    [$user, $household] = ownerWithHousehold();
    app(CreateApiToken::class)->handle($user, $household, 'Old token', false);
    $apiToken = ApiTokenDetail::query()->sole();

    $this->actingAs($user)
        ->delete(route('api-tokens.destroy', $apiToken))
        ->assertRedirect(route('api-tokens.index'));

    expect($apiToken->token->fresh()->revoked)->toBeTrue();
});

test('a user cannot revoke another user\'s token', function () {
    [$owner, $household] = ownerWithHousehold();
    app(CreateApiToken::class)->handle($owner, $household, 'Not yours', false);
    $apiToken = ApiTokenDetail::query()->sole();

    [$intruder] = ownerWithHousehold();

    $this->actingAs($intruder)
        ->delete(route('api-tokens.destroy', $apiToken))
        ->assertNotFound();

    expect($apiToken->token->fresh()->revoked)->toBeFalse();
});
