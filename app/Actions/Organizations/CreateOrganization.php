<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Services\Access\GrantWriter;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function __construct(
        private AuditRecorder $audit,
        private GrantWriter $grants,
    ) {}

    public function __invoke(User $creator, string $name): Organization
    {
        $this->guardCapability($creator, $name);

        return DB::transaction(function () use ($creator, $name): Organization {
            $organization = Organization::create([
                'name' => $name,
                'created_by' => $creator->id,
            ]);

            $organization->members()->attach($creator->id, [
                'joined_at' => now(),
            ]);

            // Whoever creates an organization starts holding every permission
            // org-wide - otherwise it would exist with nobody able to grant
            // anything. Ordinary rows, revocable later like anyone else's.
            $this->grants->seedFullAccess($organization, $creator);

            $this->audit->record(
                'organization.created',
                subject: $organization,
                properties: ['name' => $organization->name],
                scope: AuditScope::make(organizationId: $organization->id),
                causer: $creator,
            );

            return $organization;
        });
    }

    /**
     * Starting an organization cannot be a grant - a grant belongs to an
     * organization, and this one does not exist yet - so it is an account
     * capability, handed out with `vault:allow-organizations`. Checked here as
     * well as in the policy: holding every permission inside one organization
     * must never become a way to start another one nobody can reach.
     *
     * @throws AuthorizationException
     */
    private function guardCapability(User $creator, string $name): void
    {
        if ($creator->can_create_organizations) {
            return;
        }

        $this->audit->failure(
            'organization.create-denied',
            properties: ['name' => trim($name)],
            causer: $creator,
        );

        throw new AuthorizationException('This account cannot start organizations.');
    }
}
