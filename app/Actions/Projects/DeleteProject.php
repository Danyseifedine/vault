<?php

namespace App\Actions\Projects;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\ProjectChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;

/**
 * Soft-deletes a project. Its variables and their history stay on disk: losing
 * the only copy of a production secret to a misclick is not recoverable, and
 * the audit trail must keep pointing at something real.
 */
class DeleteProject
{
    public function __construct(
        private ProjectChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Project $project, User $actor): void
    {
        $this->guard->authorize($actor, $project, Permission::UpdateSettings, 'project.delete-denied', [
            'name' => $project->name,
        ]);

        $this->audit->record(
            'project.deleted',
            subject: $project,
            properties: ['name' => $project->name, 'variables' => $project->variables()->count()],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $actor,
        );

        $project->delete();
    }
}
