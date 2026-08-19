<?php

namespace App\Actions\Organizations;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\OrganizationChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;

/**
 * Renames an organization.
 *
 * The slug follows the name, as it does for projects, environments and groups:
 * a stale slug leaves the URL lying about what the thing is called. The
 * cost is real - links already pasted into a chat stop resolving - but it is
 * the rule the rest of the product already follows, and one thing behaving
 * differently would be worse than the cost itself.
 */
class UpdateOrganization
{
    public function __construct(
        private OrganizationChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Organization $organization, User $actor, string $name): Organization
    {
        $this->guard->authorize(
            $actor,
            $organization,
            Permission::UpdateOrganization,
            'organization.rename-denied',
            ['to' => trim($name)],
        );

        $was = $organization->name;

        // Clearing the slug is what lets sluggable rebuild it from the new name.
        $organization->fill(['name' => trim($name), 'slug' => null])->save();

        $this->audit->record(
            'organization.renamed',
            subject: $organization,
            properties: ['from' => $was, 'to' => $organization->name],
            scope: AuditScope::make(organizationId: $organization->id),
            causer: $actor,
        );

        return $organization;
    }
}
