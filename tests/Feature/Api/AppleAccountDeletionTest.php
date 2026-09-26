<?php

use App\Models\SocialAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

it('deletes the account after the person signs in with Apple again and revokes Apple access', function () {
    Http::preventStrayRequests();
    configureAppleKey();
    $account = SocialAccount::factory()->apple()->create(['refresh_token' => 'stored-refresh-token']);
    Http::fake([
        'https://appleid.apple.com/auth/token' => Http::response(['refresh_token' => 'fresh-refresh-token']),
        'https://appleid.apple.com/auth/revoke' => Http::response(),
    ]);
    Passport::actingAs($account->user);

    $this->deleteJson('/api/v1/me', [
        'identity_token' => appleIdentityToken(['sub' => $account->provider_user_id]),
        'nonce' => 'raw-nonce',
        'authorization_code' => 'apple-code',
    ])->assertNoContent();

    $this->assertModelMissing($account->user);
    $this->assertModelMissing($account);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://appleid.apple.com/auth/revoke'
        && $request['token'] === 'fresh-refresh-token'
        && $request['token_type_hint'] === 'refresh_token');
});

it('refuses deletion confirmed with a different Apple ID', function () {
    Http::preventStrayRequests();
    $account = SocialAccount::factory()->apple()->create();
    Passport::actingAs($account->user);

    $this->deleteJson('/api/v1/me', [
        'identity_token' => appleIdentityToken(['sub' => 'someone-else']),
        'nonce' => 'raw-nonce',
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'identity_token' => 'Sign in with the Apple ID that is connected to this account.',
    ]);

    $this->assertModelExists($account->user);
});

it('requires a valid Apple sign-in to delete', function () {
    Http::preventStrayRequests();
    $account = SocialAccount::factory()->apple()->create();
    Passport::actingAs($account->user);

    $this->deleteJson('/api/v1/me', [
        'identity_token' => appleIdentityToken(['sub' => $account->provider_user_id]),
        'nonce' => 'not-the-nonce',
    ])->assertUnprocessable()->assertJsonValidationErrors('identity_token');

    $this->deleteJson('/api/v1/me', ['identity_token' => 'token'])->assertUnprocessable()->assertJsonValidationErrors('nonce');

    $this->assertModelExists($account->user);
});
