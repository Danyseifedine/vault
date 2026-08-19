<?php

namespace App\Services\Dashboard;

use App\Enums\Permission;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Group;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Support\Collection;

/**
 * Everything the project's management screens need: the shape of the project,
 * who reaches what, and which controls the viewer is allowed to see at all.
 *
 * Kept out of ProjectDashboard so neither file has to do two jobs - this one is
 * about administering the project, that one is about reading it.
 */
class ProjectAdminData
{
    public function __construct(private AccessResolver $access) {}

    /** @return array<string, mixed> */
    public function for(Project $project, User $viewer): array
    {
        return [
            'can' => $this->can($project, $viewer),
            // The permission names in the SAME order as the dashboard's labels,
            // so the matrix UI never has to hardcode the enum's order.
            'permissionKeys' => array_map(fn (Permission $p) => $p->value, Permission::environmentActions()),
            'settings' => $this->settings($project),
            'environments' => $this->environments($project),
            'groups' => $this->groups($project),
            'tags' => $this->tags($project),
            'people' => $this->people($project),
        ];
    }

    /**
     * What the viewer may do here. The UI locks what it would only be refused
     * for - the server refuses it regardless. Project-native permissions
     * resolve against THIS project; org-native ones against the organization.
     *
     * @return array<string, bool>
     */
    private function can(Project $project, User $viewer): array
    {
        $organization = $project->organization;

        return [
            'updateSettings' => $this->access->can($viewer, Permission::UpdateSettings, $project),
            'manageEnvironments' => $this->access->can($viewer, Permission::ManageEnvironments, $project),
            'manageGroups' => $this->access->can($viewer, Permission::ManageGroups, $project),
            'manageMembers' => $this->access->can($viewer, Permission::ManageMembers, $organization),
            'inviteMembers' => $this->access->can($viewer, Permission::InviteMembers, $organization),
            'createTags' => $this->access->can($viewer, Permission::CreateTags, $project),
            'createGlobalTags' => $this->access->can($viewer, Permission::CreateGlobalTags, $organization),
        ];
    }

    /** @return array<string, mixed> */
    private function settings(Project $project): array
    {
        $settings = $project->settings ?? $project->settings()->make();

        return [
            'auditViews' => (bool) $settings->audit_views,
            'pinMaxAttempts' => (int) $settings->pin_max_attempts,
            'pinLockoutMinutes' => (int) $settings->pin_lockout_minutes,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function environments(Project $project): array
    {
        return $project->environments()
            ->with('revealPolicies')
            ->get()
            ->map(fn (Environment $environment) => [
                'id' => $environment->id,
                'slug' => $environment->slug,
                'name' => $environment->name,
                'position' => $environment->position,
                // The reveal matrix: what each sensitivity costs here.
                'policies' => collect(Sensitivity::cases())
                    ->mapWithKeys(fn (Sensitivity $sensitivity) => [
                        $sensitivity->value => $environment->requirementFor($sensitivity)->value,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function groups(Project $project): array
    {
        return $project->groups()
            ->withCount('variables')
            ->get()
            ->map(fn (Group $group) => [
                'id' => $group->id,
                'slug' => $group->slug,
                'name' => $group->name,
                'variables' => $group->variables_count,
            ])
            ->all();
    }

    /**
     * Tags this project's variables may wear: its own, its environments', and
     * the organization's global vocabulary.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tags(Project $project): array
    {
        return Tag::availableFor($project)
            ->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => (string) $tag->name,
                'projectId' => $tag->project_id,
                'environmentId' => $tag->environment_id,
                'scope' => $tag->isEnvironmentScoped() ? 'environment' : ($tag->isGlobal() ? 'organization' : 'project'),
            ])
            ->values()
            ->all();
    }

    /**
     * Everyone whose grants reach this project, each with their raw rows split
     * by scope - exactly what the grant checklist edits.
     *
     * @return array<int, array<string, mixed>>
     */
    private function people(Project $project): array
    {
        $members = User::query()
            ->whereIn('id', $this->access->projectMemberIds($project))
            ->orderBy('name')
            ->get();

        $grants = Grant::query()
            ->where('organization_id', $project->organization_id)
            ->whereIn('user_id', $members->modelKeys())
            ->where(fn ($query) => $query
                ->whereNull('project_id')
                ->orWhere('project_id', $project->id))
            ->get()
            ->groupBy('user_id');

        return $members
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->displayName(),
                'email' => $member->email,
                'grants' => $this->grantsFor($grants->get($member->id, collect())),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Grant>  $grants
     * @return array<string, mixed> organization/project-wide permission lists + per-environment map
     */
    private function grantsFor(Collection $grants): array
    {
        return [
            // Org-wide rows shown for context; the project screen cannot edit them.
            'organization' => $grants
                ->filter(fn (Grant $grant) => $grant->project_id === null)
                ->map(fn (Grant $grant) => $grant->permission->value)
                ->values()
                ->all(),
            'project' => $grants
                ->filter(fn (Grant $grant) => $grant->project_id !== null && $grant->environment_id === null)
                ->map(fn (Grant $grant) => $grant->permission->value)
                ->values()
                ->all(),
            'environments' => $grants
                ->filter(fn (Grant $grant) => $grant->environment_id !== null)
                ->groupBy('environment_id')
                ->map(fn (Collection $forEnvironment) => $forEnvironment
                    ->map(fn (Grant $grant) => $grant->permission->value)
                    ->values()
                    ->all())
                ->all(),
        ];
    }
}
