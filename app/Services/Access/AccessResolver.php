<?php

namespace App\Services\Access;

use App\Enums\GrantScope;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one place that knows how access is decided.
 *
 * There are no roles. Every power is an explicit row in `grants`, scoped to
 * the whole organization, one project, or one environment - and a row covers
 * everything beneath its scope, including things created after it was
 * written. Membership opens the organization page and nothing else. The
 * default answer is always no.
 */
class AccessResolver
{
    public function belongsTo(User $user, Organization $organization): bool
    {
        return $organization->members()->where('users.id', $user->id)->exists();
    }

    /**
     * Does $user hold $permission over the WHOLE of $where? Covered by an org
     * row always; by a project row when $where is that project or inside it;
     * by an environment row only when $where is that environment.
     */
    public function can(User $user, Permission $permission, Organization|Project|Environment $where): bool
    {
        $organization = $this->organizationOf($where);

        if (! $this->belongsTo($user, $organization)) {
            return false;
        }

        return Grant::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->where('permission', $permission->value)
            ->where(fn (Builder $query) => $this->whereCovers($query, $where))
            ->exists();
    }

    /** Holds $permission org-wide, project-wide, or in ANY environment of $project. */
    public function holdsSomewhereIn(User $user, Permission $permission, Project $project): bool
    {
        if (! $this->belongsTo($user, $project->organization)) {
            return false;
        }

        return Grant::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $project->organization_id)
            ->where('permission', $permission->value)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $organizationWide) => $organizationWide
                    ->whereNull('project_id')
                    ->whereNull('environment_id'))
                ->orWhere('project_id', $project->id))
            ->exists();
    }

    /**
     * A project page opens for anyone holding a grant that MEANS something
     * there: a project- or environment-native permission covering the project
     * or sitting inside it. Org-native powers do not open projects - inviting
     * people is not a reason to read a variable list.
     */
    public function canAccessProject(User $user, Project $project): bool
    {
        if (! $this->belongsTo($user, $project->organization)) {
            return false;
        }

        return Grant::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $project->organization_id)
            ->whereIn('permission', $this->permissionsMeaningfulBelowOrganization())
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $organizationWide) => $organizationWide
                    ->whereNull('project_id')
                    ->whereNull('environment_id'))
                ->orWhere('project_id', $project->id))
            ->exists();
    }

    /** The same idea one level down: which environments show up for this user. */
    public function canSeeEnvironment(User $user, Environment $environment): bool
    {
        $project = $environment->project;

        if (! $this->belongsTo($user, $project->organization)) {
            return false;
        }

        return Grant::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $project->organization_id)
            ->whereIn('permission', $this->permissionsMeaningfulBelowOrganization())
            ->where(fn (Builder $query) => $query
                ->whereNull('project_id')
                ->orWhere('project_id', $project->id))
            ->where(fn (Builder $query) => $query
                ->whereNull('environment_id')
                ->orWhere('environment_id', $environment->id))
            ->exists();
    }

    /**
     * Some things a variable owns - its key, its description, its labels - are
     * project-level rather than per-environment. Being trusted to write in any
     * one environment of the project is the bar for changing those.
     */
    public function canWriteSomewhereIn(User $user, Project $project): bool
    {
        return $this->holdsSomewhereIn($user, Permission::CreateVariables, $project)
            || $this->holdsSomewhereIn($user, Permission::UpdateVariables, $project);
    }

    /**
     * Labelling a variable is a project-wide act: either you may create tags
     * for this project, or you can write variables somewhere inside it.
     */
    public function canTagVariables(User $user, Project $project): bool
    {
        return $this->can($user, Permission::CreateTags, $project)
            || $this->canWriteSomewhereIn($user, $project);
    }

    /**
     * Deleting a variable removes it from EVERY environment at once, so it
     * takes delete rights in every environment the variable actually lives in.
     * Delete rights on dev must not reach into prod by the back door.
     */
    public function canDeleteVariable(User $user, Variable $variable): bool
    {
        return $this->variableActions($user, $variable->project, collect([$variable]))[$variable->id]['delete'];
    }

    /**
     * Editing the DEFINITION - key, sensitivity, labels - also reaches every
     * environment at once: relabelling critical to public strips the PIN
     * requirement everywhere. Same bar as deleting, with update rights.
     */
    public function canUpdateVariable(User $user, Variable $variable): bool
    {
        return $this->variableActions($user, $variable->project, collect([$variable]))[$variable->id]['edit'];
    }

    /**
     * The same two answers for a whole page of variables, in one pass - the
     * table needs them per row, and asking per row would mean a query storm.
     * This is the single implementation; the two methods above are one-row
     * calls into it, so the rule cannot drift between the table and the guard.
     *
     * @param  Collection<int, Variable>  $variables
     * @return array<int, array{edit: bool, delete: bool}> keyed by variable id
     */
    public function variableActions(User $user, Project $project, Collection $variables): array
    {
        if ($variables->isEmpty()) {
            return [];
        }

        $held = $this->environmentMatrix($project, collect([$user]))[$user->id] ?? [];
        $writesSomewhere = $this->canWriteSomewhereIn($user, $project);

        $environmentsOf = VariableValue::query()
            ->whereIn('variable_id', $variables->pluck('id'))
            ->get(['variable_id', 'environment_id'])
            ->groupBy('variable_id')
            ->map(fn (Collection $rows) => $rows->pluck('environment_id')->all());

        $actions = [];

        foreach ($variables as $variable) {
            $livesIn = $environmentsOf[$variable->id] ?? [];

            // A variable with no value anywhere is only a definition, so the
            // bar is writing somewhere in the project rather than everywhere.
            $everywhere = fn (Permission $permission): bool => $livesIn === []
                ? $writesSomewhere
                : collect($livesIn)->every(
                    fn (int $environmentId) => in_array(
                        $permission->value,
                        $held[$environmentId] ?? [],
                        true,
                    ),
                );

            $actions[$variable->id] = [
                'edit' => $everywhere(Permission::UpdateVariables),
                'delete' => $everywhere(Permission::DeleteVariables),
            ];
        }

        return $actions;
    }

    /**
     * The whole permissions-by-environment picture for a project's people in
     * ONE query - the dashboard matrix must not resolve cell by cell.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array<int, array<int, string>>> userId => environmentId => permission values
     */
    public function environmentMatrix(Project $project, Collection $users): array
    {
        $environmentIds = $project->environments()->pluck('id')->all();
        $actions = array_map(
            fn (Permission $permission) => $permission->value,
            Permission::environmentActions(),
        );

        $rows = Grant::query()
            ->where('organization_id', $project->organization_id)
            ->whereIn('user_id', $users->pluck('id'))
            ->whereIn('permission', $actions)
            ->where(fn (Builder $query) => $query
                ->whereNull('project_id')
                ->orWhere('project_id', $project->id))
            ->get();

        $matrix = [];

        foreach ($rows as $grant) {
            // A row above environment scope applies to every environment.
            $reaches = $grant->environment_id === null
                ? $environmentIds
                : [$grant->environment_id];

            foreach ($reaches as $environmentId) {
                $matrix[$grant->user_id][$environmentId][] = $grant->permission->value;
            }
        }

        return $matrix;
    }

    /**
     * Everyone holding any grant that opens this project - the dashboards'
     * definition of "a member of the project".
     *
     * @return array<int, int>
     */
    public function projectMemberIds(Project $project): array
    {
        return Grant::query()
            ->where('organization_id', $project->organization_id)
            ->whereIn('permission', $this->permissionsMeaningfulBelowOrganization())
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $organizationWide) => $organizationWide
                    ->whereNull('project_id')
                    ->whereNull('environment_id'))
                ->orWhere('project_id', $project->id))
            ->distinct()
            ->pluck('user_id')
            ->all();
    }

    private function organizationOf(Organization|Project|Environment $where): Organization
    {
        return match (true) {
            $where instanceof Organization => $where,
            $where instanceof Project => $where->organization,
            $where instanceof Environment => $where->project->organization,
        };
    }

    /**
     * The containment condition: rows whose scope includes $where.
     *
     * @param  Builder<Grant>  $query
     */
    private function whereCovers(Builder $query, Organization|Project|Environment $where): void
    {
        if ($where instanceof Organization) {
            $query->whereNull('project_id')->whereNull('environment_id');

            return;
        }

        if ($where instanceof Project) {
            $query
                ->where(fn (Builder $project) => $project
                    ->whereNull('project_id')
                    ->orWhere('project_id', $where->id))
                ->whereNull('environment_id');

            return;
        }

        $query
            ->where(fn (Builder $project) => $project
                ->whereNull('project_id')
                ->orWhere('project_id', $where->project_id))
            ->where(fn (Builder $environment) => $environment
                ->whereNull('environment_id')
                ->orWhere('environment_id', $where->id));
    }

    /** @return array<int, string> */
    private function permissionsMeaningfulBelowOrganization(): array
    {
        return array_map(
            fn (Permission $permission) => $permission->value,
            array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission) => $permission->scope() !== GrantScope::Organization,
            )),
        );
    }
}
