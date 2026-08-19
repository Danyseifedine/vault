<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Invitations\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * The invitation landing page.
     *
     * Shows what is being offered before asking for anything, and states
     * plainly why a dead link is dead instead of throwing a bare 404.
     */
    public function show(string $token): Response
    {
        $invitation = $this->findByToken($token);

        if ($invitation === null) {
            return Inertia::render('auth/invitation', ['state' => 'unknown']);
        }

        if (($reason = $invitation->blockedReason()) !== null) {
            return Inertia::render('auth/invitation', [
                'state' => $reason,
                'organizationName' => $invitation->organization->name,
            ]);
        }

        $signedInAs = Auth::user();

        // Never silently attach an invitation to whoever happens to be logged
        // in - say so, and let them choose.
        if ($signedInAs !== null && $signedInAs->id !== $invitation->user_id) {
            return Inertia::render('auth/invitation', [
                'state' => 'wrong-account',
                'organizationName' => $invitation->organization->name,
                'invitedEmail' => $invitation->user->email,
                'signedInEmail' => $signedInAs->email,
            ]);
        }

        return Inertia::render('auth/invitation', [
            'state' => 'pending',
            'token' => $token,
            'organizationName' => $invitation->organization->name,
            'invitedEmail' => $invitation->user->email,
            'invitedBy' => $invitation->invitedBy->displayName(),
            'expiresAt' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Accepting the invitation also authenticates the invitee: holding the
     * token proves they control the inbox it was sent to. Without this door,
     * anyone whose email is not a Google account could never get in - the
     * account has no password yet, and reset is refused for invited accounts.
     */
    public function accept(string $token, AcceptInvitation $accept): RedirectResponse
    {
        $invitation = $this->findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            $this->audit->failure('invitation.accept-failed', properties: [
                'reason' => $invitation?->blockedReason() ?? 'unknown token',
            ]);

            return redirect()->route('login')->withErrors([
                'email' => 'That invitation link is no longer valid. Ask whoever invited you to send a new one.',
            ]);
        }

        $signedInAs = Auth::user();

        if ($signedInAs !== null && $signedInAs->id !== $invitation->user_id) {
            return redirect()->route('invitations.show', $token);
        }

        $accept($invitation);

        Auth::login($invitation->user);
        request()->session()->regenerate();

        return redirect()->route('onboarding.show');
    }

    private function findByToken(string $token): ?Invitation
    {
        return Invitation::query()->where('token', $token)->first();
    }
}
