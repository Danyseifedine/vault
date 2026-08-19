<?php

namespace App\Actions\Organizations;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\OrganizationChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;

/**
 * Destroys an organization and everything under it, for real.
 *
 * Projects are normally soft-deleted, because losing the only copy of a
 * production secret to a misclick is not recoverable. This is the one path
 * where that protection is deliberately dropped: an owner asking to delete a
 * whole organization is not misclicking a row, and leaving orphaned projects
 * behind an organization nobody can open would be worse than losing them.
 *
 * The audit log is NOT touched. Its table is append-only at the database level
 * and carries no foreign key to organizations for exactly this reason - a
 * record of a deletion that deletes itself is not a record.
 */
class DeleteOrganization
{
    public function __construct(
        private OrganizationChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Organization $organization, User $actor): void
    {
        $this->guard->authorize(
            $actor,
            $organization,
            Permission::DeleteOrganization,
            'organization.delete-denied',
        );

        $this->audit->record(
            'organization.deleted',
            subject: $organization,
            properties: [
                'name' => $organization->name,
                'projects' => $organization->projects()->withTrashed()->count(),
                'members' => $organization->members()->count(),
            ],
            scope: AuditScope::make(organizationId: $organization->id),
            causer: $actor,
        );

        DB::transaction(function () use ($organization): void {
            // Soft-deleted projects would otherwise survive their parent and
            // sit unreachable forever, so they are force-deleted first; the
            // database cascades everything beneath each one.
            Project::withTrashed()
                ->where('organization_id', $organization->id)
                ->get()
                ->each(fn (Project $project) => $project->forceDelete());

            $organization->forceDelete();
        });
    }
}
