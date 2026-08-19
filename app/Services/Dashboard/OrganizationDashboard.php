<?php

namespace App\Services\Dashboard;

use App\Enums\Permission;
use App\Enums\Sensitivity;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditPage;
use App\Services\Env\CompletenessService;
use Illuminate\Support\Str;

/**
 * Builds the organization dashboard payload.
 *
 * Shapes mirror resources/js/pages/organizations/types.ts exactly - the
 * frontend already renders these, so the contract is fixed here, not invented.
 * The people cards and the audit feeds are built by their own services; this
 * class owns the projects, tags and health numbers, and the final assembly.
 */
class OrganizationDashboard
{
    public function __construct(
        private CompletenessService $completeness,
        private AccessResolver $access,
        private AuditFeed $feed,
        private OrganizationPeople $people,
        private SharedVaultData $shared,
    ) {}

    /**
     * @param  array<string, mixed>  $activityQuery  page / perPage / filter, straight off the URL
     * @return array<string, mixed>
     */
    public function for(Organization $organization, User $viewer, array $activityQuery = []): array
    {
        $projects = $this->projects($organization, $viewer);
        $canInvite = $this->access->can($viewer, Permission::InviteMembers, $organization);
        $canManageMembers = $this->access->can($viewer, Permission::ManageMembers, $organization);
        $scopes = $this->scopes($organization, $viewer);
        // Creating a tag needs a scope to put it in: organization-wide, or a
        // project this viewer may label.
        $canCreateTags = $this->access->can($viewer, Permission::CreateGlobalTags, $organization)
            || collect($scopes)->contains(fn (array $scope) => $scope['canCreateTags'] === true);

        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                // What the viewer is allowed to *do* here, so the UI can lock
                // controls it would only be refused for.
                'canInvite' => $canInvite,
                'canCreateProjects' => $this->access
                    ->can($viewer, Permission::CreateProjects, $organization),
                'canManageMembers' => $canManageMembers,
                'canManagePins' => $this->access
                    ->can($viewer, Permission::ManagePins, $organization),
                // The only control in the product that destroys evidence, so
                // it is locked rather than hidden - a power worth knowing
                // exists, and knowing you do not hold.
                'canWipeActivity' => $this->access
                    ->can($viewer, Permission::WipeActivity, $organization),
                'canCreateGlobalTags' => $this->access
                    ->can($viewer, Permission::CreateGlobalTags, $organization),
                'canCreateTags' => $canCreateTags,
            ],
            'tags' => $this->tags($organization, $viewer),
            'projects' => $projects,
            // The grant checklist and the tag scope picker both need real
            // project and environment ids to point at - and only someone who
            // invites, edits access or labels has business seeing the map.
            'scopes' => $canInvite || $canManageMembers || $canCreateTags ? $scopes : [],
            'activity' => $this->feed->activity($organization, $viewer),
            'activitySeries' => $this->feed->series($organization, $viewer),
            'members' => $this->people->members(
                $organization,
                withPins: $this->access->can($viewer, Permission::ManagePins, $organization),
                withGrants: $canManageMembers,
            ),
            // Pending invites carry email addresses; deny-by-default says a
            // plain member has no claim on who is being recruited.
            'invites' => $canInvite ? $this->people->invites($organization) : [],
            'shared' => $this->shared->for($organization, $viewer),
            // The one screen that pages: the log is the only table with no
            // ceiling, so it is asked for a slice rather than a limit.
            'auditLog' => $this->feed->auditRows(
                $organization,
                $viewer,
                page: (int) ($activityQuery['page'] ?? 1),
                perPage: (int) ($activityQuery['perPage'] ?? AuditPage::PER_PAGE),
                filter: (string) ($activityQuery['filter'] ?? 'all'),
            ),
            'health' => $this->health($organization, $viewer, $projects),
        ];
    }

    /**
     * Project cards, filtered like everything else: you see the projects your
     * grants reach - an org-wide grant of anything project-shaped covers them
     * all. A card carries names, counts and last activity - none of which a
     * member with no access to the project is owed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function projects(Organization $organization, User $viewer): array
    {
        return $organization->projects()
            ->with(['environments', 'variables'])
            ->withCount('environments')
            ->get()
            ->filter(fn (Project $project) => $this->access->canAccessProject($viewer, $project))
            ->values()
            ->map(function (Project $project) use ($organization, $viewer) {
                $variables = $project->variables;
                $missing = array_sum($this->completeness->projectSummary($project));

                return [
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'initials' => Str::upper(Str::substr($project->slug, 0, 2)),
                    'envs' => $project->environments_count,
                    'vars' => $variables->count(),
                    'members' => $this->projectMemberCount($project),
                    'lastActivity' => $this->feed->lastActivityFor($organization, $project, $viewer),
                    'missing' => $missing,
                    'mix' => [
                        $variables->where('sensitivity', Sensitivity::Critical)->count(),
                        $variables->where('sensitivity', Sensitivity::Sensitive)->count(),
                        $variables->where('sensitivity', Sensitivity::Public)->count(),
                    ],
                ];
            })
            ->all();
    }

    /**
     * Every project with its environments, for choosing an invite's scope.
     * Ids, not slugs: a grant points at one specific environment.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scopes(Organization $organization, User $viewer): array
    {
        return $organization->projects()
            ->with('environments')
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'slug' => $project->slug,
                'name' => $project->name,
                // Tags are created per project, so the picker can only offer
                // the projects this viewer may actually label.
                'canCreateTags' => $this->access->can($viewer, Permission::CreateTags, $project),
                'environments' => $project->environments
                    ->map(fn ($environment) => [
                        'id' => $environment->id,
                        'slug' => $environment->slug,
                        'name' => $environment->name,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Every tag defined anywhere in the organization, with the reach it was
     * created with - scope is fixed at creation and never edited.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tags(Organization $organization, User $viewer): array
    {
        $projects = $organization->projects()->get()->keyBy('id');

        return Tag::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->map(function (Tag $tag) use ($organization, $viewer, $projects) {
                // The same question TagAuthority asks before a rename or a
                // delete, answered here so the row can lock instead of failing.
                $project = $tag->project_id === null ? null : $projects->get($tag->project_id);

                $canManage = $tag->isGlobal()
                    ? $this->access->can($viewer, Permission::CreateGlobalTags, $organization)
                    : $project !== null
                        && $this->access->can($viewer, Permission::CreateTags, $project);

                return [
                    'id' => $tag->id,
                    'name' => (string) $tag->name,
                    'scope' => $tag->isEnvironmentScoped() ? 'environment' : ($tag->isGlobal() ? 'organization' : 'project'),
                    'canManage' => $canManage,
                ];
            })
            ->all();
    }

    private function projectMemberCount(Project $project): int
    {
        return count($this->access->projectMemberIds($project));
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function health(Organization $organization, User $viewer, array $projects): array
    {
        $variableCount = array_sum(array_column($projects, 'vars'));
        $criticalCount = array_sum(array_map(fn ($project) => $project['mix'][0], $projects));
        $incomplete = count(array_filter($projects, fn ($project) => $project['missing'] > 0));

        $failedPins = $this->feed->failedPins($organization, $viewer);

        return [
            ['label' => 'Projects', 'value' => (string) count($projects), 'sub' => count($projects) - $incomplete.' complete', 'tone' => 'fg'],
            ['label' => 'Variables', 'value' => (string) $variableCount, 'sub' => "{$criticalCount} critical", 'tone' => 'fg'],
            ['label' => 'Failed PINs', 'value' => (string) $failedPins, 'sub' => 'last 24h', 'tone' => $failedPins > 0 ? 'crit' : 'fg'],
            ['label' => 'Incomplete envs', 'value' => (string) $incomplete, 'sub' => $incomplete === 0 ? 'all complete' : 'needs attention', 'tone' => $incomplete > 0 ? 'sens' : 'fg'],
        ];
    }
}
