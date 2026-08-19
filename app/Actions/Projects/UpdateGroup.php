<?php

namespace App\Actions\Projects;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\User;
use App\Services\Access\ProjectChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Projects\UniqueNameGuard;

class UpdateGroup
{
    public function __construct(
        private ProjectChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Group $group, User $actor, string $name, ?int $position = null): Group
    {
        $name = trim($name);
        $project = $group->project;

        $this->guard->authorize($actor, $project, Permission::ManageGroups, 'group.update-denied', ['name' => $name]);

        UniqueNameGuard::guard($project->groups()->get(), $name, 'group', ignoreId: $group->id);

        $previous = $group->name;

        // A null slug lets sluggable rebuild it from the new name.
        $attributes = ['name' => $name, 'slug' => null];

        if ($position !== null) {
            $attributes['position'] = $position;
        }

        $group->fill($attributes)->save();

        $this->audit->record(
            'group.updated',
            subject: $group,
            properties: ['from' => $previous, 'to' => $name],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $actor,
        );

        return $group;
    }
}
