<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\AccessResolver;

class ProjectPolicy
{
    public function __construct(private AccessResolver $access) {}

    public function view(User $user, Project $project): bool
    {
        return $this->access->canAccessProject($user, $project);
    }

    public function updateSettings(User $user, Project $project): bool
    {
        return $this->access->can($user, Permission::UpdateSettings, $project);
    }

    public function manageEnvironments(User $user, Project $project): bool
    {
        return $this->access->can($user, Permission::ManageEnvironments, $project);
    }
}
