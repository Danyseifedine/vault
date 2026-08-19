<?php

namespace App\Services\Dashboard;

use App\Enums\ChangeSafety;
use App\Enums\Permission;
use App\Enums\Sensitivity;
use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditPage;
use App\Services\Audit\AuditVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the project dashboard payload, mirroring
 * resources/js/pages/projects/types.ts.
 *
 * The critical rule lives here: only PUBLIC values are ever sent. Everything
 * else travels as a masked placeholder and is fetched, if at all, through
 * RevealGuard.
 */
class ProjectDashboard
{
    use FormatsInitials;

    private const ENVIRONMENT_DOTS = ['dev' => 'bg-pub', 'staging' => 'bg-sens', 'prod' => 'bg-crit'];

    public function __construct(
        private AccessResolver $access,
        private ProjectAdminData $admin,
        private AuditVisibility $visibility,
        private AuditPage $pages,
    ) {}

    /**
     * @param  array<string, mixed>  $auditQuery  page / perPage / filter, straight off the URL
     * @return array<string, mixed>
     */
    public function for(Project $project, User $viewer, array $auditQuery = []): array
    {
        $environments = $project->environments()
            // requirementFor() reads this relation for every sensitivity, so
            // it is loaded once rather than per environment per row.
            ->with('revealPolicies')
            ->get()
            ->filter(fn (Environment $environment) => $this->access->canSeeEnvironment($viewer, $environment));

        return [
            'project' => [
                // The id is what grant rows point at; slugs are for URLs.
                'id' => $project->id,
                'organizationSlug' => $project->organization->slug,
                'organizationName' => $project->organization->name,
                'slug' => $project->slug,
                'name' => $project->name,
                // Sent so a rename does not have to blank the description.
                'description' => $project->description,
            ],
            'environments' => $environments->map(fn (Environment $environment) => [
                'id' => $environment->slug,
                'dotClass' => self::ENVIRONMENT_DOTS[$environment->slug] ?? 'bg-fg-3',
            ])->values()->all(),
            // What THIS viewer may do here, per environment - so the screen can
            // lock what the server would refuse instead of letting the click
            // land on an error page.
            'viewer' => $this->viewer($project, $viewer, $environments),
            'variables' => $this->variables($project, $viewer, $environments),
            'members' => $this->members($project),
            'permissions' => array_map(fn (Permission $p) => $p->label(), Permission::environmentActions()),
            'grants' => $this->grants($project, $environments),
            // The overview card's "Recent activity" reads this, NOT the paged
            // log: switching tabs keeps the URL's ?page, and a card labelled
            // recent must never quietly show page three.
            'recentActivity' => $this->auditRows($project, $viewer, $environments, perPage: 5)['rows'],
            'auditLog' => $this->auditRows(
                $project,
                $viewer,
                $environments,
                page: (int) ($auditQuery['page'] ?? 1),
                perPage: (int) ($auditQuery['perPage'] ?? AuditPage::PER_PAGE),
                filter: (string) ($auditQuery['filter'] ?? 'all'),
            ),
            // The management screens: shape, people and permissions.
            'admin' => $this->admin->for($project, $viewer),
        ];
    }

    /**
     * The permissions matrix, per member: every environment action × every
     * environment, flattened in that order, each cell the effective answer -
     * an org-wide or project-wide row lights every environment's cell. Built
     * from ONE grants query, never cell by cell.
     *
     * @param  Collection<int, Environment>  $environments
     * @return array<string, array<int, int>>
     */
    private function grants(Project $project, Collection $environments): array
    {
        $members = $this->projectMembers($project);
        $matrix = $this->access->environmentMatrix($project, $members);

        $grants = [];

        foreach ($members as $member) {
            $row = [];

            foreach (Permission::environmentActions() as $permission) {
                foreach ($environments as $environment) {
                    $held = $matrix[$member->id][$environment->id] ?? [];
                    $row[] = in_array($permission->value, $held, true) ? 3 : 0;
                }
            }

            $grants[$member->email] = $row;
        }

        return $grants;
    }

    /**
     * Everyone whose grants reach this project.
     *
     * @return Collection<int, User>
     */
    private function projectMembers(Project $project): Collection
    {
        return User::query()
            ->whereIn('id', $this->access->projectMemberIds($project))
            ->orderBy('name')
            ->get();
    }

    /**
     * The viewer's own reach, keyed by environment slug - the same key the
     * page already uses for the environment tabs.
     *
     * @param  Collection<int, Environment>  $environments
     * @return array<string, mixed>
     */
    private function viewer(Project $project, User $viewer, Collection $environments): array
    {
        $per = [];

        foreach ($environments as $environment) {
            $canReveal = $this->access->can($viewer, Permission::RevealValues, $environment);

            $per[$environment->slug] = [
                'create' => $this->access->can($viewer, Permission::CreateVariables, $environment),
                'update' => $this->access->can($viewer, Permission::UpdateVariables, $environment),
                'rollback' => $this->access->can($viewer, Permission::RollbackVariables, $environment),
                'reveal' => $canReveal,
                'export' => $this->access->can($viewer, Permission::ExportEnv, $environment),
                'import' => $this->access->can($viewer, Permission::ImportEnv, $environment),
                /*
                 * What a reveal actually COSTS here, per sensitivity. Without
                 * it the screen can only guess from the sensitivity label,
                 * which is the DEFAULT matrix rather than this environment's:
                 * a dev opened right up would still be asked for a PIN the
                 * server was never going to check.
                 *
                 * Only for someone who holds reveal here. To anyone else the
                 * policy says nothing they could act on, and RevealGuard is
                 * the thing that decides either way.
                 */
                'policies' => $canReveal
                    ? collect(Sensitivity::cases())
                        ->mapWithKeys(fn (Sensitivity $sensitivity) => [
                            $sensitivity->value => $environment->requirementFor($sensitivity)->value,
                        ])
                        ->all()
                    : null,
            ];
        }

        return [
            'environments' => $per,
            'canTag' => $this->access->canTagVariables($viewer, $project),
            // Locked rather than hidden: a power that destroys evidence should
            // not be a surprise to the people it can be used on.
            'canWipeActivity' => $this->access
                ->can($viewer, Permission::WipeActivity, $project->organization),
        ];
    }

    /**
     * @param  Collection<int, Environment>  $environments
     * @return array<int, array<string, mixed>>
     */
    private function variables(Project $project, User $viewer, $environments): array
    {
        $defined = $project->variables()
            ->with(['group', 'values', 'tags'])
            ->orderBy('key')
            ->get();

        // Editing and deleting reach every environment the variable lives in,
        // so they are decided per variable, in one pass for the whole table.
        $actions = $this->access->variableActions($viewer, $project, $defined);

        // Whether THIS viewer may reveal in each environment, computed once. An
        // inline value is a reveal with no PIN prompt, so it may only ever be
        // sent to someone who could have clicked Reveal and been shown it.
        $canReveal = [];

        foreach ($environments as $environment) {
            $canReveal[$environment->id] = $this->access
                ->can($viewer, Permission::RevealValues, $environment);
        }

        return $defined
            ->map(function (Variable $variable) use ($environments, $actions, $canReveal) {
                $values = [];

                foreach ($environments as $environment) {
                    $value = $variable->values->firstWhere('environment_id', $environment->id);

                    if ($value === null) {
                        continue;
                    }

                    // Only PUBLIC values ever travel inline - a secret value
                    // reaches the browser through RevealGuard alone.
                    // Public is the harmless exception - but even it is inlined
                    // only when revealing here would cost nothing: the viewer
                    // holds reveal AND this environment's Public policy is None.
                    // Sensitivity is not the whole gate: an admin can raise
                    // Public above None, and a viewer may hold variables.view
                    // without variables.reveal - either way the plaintext must
                    // not ride along in the page prop.
                    $inline = $variable->sensitivity === Sensitivity::Public
                        && ($canReveal[$environment->id] ?? false)
                        && ! $environment->requirementFor(Sensitivity::Public)->requiresPin();

                    $values[$environment->slug] = $inline ? $value->value : '';
                }

                return [
                    // The id is what the reveal, value and delete endpoints
                    // address - the key alone is not addressable.
                    'id' => $variable->id,
                    'key' => $variable->key,
                    'group' => $variable->group === null ? 'ungrouped' : $variable->group->name,
                    'sensitivity' => $variable->sensitivity->value,
                    'unsafe' => $variable->change_safety === ChangeSafety::Breaking,
                    // Tag names are translated JSON on the model; pluck reads
                    // them through the accessor, which resolves the locale.
                    'tags' => $variable->tags->pluck('name')->map(strval(...))->all(),
                    'values' => $values,
                    // Both reach every environment this variable lives in, so
                    // they are answered per row rather than per environment.
                    'canEdit' => $actions[$variable->id]['edit'] ?? false,
                    'canDelete' => $actions[$variable->id]['delete'] ?? false,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function members(Project $project): array
    {
        return $this->projectMembers($project)
            ->map(fn (User $member) => [
                'name' => $member->displayName(),
                'email' => $member->email,
                'initials' => $this->initials($member->displayName()),
            ])
            ->all();
    }

    /**
     * Rows are filtered twice. By person: other people's entries need the
     * audit.view-all grant, exactly as on the organization feed. Then by
     * environment: a dev-only member reads dev history, not fifty rows of prod
     * reveals. `$environments` is already the viewer-visible set computed in
     * `for()` - entries naming no environment (project-level acts) stay.
     *
     * @param  Collection<int, Environment>  $environments
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     page: int, perPage: int, total: int, lastPage: int, filter: string,
     *     failures: int,
     * }
     */
    private function auditRows(
        Project $project,
        User $viewer,
        Collection $environments,
        int $page = 1,
        int $perPage = AuditPage::PER_PAGE,
        string $filter = 'all',
    ): array {
        $visibleSlugs = $environments->pluck('slug')->all();

        $visible = $this->visibility
            ->apply(AuditLog::query(), $viewer, $project->organization)
            ->forProject($project->id)
            // In SQL, not in PHP. This used to read the newest 200 rows and
            // filter them afterwards, which meant history past 200 entries
            // silently stopped existing - and no honest pager can be built on
            // a set that is narrowed after the limit.
            ->where(fn (Builder $scope) => $scope
                ->whereNull('properties->environment')
                ->orWhereIn('properties->environment', $visibleSlugs));

        return $this->pages->of(
            $visible,
            fn (AuditLog $entry) => [
                'time' => $entry->created_at->format('H:i:s'),
                'actor' => $entry->actorName(),
                'action' => str_replace('.', ' ', $entry->event),
                'kind' => ($entry->properties['failed'] ?? false) === true ? 'fail' : 'ok',
                'path' => collect([$entry->properties['environment'] ?? null, $entry->properties['key'] ?? null])
                    ->filter()->implode(' / ') ?: $entry->event,
                'hash' => Str::substr($entry->hash ?? '', 0, 4).'…'.Str::substr($entry->hash ?? '', -4),
            ],
            page: $page,
            perPage: $perPage,
            filter: $filter,
        );
    }
}
