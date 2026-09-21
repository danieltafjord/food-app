<?php

namespace App\Actions\ApiTokens;

use App\Enums\ApiTokenScope;
use App\Models\ApiTokenDetail;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\PersonalAccessTokenResult;
use RuntimeException;

class CreateApiToken
{
    public function __construct(private ClientRepository $clients) {}

    /**
     * Issue a personal access token pinned to one of the user's households.
     * The plain-text token is only available on the returned result.
     */
    public function handle(User $user, Household $household, string $name, bool $canWrite): PersonalAccessTokenResult
    {
        if (! $household->hasMember($user)) {
            throw ValidationException::withMessages([
                'household_id' => 'You are not a member of this household.',
            ]);
        }

        $this->ensurePersonalAccessClientExists($user);

        $scopes = $canWrite
            ? [ApiTokenScope::Read->value, ApiTokenScope::Write->value]
            : [ApiTokenScope::Read->value];

        return DB::transaction(function () use ($user, $household, $name, $scopes): PersonalAccessTokenResult {
            $result = $user->createToken($name, $scopes);

            ApiTokenDetail::create([
                'token_id' => $result->accessTokenId,
                'household_id' => $household->id,
            ]);

            return $result;
        });
    }

    /**
     * Passport needs a personal access client before it can issue tokens;
     * create it on first use so no manual deploy step is required.
     */
    private function ensurePersonalAccessClientExists(User $user): void
    {
        try {
            $this->clients->personalAccessClient($user->getProviderName());
        } catch (RuntimeException) {
            $this->clients->createPersonalAccessGrantClient(config('app.name').' API tokens');
        }
    }
}
