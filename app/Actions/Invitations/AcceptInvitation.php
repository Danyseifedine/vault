<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;

/**
 * Claims a reserved seat.
 *
 * Note what this does NOT do: it never grants anything. The membership and
 * environment rows were written when the invitation was sent - accepting just
 * marks the seat taken and stamps the join date.
 */
class AcceptInvitation
{
    public function __construct(private AuditRecorder $audit) {}

    public function __invoke(Invitation $invitation): Invitation
    {
        return DB::transaction(function () use ($invitation): Invitation {
            $invitation->forceFill(['accepted_at' => now()])->save();

            $invitation->organization->members()->updateExistingPivot(
                $invitation->user_id,
                ['joined_at' => now()],
            );

            // Receiving the token proves control of the inbox, so there is no
            // point sending a separate "verify your email" message.
            if ($invitation->user->email_verified_at === null) {
                $invitation->user->forceFill(['email_verified_at' => now()])->save();
            }

            $this->audit->record(
                'invitation.accepted',
                subject: $invitation,
                properties: ['email' => $invitation->user->email],
                scope: AuditScope::make(organizationId: $invitation->organization_id),
                causer: $invitation->user,
            );

            return $invitation;
        });
    }
}
