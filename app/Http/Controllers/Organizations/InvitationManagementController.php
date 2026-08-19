<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Invitations\ResendInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Withdrawing and re-sending invitations.
 *
 * Revoking deletes the reserved account along with its grants: a seat that was
 * never claimed must leave nothing behind that could later be logged into.
 */
class InvitationManagementController extends Controller
{
    public function destroy(
        Request $request,
        Organization $organization,
        Invitation $invitation,
        RevokeInvitation $revoke,
    ): RedirectResponse {
        $this->authorize('invite', $organization);

        $revoke($invitation, $request->user());

        return back()->with('success', 'Invitation revoked.');
    }

    public function resend(
        Request $request,
        Organization $organization,
        Invitation $invitation,
        ResendInvitation $resend,
    ): RedirectResponse {
        $this->authorize('invite', $organization);

        $resend($invitation, $request->user());

        return back()->with('success', 'Invitation sent again.');
    }
}
