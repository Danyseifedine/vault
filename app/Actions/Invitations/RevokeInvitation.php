<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an invitation that has not been claimed.
 *
 * The reserved account is deleted along with it - a typo'd address must not
 * leave a permanent ghost user, and its dormant grants must not survive.
 */
class RevokeInvitation
{
    public function __construct(private AuditRecorder $audit) {}

    public function __invoke(Invitation $invitation, User $revokedBy): void
    {
        DB::transaction(function () use ($invitation, $revokedBy): void {
            $email = $invitation->user->email;
            $organizationId = $invitation->organization_id;
            $wasUnclaimed = $invitation->user->isInvited();

            $invitation->forceFill(['revoked_at' => now()])->save();

            $this->audit->record(
                'invitation.revoked',
                properties: ['email' => $email],
                scope: AuditScope::make(organizationId: $organizationId),
                causer: $revokedBy,
            );

            // Only tear down a seat nobody ever claimed; an account that has
            // been used belongs to a real person and is removed separately.
            if ($wasUnclaimed) {
                $invitation->user->delete();
            }
        });
    }
}
