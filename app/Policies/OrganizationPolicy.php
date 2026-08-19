<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\AccessResolver;

/**
 * Policies never decide anything themselves - they ask AccessResolver, so the
 * chain lives in exactly one place.
 */
class OrganizationPolicy
{
    public function __construct(private AccessResolver $access) {}

    public function view(User $user, Organization $organization): bool
    {
        return $this->access->belongsTo($user, $organization);
    }

    /**
     * The one power no grant can carry: the organization being created does
     * not exist yet, so there is nothing to scope a row to. It rides on the
     * account instead - see the users table.
     */
    public function create(User $user): bool
    {
        return $user->can_create_organizations;
    }

    public function invite(User $user, Organization $organization): bool
    {
        return $this->access->can($user, Permission::InviteMembers, $organization);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->access->can($user, Permission::ManageMembers, $organization);
    }

    public function managePins(User $user, Organization $organization): bool
    {
        return $this->access->can($user, Permission::ManagePins, $organization);
    }

    public function createProject(User $user, Organization $organization): bool
    {
        return $this->access->can($user, Permission::CreateProjects, $organization);
    }
}
