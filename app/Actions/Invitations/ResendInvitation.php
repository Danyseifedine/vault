<?php

namespace App\Actions\Invitations;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ResendInvitation
{
    public function __construct(private AuditRecorder $audit) {}

    public function __invoke(Invitation $invitation, User $resentBy): Invitation
    {
        // A fresh token and clock: the old link stops working, which is what
        // you want if the first one went somewhere it shouldn't have.
        $invitation->forceFill([
            'token' => Str::random(64),
            'expires_at' => now()->addDays(SendInvitation::EXPIRY_DAYS),
        ])->save();

        Mail::to($invitation->user->email)->queue(new InvitationMail($invitation));

        $this->audit->record(
            'invitation.resent',
            subject: $invitation,
            properties: ['email' => $invitation->user->email],
            scope: AuditScope::make(organizationId: $invitation->organization_id),
            causer: $resentBy,
        );

        return $invitation;
    }
}
