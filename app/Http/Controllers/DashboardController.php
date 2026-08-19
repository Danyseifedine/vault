<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\User;
use App\Models\Variable;
use App\Services\Access\AccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where you land after signing in: the organizations you belong to, and a
 * count of what is inside them.
 *
 * Membership is the first link of the access chain, so this is simply "the
 * organizations that have a row for you" - nothing here is discoverable by
 * someone who was never invited, and every total is computed from that same
 * set rather than from the whole database.
 */
class DashboardController extends Controller
{
    public function __construct(private AccessResolver $access) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('dashboard/index', [
            'organizations' => $this->organizationsFor($user),
            'stats' => $this->stats($user),
            'recentActivity' => $this->recentActivity($user),
            // An account capability, not a grant - there is no organization
            // yet for a grant row to belong to. Asked through the policy, like
            // every other question about what someone may do.
            'canCreateOrganizations' => $user->can('create', Organization::class),
        ]);
    }

    /**
     * What this person did lately, across every scope they touch.
     *
     * Filtered to their OWN actions - the org feeds show everyone else's, with
     * permission filtering; here causer = viewer is the filter, because you
     * cannot be shown something you did without having been there to do it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(User $user): array
    {
        // Leaving an organization forfeits its history too: entries are shown
        // only for organizations the person is STILL in, plus their personal
        // trail. Being the causer is not a lifetime claim on the payload.
        $memberOrgIds = $this->memberOf($user)->pluck('organizations.id');

        return AuditLog::query()
            ->where('causer_id', $user->id)
            ->where(fn ($query) => $query
                ->whereNull('organization_id')
                ->orWhereIn('organization_id', $memberOrgIds))
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (AuditLog $entry) => [
                'action' => str_replace(['.', '-'], [' ', ' '], $entry->event),
                'name' => $entry->properties['name']
                    ?? $entry->properties['key']
                    ?? $entry->properties['email']
                    ?? null,
                'failed' => ($entry->properties['failed'] ?? false) === true,
                'when' => $entry->created_at->diffForHumans(short: true),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function organizationsFor(User $user): array
    {
        return $this->memberOf($user)
            ->withCount(['projects', 'members'])
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $organization) => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                // What the card's context menu may offer - each its own grant.
                'canUpdate' => $this->access
                    ->can($user, Permission::UpdateOrganization, $organization),
                'canDelete' => $this->access
                    ->can($user, Permission::DeleteOrganization, $organization),
                'projects' => $organization->projects_count,
                'members' => $organization->members_count,
            ])
            ->all();
    }

    /**
     * Totals across everything this person can reach. Variables and
     * environments are counted, never listed - a number says how much there is
     * to look after without saying what any of it is called.
     *
     * @return array<string, int>
     */
    private function stats(User $user): array
    {
        $organizations = $this->memberOf($user);

        $projectIds = $organizations->clone()
            ->join('projects', 'projects.organization_id', '=', 'organizations.id')
            ->whereNull('projects.deleted_at')
            ->pluck('projects.id')
            ->all();

        return [
            'organizations' => $organizations->clone()->count(),
            'projects' => count($projectIds),
            'environments' => $projectIds === [] ? 0 : Environment::query()->whereIn('project_id', $projectIds)->count(),
            'variables' => $projectIds === [] ? 0 : Variable::query()->whereIn('project_id', $projectIds)->count(),
        ];
    }

    /** @return Builder<Organization> */
    private function memberOf(User $user)
    {
        return Organization::query()
            ->whereHas('members', fn ($query) => $query->where('users.id', $user->id));
    }
}
