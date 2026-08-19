<?php

namespace App\Services\Access;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The gate in front of every administrative change to a project's shape -
 * its environments, groups, settings and reveal policy.
 *
 * All of them ask the same question, so they ask it here, and every refusal is
 * recorded before it is thrown.
 */
final class ProjectChangeGuard
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
        Project $project,
        Permission $permission,
        string $deniedEvent,
        array $properties = [],
    ): void {
        if ($this->access->can($actor, $permission, $project)) {
            return;
        }

        $this->audit->failure(
            $deniedEvent,
            subject: $project,
            properties: $properties + ['permission' => $permission->value],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $actor,
        );

        throw new AuthorizationException('You cannot change this project.');
    }
}
