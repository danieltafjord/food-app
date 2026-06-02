<?php

namespace App\Actions\Households;

use App\Data\InvitationInputData;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Notifications\HouseholdInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteMember
{
    /**
     * Invite someone to the household by email and notify them.
     */
    public function handle(Household $household, User $inviter, InvitationInputData $data): HouseholdInvitation
    {
        $email = Str::lower($data->email);

        if ($household->members()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This person is already a member of the household.',
            ]);
        }

        $alreadyInvited = $household->invitations()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($alreadyInvited) {
            throw ValidationException::withMessages([
                'email' => 'An invitation has already been sent to this email.',
            ]);
        }

        $invitation = $household->invitations()->create([
            'invited_by_user_id' => $inviter->id,
            'email' => $email,
            'role' => $data->role,
            'token' => Str::random(48),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $email)
            ->notify(new HouseholdInvitationNotification($invitation));

        return $invitation;
    }
}
