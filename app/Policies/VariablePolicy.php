<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use App\Services\Access\AccessResolver;

/**
 * Every answer here comes from AccessResolver - the policy is the call site,
 * not a second copy of the rules.
 *
 * Note the split: a variable's DEFINITION is project-level, while its VALUE in
 * one environment is governed by that environment's access level.
 */
class VariablePolicy
{
    public function __construct(private AccessResolver $access) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->access->canAccessProject($user, $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->access->canWriteSomewhereIn($user, $project);
    }

    public function update(User $user, Variable $variable): bool
    {
        // Definition edits reach every environment - see canUpdateVariable.
        return $this->access->canUpdateVariable($user, $variable);
    }

    public function delete(User $user, Variable $variable): bool
    {
        return $this->access->canDeleteVariable($user, $variable);
    }

    public function tag(User $user, Variable $variable): bool
    {
        return $this->access->canTagVariables($user, $variable->project);
    }

    public function setValue(User $user, Variable $variable, Environment $environment): bool
    {
        return $this->access->can($user, Permission::UpdateVariables, $environment);
    }

    public function rollback(User $user, Variable $variable, Environment $environment): bool
    {
        return $this->access->can($user, Permission::RollbackVariables, $environment);
    }
}
