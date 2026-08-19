<?php

namespace App\Actions\Projects;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\ProjectChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Projects\UniqueNameGuard;

class CreateGroup
{
    public function __construct(
        private ProjectChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Project $project, User $actor, string $name, int $position = 0): Group
    {
        $name = trim($name);

        $this->guard->authorize($actor, $project, Permission::ManageGroups, 'group.create-denied', ['name' => $name]);

        UniqueNameGuard::guard($project->groups()->get(), $name, 'group');

        $group = $project->groups()->create(['name' => $name, 'position' => $position]);

        $this->audit->record(
            'group.created',
            subject: $group,
            properties: ['name' => $name],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $actor,
        );

        return $group;
    }
}
