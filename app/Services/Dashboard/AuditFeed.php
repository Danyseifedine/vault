<?php

namespace App\Services\Dashboard;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditPage;
use App\Services\Audit\AuditVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Shapes audit entries for the organization dashboard's feeds.
 *
 * Both feeds are filtered to what the viewer may see: entries about a project
 * they have no access to must not appear, not even as a path fragment.
 */
class AuditFeed
{
    use FormatsInitials;

    public function __construct(
        private AccessResolver $access,
        private AuditVisibility $visibility,
        private AuditPage $pages,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function activity(Organization $organization, User $viewer): array
    {
        return $this->visibleEntries($organization, $viewer, limit: 8)
            ->map(fn (AuditLog $entry) => [
                'actor' => $entry->actorName(),
                'initials' => $this->initials($entry->actorName()),
                'action' => str_replace(['.', '-'], [' ', ' '], $entry->event),
                'path' => $this->pathFor($entry),
                'when' => $entry->created_at->diffForHumans(short: true),
                'kind' => $this->kindFor($entry),
            ])
            ->values()
            ->all();
    }

    /**
     * One page of the organization's activity log.
     *
     * The envelope, the paging and the filter belong to AuditPage; what is
     * this feed's own is which entries are visible and how a row reads.
     *
     * @return array<string, mixed>
     */
    public function auditRows(
        Organization $organization,
        User $viewer,
        int $page = 1,
        int $perPage = AuditPage::PER_PAGE,
        string $filter = 'all',
    ): array {
        return $this->pages->of(
            $this->visibleQuery($organization, $viewer),
            fn (AuditLog $entry) => [
                'time' => $entry->created_at->format('H:i'),
                'actor' => $entry->actorName(),
                'action' => str_replace('.', ' ', $entry->event),
                'kind' => $this->kindFor($entry),
                'path' => $this->pathFor($entry),
                'hash' => Str::substr($entry->hash ?? '', 0, 4).'…'.Str::substr($entry->hash ?? '', -4),
            ],
            page: $page,
            perPage: $perPage,
            filter: $filter,
        );
    }

    /**
     * Fourteen days of activity as two counts per day: fine, and failed.
     *
     * This feeds the dashboard's chart, whose whole job is making a bad day
     * visible - a spike of failures reads instantly where a table of rows does
     * not. Counts only, and only over entries the viewer may see.
     *
     * @return array<int, array{day: string, ok: int, fail: int}>
     */
    public function series(Organization $organization, User $viewer, int $days = 14): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

        $entries = $this->visibleQuery($organization, $viewer)
            ->where('created_at', '>=', $since)
            ->get(['created_at', 'properties'])
            ->groupBy(fn (AuditLog $entry) => $entry->created_at->format('Y-m-d'));

        return collect(range($days - 1, 0))
            ->map(function (int $back) use ($entries) {
                $date = now()->subDays($back);
                $day = $entries->get($date->format('Y-m-d'), collect());

                $failed = $day->filter(
                    fn (AuditLog $entry) => ($entry->properties['failed'] ?? false) === true,
                )->count();

                return [
                    'day' => $date->format('M j'),
                    'ok' => $day->count() - $failed,
                    'fail' => $failed,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The newest thing that happened in one project, as a single line for its
     * card - or nothing, when the only thing that happened was someone else's.
     */
    public function lastActivityFor(Organization $organization, Project $project, User $viewer): ?string
    {
        $entry = $this->visibleQuery($organization, $viewer)
            ->forProject($project->id)
            ->with('causer')
            ->latest('id')
            ->first();

        return $entry === null ? null : "{$entry->actorName()} - {$entry->event}";
    }

    /**
     * Failed PIN attempts in the last day. A count is still a fact about the
     * people who failed, so it is counted over the same visible set.
     */
    public function failedPins(Organization $organization, User $viewer): int
    {
        return $this->visibleQuery($organization, $viewer)
            ->where('event', 'pin.failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    /** @return Collection<int, AuditLog> */
    private function visibleEntries(Organization $organization, User $viewer, int $limit): Collection
    {
        return $this->visibleQuery($organization, $viewer)
            ->with('causer')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The one definition of "entries this viewer may see here".
     *
     * Reading other people's trails is a permission of its own, and holding it
     * means the whole organization: an auditor who cannot see every project
     * cannot audit. Without it you get your own entries, and only inside the
     * projects you still reach - your own act in a project you have since lost
     * would otherwise name that project back to you.
     *
     * @return Builder<AuditLog>
     */
    private function visibleQuery(Organization $organization, User $viewer)
    {
        $query = AuditLog::query()->forOrganization($organization->id);

        if ($this->visibility->readsEveryone($viewer, $organization)) {
            return $query;
        }

        $reachableProjectIds = $organization->projects
            ->filter(fn (Project $project) => $this->access->canAccessProject($viewer, $project))
            ->pluck('id');

        return $this->visibility->apply($query, $viewer, $organization)
            ->where(fn (Builder $scope) => $scope
                ->whereIn('project_id', $reachableProjectIds)
                // Org-level acts of their own: invited, PIN issued, granted.
                ->orWhereNull('project_id'));
    }

    private function pathFor(AuditLog $entry): string
    {
        $properties = $entry->properties;

        return collect([
            $properties['key'] ?? null,
            $properties['environment'] ?? null,
            $properties['email'] ?? null,
            $properties['name'] ?? null,
        ])->filter()->implode(' / ') ?: $entry->event;
    }

    private function kindFor(AuditLog $entry): string
    {
        if (($entry->properties['failed'] ?? false) === true) {
            return 'fail';
        }

        return str_contains($entry->event, 'updated') || str_contains($entry->event, 'settings')
            ? 'warn'
            : 'ok';
    }
}
