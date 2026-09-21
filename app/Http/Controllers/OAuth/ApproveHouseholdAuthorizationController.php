<?php

namespace App\Http\Controllers\OAuth;

use App\Models\OAuthHouseholdGrant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

class ApproveHouseholdAuthorizationController extends ApproveAuthorizationController
{
    /**
     * Approve a third-party client (such as an MCP connector) for one of the
     * user's households. The choice is remembered per user and client, and
     * every token the client is issued is pinned to it.
     */
    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        $validated = $request->validate([
            'household_id' => [
                'required',
                'integer',
                Rule::exists('household_user', 'household_id')->where('user_id', $request->user()->id),
            ],
            'can_write' => ['required', 'boolean'],
        ]);

        $clientId = $this->pendingClientId($request);

        if ($clientId !== null) {
            OAuthHouseholdGrant::query()->updateOrCreate(
                ['user_id' => $request->user()->id, 'client_id' => $clientId],
                ['household_id' => $validated['household_id'], 'can_write' => (bool) $validated['can_write']],
            );
        }

        return parent::approve($request, $psrResponse);
    }

    /**
     * Peek at the client of the pending authorization request without
     * consuming it; the parent controller pulls and verifies it afterwards.
     */
    private function pendingClientId(Request $request): ?string
    {
        $serialized = $request->session()->get('authRequest');

        if (! is_string($serialized)) {
            return null;
        }

        $authRequest = unserialize($serialized, ['allowed_classes' => [
            AuthorizationRequest::class,
            Client::class,
            Scope::class,
            User::class,
        ]]);

        return $authRequest instanceof AuthorizationRequest ? $authRequest->getClient()->getIdentifier() : null;
    }
}
