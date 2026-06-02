<?php

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

it('rejects unauthenticated access', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('returns the authenticated user with their current household', function () {
    $household = Household::factory()->create();
    $user = User::factory()->create(['current_household_id' => $household->id]);

    Passport::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.current_household.id', $household->id)
        ->assertJsonPath('data.current_household.name', $household->name);
});

it('returns a null current household when none is selected', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.current_household', null);
});

describe('token management', function () {
    beforeEach(function () {
        Artisan::call('passport:client', [
            '--personal' => true,
            '--name' => 'Test Personal Access Client',
            '--no-interaction' => true,
        ]);
    });

    it('lists the active devices for the user', function () {
        $user = User::factory()->create();
        $phone = $user->createToken('Phone')->accessToken;
        $user->createToken('Tablet');

        $this->withToken($phone)->getJson('/api/v1/auth/devices')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Phone', 'current' => true])
            ->assertJsonFragment(['name' => 'Tablet', 'current' => false]);
    });

    it('revokes the current token on logout', function () {
        $user = User::factory()->create();
        $created = $user->createToken('Phone');

        $this->withToken($created->accessToken)->postJson('/api/v1/auth/logout')->assertSuccessful();

        expect($user->tokens()->whereKey($created->token->id)->first()->revoked)->toBeTrue();
    });

    it('revokes a specific device without affecting others', function () {
        $user = User::factory()->create();
        $keep = $user->createToken('Keep')->accessToken;
        $remove = $user->createToken('Remove');

        $this->withToken($keep)
            ->deleteJson("/api/v1/auth/devices/{$remove->token->id}")
            ->assertSuccessful();

        expect($user->tokens()->whereKey($remove->token->id)->first()->revoked)->toBeTrue();
        $this->withToken($keep)->getJson('/api/v1/me')->assertSuccessful();
    });
});
