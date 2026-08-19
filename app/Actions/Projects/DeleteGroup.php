<?php

namespace App\Actions\Projects;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\User;
use App\Services\Access\ProjectChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;

/**
 * Removes a grouping. The variables inside it survive, ungrouped - a group is
 * a table of contents, and deleting the contents page must not burn the book.
 */
class DeleteGroup
{
    public function __construct(
        private ProjectChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Group $group, User $actor): void
    {
        $project = $group->project;

        $this->guard->authorize($actor, $project, Permission::ManageGroups, 'group.delete-denied', [
            'group' => $group->slug,
        ]);

        DB::transaction(function () use ($group, $project, $actor): void {
            $orphaned = $group->variables()->count();

            $group->variables()->update(['group_id' => null]);

            $this->audit->record(
                'group.deleted',
                subject: $project,
                properties: ['group' => $group->name, 'ungrouped_variables' => $orphaned],
                scope: AuditScope::make($project->organization_id, $project->id),
                causer: $actor,
            );

            $group->delete();
        });
    }
}
