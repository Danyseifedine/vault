<?php

namespace Tests\Concerns;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

/**
 * The one seam feature tests use to build people and hand them access, so the
 * grants shape is written in exactly one place.
 */
trait ManagesAccess
{
    /** A joined member with NO grants - the deny-by-default baseline. */
    protected function joinMember(Organization $organization, ?User $user = null): User
    {
        $user ??= User::factory()->onboarded()->create();

        $organization->members()->syncWithoutDetaching([
            $user->id => ['joined_at' => now()],
        ]);

        return $user;
    }

    /**
     * Hand $user one or more permissions over a scope. The scope argument
     * decides the row: an Organization writes org-wide rows, a Project writes
     * project rows, an Environment writes environment rows.
     *
     * @param  Permission|array<int, Permission>  $permissions
     */
    protected function grant(
        User $user,
        Permission|array $permissions,
        Organization|Project|Environment $scope,
    ): void {
        [$organization, $projectId, $environmentId] = match (true) {
            $scope instanceof Organization => [$scope, null, null],
            $scope instanceof Project => [$scope->organization, $scope->id, null],
            $scope instanceof Environment => [
                $scope->project->organization,
                $scope->project_id,
                $scope->id,
            ],
        };

        foreach (is_array($permissions) ? $permissions : [$permissions] as $permission) {
            Grant::firstOrCreate([
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'project_id' => $projectId,
                'environment_id' => $environmentId,
                'permission' => $permission->value,
            ]);
        }
    }

    /** A member holding every permission org-wide - the creator's shape. */
    protected function fullManager(Organization $organization, ?User $user = null): User
    {
        $user = $this->joinMember($organization, $user);

        $this->grant($user, Permission::cases(), $organization);

        return $user;
    }

    /** The read-a-value bundle for one environment: view + reveal + export. */
    protected function grantReader(User $user, Environment $environment): void
    {
        $this->grant($user, [
            Permission::ViewVariables,
            Permission::RevealValues,
            Permission::ExportEnv,
        ], $environment);
    }

    /** Reader + create/update/rollback/import for one environment. */
    protected function grantWriter(User $user, Environment $environment): void
    {
        $this->grantReader($user, $environment);
        $this->grant($user, [
            Permission::CreateVariables,
            Permission::UpdateVariables,
            Permission::RollbackVariables,
            Permission::ImportEnv,
        ], $environment);
    }

    /** Writer + delete - what "full" used to mean, per environment. */
    protected function grantFullAccess(User $user, Environment $environment): void
    {
        $this->grantWriter($user, $environment);
        $this->grant($user, Permission::DeleteVariables, $environment);
    }
}
