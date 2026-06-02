<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Notifications\HouseholdInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

beforeEach(function () {
    Notification::fake();

    $this->owner = User::factory()->create();
    $this->household = Household::factory()->create();
    $this->household->members()->attach($this->owner, ['role' => HouseholdRole::Owner->value]);
    $this->owner->update(['current_household_id' => $this->household->id]);
});

it('invites a member by email and notifies them', function () {
    Passport::actingAs($this->owner);

    $this->postJson('/api/v1/household/invitations', ['email' => 'partner@example.com', 'role' => 'member'])
        ->assertSuccessful()
        ->assertJsonPath('data.email', 'partner@example.com')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('household_invitations', [
        'household_id' => $this->household->id,
        'email' => 'partner@example.com',
    ]);
    Notification::assertSentOnDemand(HouseholdInvitationNotification::class);
});

it('rejects inviting an existing member', function () {
    Passport::actingAs($this->owner);

    $this->postJson('/api/v1/household/invitations', ['email' => $this->owner->email])
        ->assertUnprocessable();
});

it('rejects a duplicate pending invitation', function () {
    Passport::actingAs($this->owner);

    $this->postJson('/api/v1/household/invitations', ['email' => 'partner@example.com'])->assertSuccessful();
    $this->postJson('/api/v1/household/invitations', ['email' => 'partner@example.com'])->assertUnprocessable();
});

it('forbids non-owners from inviting', function () {
    $member = User::factory()->create(['current_household_id' => $this->household->id]);
    $this->household->members()->attach($member, ['role' => HouseholdRole::Member->value]);

    Passport::actingAs($member);
    $this->postJson('/api/v1/household/invitations', ['email' => 'x@example.com'])->assertForbidden();
});

it('lets the invited user accept and join', function () {
    $invitee = User::factory()->create(['email' => 'partner@example.com']);
    $invitation = HouseholdInvitation::factory()->for($this->household)->create([
        'email' => 'partner@example.com',
        'role' => HouseholdRole::Member,
    ]);

    Passport::actingAs($invitee);
    $this->postJson("/api/v1/invitations/{$invitation->token}/accept")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $this->household->id);

    expect($this->household->hasMember($invitee))->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('forbids accepting an invitation addressed to a different email', function () {
    $invitation = HouseholdInvitation::factory()->for($this->household)->create(['email' => 'someone@else.com']);

    Passport::actingAs(User::factory()->create(['email' => 'other@example.com']));
    $this->postJson("/api/v1/invitations/{$invitation->token}/accept")->assertForbidden();
});

it('rejects accepting an expired invitation', function () {
    $invitee = User::factory()->create(['email' => 'partner@example.com']);
    $invitation = HouseholdInvitation::factory()->for($this->household)->expired()->create([
        'email' => 'partner@example.com',
    ]);

    Passport::actingAs($invitee);
    $this->postJson("/api/v1/invitations/{$invitation->token}/accept")->assertConflict();
});

it('lets the invited user decline', function () {
    $invitee = User::factory()->create(['email' => 'partner@example.com']);
    $invitation = HouseholdInvitation::factory()->for($this->household)->create(['email' => 'partner@example.com']);

    Passport::actingAs($invitee);
    $this->postJson("/api/v1/invitations/{$invitation->token}/decline")->assertSuccessful();

    expect($invitation->fresh()->declined_at)->not->toBeNull();
});

it('lets an owner revoke a pending invitation', function () {
    $invitation = HouseholdInvitation::factory()->for($this->household)->create();

    Passport::actingAs($this->owner);
    $this->deleteJson("/api/v1/household/invitations/{$invitation->id}")->assertNoContent();

    $this->assertModelMissing($invitation);
});
