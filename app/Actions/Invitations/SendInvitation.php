<?php

namespace App\Actions\Invitations;

use App\Enums\UserStatus;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\GrantWriter;
use App\Services\Access\MemberChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reserves a seat for someone.
 *
 * The account, the membership and every grant are created NOW and simply lie
 * inert until the person claims them - so what the inviter chose is visible in
 * the members list immediately, and acceptance has nothing to recalculate.
 *
 * The grant list IS the invitation: it states exactly what this person can do,
 * chosen permission by permission, scope by scope. There are no roles.
 */
class SendInvitation
{
    public const EXPIRY_DAYS = 7;

    public function __construct(
        private AuditRecorder $audit,
        private GrantWriter $writer,
        private MemberChangeGuard $guard,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $grants  specs: permission + optional project_id / environment_id
     */
    public function __invoke(
        Organization $organization,
        User $inviter,
        string $email,
        array $grants = [],
    ): Invitation {
        $email = Str::lower(trim($email));

        $wanted = $this->writer->normalize($organization, $grants);

        // Escalation stop: members.invite alone must not mint accounts more
        // powerful than the inviter could ever make an existing member.
        $this->guard->authorizeInviteGrants($inviter, $organization, $wanted);
        $this->guardAgainstDuplicate($organization, $email);

        return DB::transaction(function () use ($organization, $inviter, $email, $grants, $wanted): Invitation {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = new User(['email' => $email]);
                $user->status = UserStatus::Invited;
                $user->save();
            }

            if ($organization->members()->where('users.id', $user->id)->exists()) {
                $organization->members()->updateExistingPivot($user->id, ['joined_at' => null]);
            } else {
                $organization->members()->attach($user->id, ['joined_at' => null]);
            }

            // The invite STATES what they can do: dormant rows from an earlier
            // invite are replaced, never accumulated.
            $this->writer->replace($organization, $user, $grants, $inviter);

            $invitation = Invitation::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'token' => Str::random(64),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(self::EXPIRY_DAYS),
            ]);

            Mail::to($email)->queue(new InvitationMail($invitation));

            $this->audit->record(
                'invitation.sent',
                subject: $invitation,
                properties: [
                    'email' => $email,
                    'grants' => count($wanted),
                ],
                scope: AuditScope::make(organizationId: $organization->id),
                causer: $inviter,
            );

            return $invitation;
        });
    }

    private function guardAgainstDuplicate(Organization $organization, string $email): void
    {
        $alreadyPending = Invitation::query()
            ->pending()
            ->where('organization_id', $organization->id)
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->exists();

        if ($alreadyPending) {
            throw ValidationException::withMessages([
                'email' => "{$email} already has a pending invitation to this organization.",
            ]);
        }

        $alreadyMember = $organization->members()
            ->where('email', $email)
            ->wherePivotNotNull('joined_at')
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'email' => "{$email} is already a member of this organization.",
            ]);
        }
    }
}
