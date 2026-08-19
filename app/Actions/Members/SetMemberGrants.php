<?php

namespace App\Actions\Members;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\GrantWriter;
use App\Services\Access\MemberChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;

/**
 * Makes a member's grants BE the given set - the one write path for editing
 * anyone's access after the invite. With $scope, only rows inside that project
 * are replaced (the project members screen edits one project at a time); the
 * lockout rule only applies to unscoped rewrites, because a scoped one cannot
 * touch org-wide rows by construction.
 */
class SetMemberGrants
{
    public function __construct(
        private MemberChangeGuard $guard,
        private GrantWriter $writer,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $specs
     */
    public function __invoke(
        Organization $organization,
        User $actor,
        User $member,
        array $specs,
        ?Project $scope = null,
    ): void {
        $this->guard->authorize($actor, $organization, 'member.grants-denied', [
            'member' => $member->email,
        ]);
        $this->guard->requireMember($organization, $member);

        $wanted = $this->writer->normalize($organization, $specs);

        if ($scope === null) {
            $this->guard->assertNotLastManager($organization, $member, $wanted);
        }

        [$granted, $revoked] = $this->writer->replace($organization, $member, $specs, $actor, $scope);

        $this->audit->record(
            'member.grants-updated',
            properties: [
                'member' => $member->email,
                'granted' => $granted,
                'revoked' => $revoked,
            ] + ($scope === null ? [] : ['project' => $scope->slug]),
            scope: AuditScope::make($organization->id, $scope?->id),
            causer: $actor,
        );
    }
}
