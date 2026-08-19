<?php

namespace App\Services\Access;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The gate in front of changing an organization itself - its name, and its
 * existence. Renaming takes `organization.update`, destroying takes
 * `organization.delete`: separate grants, because they are separate trusts.
 * Every refusal is recorded before it is thrown, including from strangers,
 * because a stranger reaching this URL at all is worth knowing about.
 */
final class OrganizationChangeGuard
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
        Organization $organization,
        Permission $permission,
        string $deniedEvent,
        array $properties = [],
    ): void {
        if ($this->access->can($actor, $permission, $organization)) {
            return;
        }

        $this->audit->failure(
            $deniedEvent,
            subject: $organization,
            properties: $properties + ['name' => $organization->name],
            scope: AuditScope::make(organizationId: $organization->id),
            causer: $actor,
        );

        throw new AuthorizationException('You are not allowed to change this organization.');
    }
}
