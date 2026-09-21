<?php

use App\Models\ApiTokenDetail;
use App\Models\Household;
use App\Models\OAuthHouseholdGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

const MCP_REDIRECT_URI = 'http://localhost:6274/oauth/callback';

/**
 * Register a client dynamically and walk the consent screen up to the point
 * where the user must approve. Returns the client id and PKCE verifier.
 *
 * @return array{string, string}
 */
function beginMcpAuthorization(User $user): array
{
    $clientId = test()->postJson('/oauth/register', [
        'client_name' => 'Test Agent',
        'redirect_uris' => [MCP_REDIRECT_URI],
    ])->assertSuccessful()->json('client_id');

    $verifier = str_repeat('v', 64);

    test()->actingAs($user)
        ->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => MCP_REDIRECT_URI,
            'scope' => 'mcp:use',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'state' => 'state',
        ]))
        ->assertOk()
        ->assertSee('Test Agent')
        ->assertSee('name="household_id"', false);

    return [$clientId, $verifier];
}

function exchangeMcpCode(TestResponse $approval, string $clientId, string $verifier): TestResponse
{
    parse_str((string) parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);

    return test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => MCP_REDIRECT_URI,
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ]);
}

test('a dynamically registered client never skips consent', function () {
    [$user] = ownerWithHousehold();

    beginMcpAuthorization($user);
});

test('an approved mcp client gets a token pinned to the chosen household', function () {
    [$user, $household] = ownerWithHousehold();
    [$clientId, $verifier] = beginMcpAuthorization($user);

    $approval = $this->post(route('oauth.household-authorizations.approve'), [
        'auth_token' => session('authToken'),
        'household_id' => $household->id,
        'can_write' => '0',
    ])->assertRedirect();

    $accessToken = exchangeMcpCode($approval, $clientId, $verifier)->assertOk()->json('access_token');

    $apiToken = ApiTokenDetail::query()->sole();
    expect($apiToken->household_id)->toBe($household->id)
        ->and($apiToken->can_write)->toBeFalse();

    app('auth')->forgetGuards();

    $tools = $this->withToken($accessToken)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()
        ->json('result.tools.*.name');

    expect($tools)->toContain('get-today-tool')->not->toContain('add-shopping-list-item-tool');

    $this->withToken($accessToken)->getJson('/api/v1/me')->assertForbidden();
});

test('a household the user does not belong to cannot be granted', function () {
    [$user] = ownerWithHousehold();
    beginMcpAuthorization($user);

    $this->post(route('oauth.household-authorizations.approve'), [
        'auth_token' => session('authToken'),
        'household_id' => Household::factory()->create()->id,
        'can_write' => '1',
    ])->assertSessionHasErrors('household_id');
});

test('approving without choosing a household yields a revoked token', function () {
    [$user] = ownerWithHousehold();
    [$clientId, $verifier] = beginMcpAuthorization($user);

    $approval = $this->post(route('passport.authorizations.approve'), [
        'auth_token' => session('authToken'),
    ])->assertRedirect();

    $accessToken = exchangeMcpCode($approval, $clientId, $verifier)->json('access_token');

    app('auth')->forgetGuards();

    $this->withToken($accessToken)->getJson('/api/v1/me')->assertUnauthorized();
    $this->withToken($accessToken)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
});

test('the consent screen follows the user\'s language', function () {
    [$user] = ownerWithHousehold();
    $user->update(['locale' => 'nb']);

    beginMcpAuthorization($user);

    $this->actingAs($user)
        ->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => DB::table('oauth_clients')->value('id'),
            'redirect_uri' => MCP_REDIRECT_URI,
            'scope' => 'mcp:use',
            'code_challenge' => str_repeat('c', 43),
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertSee('Forespørsel om tilgang')
        ->assertSee('Husstand')
        ->assertSee('Godkjenn');
});

test('a connected app is listed and can be disconnected', function () {
    [$user, $household] = ownerWithHousehold();
    [$clientId, $verifier] = beginMcpAuthorization($user);

    $approval = $this->post(route('oauth.household-authorizations.approve'), [
        'auth_token' => session('authToken'),
        'household_id' => $household->id,
        'can_write' => '1',
    ]);
    $accessToken = exchangeMcpCode($approval, $clientId, $verifier)->json('access_token');
    $grant = OAuthHouseholdGrant::query()->sole();

    $this->actingAs($user, 'web')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('connectedApps', 1)
            ->where('connectedApps.0.name', 'Test Agent')
            ->where('connectedApps.0.household_name', $household->name)
            ->where('connectedApps.0.can_write', true)
            ->has('tokens', 0)
        );

    $this->delete(route('connected-apps.destroy', $grant))->assertRedirect(route('api-tokens.index'));

    expect(OAuthHouseholdGrant::query()->count())->toBe(0)
        ->and(DB::table('oauth_access_tokens')->where('revoked', false)->count())->toBe(0)
        ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(0);

    app('auth')->forgetGuards();

    $this->withToken($accessToken)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized();
});

test('a user cannot disconnect another user\'s app', function () {
    [$owner, $household] = ownerWithHousehold();
    [$clientId] = beginMcpAuthorization($owner);
    $grant = OAuthHouseholdGrant::query()->create([
        'user_id' => $owner->id, 'client_id' => $clientId, 'household_id' => $household->id, 'can_write' => false,
    ]);

    [$intruder] = ownerWithHousehold();

    $this->actingAs($intruder)->delete(route('connected-apps.destroy', $grant))->assertNotFound();

    expect($grant->fresh())->not->toBeNull();
});
