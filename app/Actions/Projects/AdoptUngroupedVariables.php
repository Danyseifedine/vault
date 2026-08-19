<?php

namespace App\Actions\Projects;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\ProjectChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;

/**
 * Gives the loose, ungrouped variables a home - the mirror of DeleteGroup,
 * which sends a group's variables back to ungrouped. "Ungrouped" is not a real
 * row, so it cannot be renamed; naming it means creating (or reusing) a group
 * and moving everything with no group into it.
 *
 * Grouping is organisation, not a change to what a variable IS - its key,
 * value, sensitivity and history are untouched - so it takes groups.manage,
 * exactly like creating, renaming or deleting a group.
 */
class AdoptUngroupedVariables
{
    public function __construct(
        private ProjectChangeGuard $guard,
        private CreateGroup $createGroup,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Project $project, User $actor, string $name): Group
    {
        $name = trim($name);

        $this->guard->authorize($actor, $project, Permission::ManageGroups, 'group.adopt-denied', [
            'name' => $name,
        ]);

        return DB::transaction(function () use ($project, $actor, $name): Group {
            // An existing name merges into that group; a new one is created
            // through CreateGroup so it is audited and name-checked like any
            // other - the same "reuse rather than refuse" the variable dialog
            // uses when you type a group name.
            $group = Group::query()
                ->where('project_id', $project->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->first()
                ?? ($this->createGroup)($project, $actor, $name);

            $adopted = $project->variables()
                ->whereNull('group_id')
                ->update(['group_id' => $group->id]);

            $this->audit->record(
                'group.adopted-ungrouped',
                subject: $project,
                properties: ['group' => $group->name, 'adopted' => $adopted],
                scope: AuditScope::make($project->organization_id, $project->id),
                causer: $actor,
            );

            return $group;
        });
    }
}
