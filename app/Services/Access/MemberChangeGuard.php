<?php

namespace App\Services\Access;

use App\Enums\Permission;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The gate in front of every change to someone's access.
 *
 * Rewriting grants and narrowing app scope are the same authority - managing
 * people - so they ask the same question here, and a refusal is always
 * recorded before it is thrown.
 */
final class MemberChangeGuard
{
    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     *
     * @throws AuthorizationException
     */
    public function authorize(
        User $actor,
        Organization $organization,
        string $deniedEvent,
        array $properties = [],
    ): void {
        if ($this->access->can($actor, Permission::ManageMembers, $organization)) {
            return;
        }

        $this->audit->failure(
            $deniedEvent,
            properties: $properties,
            scope: AuditScope::make(organizationId: $organization->id),
            causer: $actor,
        );

        throw new AuthorizationException('Managing member access takes the members.manage permission.');
    }

    /**
     * Access can only be given to someone who is already in the organization -
     * membership is the first link of the chain and nothing may skip it.
     *
     * @throws ValidationException
     */
    public function requireMember(Organization $organization, User $member): void
    {
        if (! $organization->hasMember($member)) {
            throw ValidationException::withMessages([
                'member' => 'That person is not a member of this organization.',
            ]);
        }
    }

    /**
     * The lockout rule: the last JOINED holder of org-wide `members.manage`
     * cannot lose it - by another manager's hand or their own - because after
     * that nobody could ever grant anything again. Dormant invitees do not
     * count as holders; an account that never signed in must not be the thing
     * standing between the organization and lockout.
     *
     * @param  list<array{permission: Permission, project_id: ?int, environment_id: ?int}>  $wanted
     *
     * @throws ValidationException
     */
    public function assertNotLastManager(Organization $organization, User $member, array $wanted): void
    {
        $keepsManaging = collect($wanted)->contains(
            fn (array $row) => $row['permission'] === Permission::ManageMembers
                && $row['project_id'] === null
                && $row['environment_id'] === null,
        );

        if ($keepsManaging) {
            return;
        }

        $holdsToday = Grant::query()
            ->where('user_id', $member->id)
            ->where('organization_id', $organization->id)
            ->where('permission', Permission::ManageMembers->value)
            ->whereNull('project_id')
            ->exists();

        if (! $holdsToday) {
            return;
        }

        $otherJoinedManagers = Grant::query()
            ->where('organization_id', $organization->id)
            ->where('permission', Permission::ManageMembers->value)
            ->whereNull('project_id')
            ->where('user_id', '!=', $member->id)
            ->whereIn('user_id', $organization->members()
                ->wherePivotNotNull('joined_at')
                ->select('users.id'))
            ->exists();

        if ($otherJoinedManagers) {
            return;
        }

        throw ValidationException::withMessages([
            'grants' => 'This is the last person who can manage access - grant someone else members.manage first.',
        ]);
    }

    /**
     * Invite escalation: attaching ANY administrative permission to an invite
     * takes `members.manage` - otherwise `members.invite` alone would mint
     * fully-powered accounts. Environment actions ride on the invite right
     * itself.
     *
     * @param  list<array{permission: Permission, project_id: ?int, environment_id: ?int}>  $wanted
     *
     * @throws AuthorizationException
     */
    public function authorizeInviteGrants(User $inviter, Organization $organization, array $wanted): void
    {
        $administrative = collect($wanted)
            ->map(fn (array $row) => $row['permission'])
            ->filter(fn (Permission $permission) => $permission->isAdministrative());

        if ($administrative->isEmpty()) {
            return;
        }

        if ($this->access->can($inviter, Permission::ManageMembers, $organization)) {
            return;
        }

        $this->audit->failure(
            'invitation.privilege-denied',
            properties: [
                'permissions' => $administrative
                    ->map(fn (Permission $permission) => $permission->value)
                    ->values()
                    ->all(),
            ],
            scope: AuditScope::make(organizationId: $organization->id),
            causer: $inviter,
        );

        throw new AuthorizationException(
            'Attaching administrative permissions to an invite takes members.manage.',
        );
    }
}
