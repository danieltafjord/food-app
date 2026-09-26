<?php

use App\Models\Household;
use App\Models\HouseholdInvitation;
use Inertia\Testing\AssertableInertia as Assert;

it('sends an invitation link to the page in the visitor\'s language', function () {
    $this->withHeader('Accept-Language', 'nb-NO')
        ->get('/invitations/abc123')
        ->assertRedirect(route('localized.invitation', ['locale' => 'no', 'token' => 'abc123']));
});

it('offers to open a pending invitation in the app or get it from the App Store', function () {
    config(['services.app_store_url' => 'https://apps.apple.com/app/id123']);
    $invitation = HouseholdInvitation::factory()->for(Household::factory()->state(['name' => 'Familien Nordmann']))->create();

    $this->get(route('localized.invitation', ['locale' => 'en', 'token' => $invitation->token]))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invitation')
            ->where('householdName', 'Familien Nordmann')
            ->where('appUrl', "foodapp://invitations/{$invitation->token}")
            ->where('appStoreUrl', 'https://apps.apple.com/app/id123')
            ->missing('email')
        );
});

it('reveals nothing about an invitation that cannot be used', function (?string $state) {
    config(['services.app_store_url' => null]);
    $invitation = HouseholdInvitation::factory()->for(Household::factory()->state(['name' => 'Familien Nordmann']));
    $token = $state === null ? 'unknown-token' : $invitation->{$state}()->create()->token;

    $this->get(route('localized.invitation', ['locale' => 'no', 'token' => $token]))
        ->assertOk()
        ->assertDontSee('Familien Nordmann')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invitation')
            ->where('householdName', null)
            ->where('appStoreUrl', null)
        );
})->with(['unknown token' => null, 'expired' => 'expired', 'accepted' => 'accepted']);
