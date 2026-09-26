<?php

use App\Actions\Households\AcceptInvitation;
use App\Actions\Households\DeclineInvitation;
use App\Enums\AppLocale;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Notifications\HouseholdInvitationNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

it('lets whoever holds the token respond, even from a different email address', function (string $response) {
    // Sign in with Apple's "Hide My Email" gives the invitee a relay address.
    $invitee = User::factory()->create(['email' => 'x7k2p9@privaterelay.appleid.com']);
    $invitation = HouseholdInvitation::factory()->for($this->household)->create(['email' => 'partner@example.com']);

    Passport::actingAs($invitee);
    $this->postJson("/api/v1/invitations/{$invitation->token}/{$response}")->assertSuccessful();

    expect($this->household->hasMember($invitee))->toBe($response === 'accept')
        ->and($invitation->fresh()->isPending())->toBeFalse();
    $this->postJson("/api/v1/invitations/{$invitation->token}/accept")->assertConflict();
})->with(['accept', 'decline']);

it('refuses an invitation once its sender no longer owns the household', function (?HouseholdRole $senderRole) {
    $sender = User::factory()->create();
    $this->household->members()->attach($sender, ['role' => HouseholdRole::Owner->value]);
    $invitation = HouseholdInvitation::factory()->for($this->household)->create([
        'invited_by_user_id' => $sender->id,
        'role' => HouseholdRole::Owner,
    ]);
    // Changed behind the actions' backs, which would also revoke the invitation.
    $senderRole === null
        ? $this->household->members()->detach($sender)
        : $this->household->members()->updateExistingPivot($sender->id, ['role' => $senderRole->value]);
    $invitee = User::factory()->create();

    Passport::actingAs($invitee);
    $this->postJson("/api/v1/invitations/{$invitation->token}/accept")->assertConflict();

    expect($this->household->hasMember($invitee))->toBeFalse();
})->with(['removed' => null, 'demoted' => HouseholdRole::Member]);

it('revokes the pending invitations of an owner who is removed or demoted', function (string $change) {
    $sender = User::factory()->create(['current_household_id' => $this->household->id]);
    $this->household->members()->attach($sender, ['role' => HouseholdRole::Owner->value]);
    $pending = HouseholdInvitation::factory()->for($this->household)->create(['invited_by_user_id' => $sender->id]);
    $accepted = HouseholdInvitation::factory()->for($this->household)->accepted()->create(['invited_by_user_id' => $sender->id]);
    $fromOwner = HouseholdInvitation::factory()->for($this->household)->create(['invited_by_user_id' => $this->owner->id]);

    Passport::actingAs($this->owner);
    $change === 'remove'
        ? $this->deleteJson("/api/v1/household/members/{$sender->id}")->assertNoContent()
        : $this->patchJson("/api/v1/household/members/{$sender->id}", ['role' => 'member'])->assertNoContent();

    $this->assertModelMissing($pending);
    $this->assertModelExists($accepted);
    $this->assertModelExists($fromOwner);
})->with(['remove', 'demote']);

it('requires a verified email to send invitations', function () {
    $this->owner->forceFill(['email_verified_at' => null])->save();
    Passport::actingAs($this->owner);

    $this->withHeader('Accept-Language', 'nb')
        ->postJson('/api/v1/household/invitations', ['email' => 'partner@example.com'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Bekreft e-postadressen din før du inviterer noen til husstanden.');

    Notification::assertNothingSent();
});

it('limits invitations to one address across households, even when they are revoked', function () {
    [$otherOwner] = ownerWithHousehold();

    Passport::actingAs($this->owner);
    foreach (range(1, 2) as $attempt) {
        $id = $this->postJson('/api/v1/household/invitations', ['email' => 'Target@example.com'])->assertSuccessful()->json('data.id');
        $this->deleteJson("/api/v1/household/invitations/{$id}")->assertNoContent();
    }
    Passport::actingAs($otherOwner);
    $this->postJson('/api/v1/household/invitations', ['email' => 'target@example.com'])->assertSuccessful();
    $this->withHeader('Accept-Language', 'en')
        ->postJson('/api/v1/household/invitations', ['email' => 'target@example.com'])
        ->assertTooManyRequests()
        ->assertJsonPath('message', 'Too many invitations have been sent. Please try again later.');

    $this->postJson('/api/v1/household/invitations', ['email' => 'someone-else@example.com'])->assertSuccessful();
    Notification::assertSentOnDemandTimes(HouseholdInvitationNotification::class, 4);
});

it('limits how many invitations one person sends in an hour', function () {
    Passport::actingAs($this->owner);

    foreach (range(1, 10) as $number) {
        $this->postJson('/api/v1/household/invitations', ['email' => "person{$number}@example.com"])->assertSuccessful();
    }

    $this->postJson('/api/v1/household/invitations', ['email' => 'person11@example.com'])->assertTooManyRequests();
    $this->travel(61)->minutes();
    $this->postJson('/api/v1/household/invitations', ['email' => 'person11@example.com'])->assertSuccessful();
});

it('writes the invitation email in the inviter\'s language with a fixed subject', function (AppLocale $locale, string $subject, string $intro) {
    $this->owner->update(['name' => 'Kari', 'locale' => $locale]);
    $this->household->update(['name' => 'Click [here](https://evil.example)']);
    Passport::actingAs($this->owner);

    $this->postJson('/api/v1/household/invitations', ['email' => 'partner@example.com'])->assertSuccessful();

    Notification::assertSentOnDemand(HouseholdInvitationNotification::class, function (HouseholdInvitationNotification $notification, array $channels, object $notifiable) use ($subject, $intro): bool {
        app()->setLocale($notification->locale);
        $mail = $notification->toMail($notifiable);
        $html = (string) $mail->render();

        return $mail->subject === $subject
            && str_contains($mail->introLines[0], $intro)
            && ! str_contains($html, 'href="https://evil.example"')
            && ! str_contains($html, 'Food App');
    });
})->with([
    'english' => [AppLocale::English, 'You are invited to a household on Handlelista', 'Kari has invited you to join the household'],
    'norwegian' => [AppLocale::Norwegian, 'Du er invitert til en husstand i Handlelista', 'Kari har invitert deg til husstanden'],
]);

it('requires a verified email before responding to an invitation', function (string $response) {
    $invitee = User::factory()->unverified()->create(['email' => 'partner@example.com']);
    $invitation = HouseholdInvitation::factory()->for($this->household)->create([
        'email' => $invitee->email,
    ]);

    Passport::actingAs($invitee);
    $this->postJson("/api/v1/invitations/{$invitation->token}/{$response}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Verify your email address before responding to household invitations.');

    expect($this->household->hasMember($invitee))->toBeFalse()
        ->and($invitation->fresh()->isPending())->toBeTrue();
})->with(['accept', 'decline']);

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

it('does not accept an invitation revoked after route binding', function () {
    $invitee = User::factory()->create();
    $invitation = HouseholdInvitation::factory()->for($this->household)->create(['email' => $invitee->email]);
    HouseholdInvitation::whereKey($invitation->id)->delete();

    expect(fn () => app(AcceptInvitation::class)->handle($invitation, $invitee))
        ->toThrow(ModelNotFoundException::class);
    expect($this->household->hasMember($invitee))->toBeFalse();
});

it('rechecks invitation status after acquiring the write lock', function (string $response) {
    $invitee = User::factory()->create();
    $invitation = HouseholdInvitation::factory()->for($this->household)->create(['email' => $invitee->email]);
    HouseholdInvitation::whereKey($invitation->id)->update([
        $response === 'accept' ? 'declined_at' : 'accepted_at' => now(),
    ]);

    expect(fn () => $response === 'accept'
        ? app(AcceptInvitation::class)->handle($invitation, $invitee)
        : app(DeclineInvitation::class)->handle($invitation))
        ->toThrow(HttpException::class, 'This invitation is no longer valid.');

    expect($this->household->hasMember($invitee))->toBeFalse();
})->with(['accept', 'decline']);

it('makes the joined household active even when the invitee already has a household', function () {
    [$invitee, $previous] = ownerWithHousehold();
    $invitation = HouseholdInvitation::factory()->for($this->household)->create(['email' => $invitee->email]);
    Passport::actingAs($invitee);
    $this->postJson("/api/v1/invitations/{$invitation->token}/accept")->assertSuccessful()
        ->assertJsonPath('data.id', $this->household->id);
    expect($invitee->fresh()->current_household_id)->toBe($this->household->id)
        ->and($previous->hasMember($invitee))->toBeTrue();
    $this->getJson('/api/v1/me')->assertSuccessful()->assertJsonPath('data.current_household.id', $this->household->id);
});

it('prunes invitations a month after they expired', function () {
    $stale = HouseholdInvitation::factory()->for($this->household)->create(['expires_at' => now()->subDays(HouseholdInvitation::RETENTION_DAYS_AFTER_EXPIRY + 1)]);
    $recent = HouseholdInvitation::factory()->for($this->household)->create(['expires_at' => now()->subDay()]);

    $this->artisan('model:prune', ['--model' => [HouseholdInvitation::class]])->assertSuccessful();

    $this->assertModelMissing($stale);
    $this->assertModelExists($recent);
});
