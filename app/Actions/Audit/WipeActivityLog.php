<?php

namespace App\Actions\Audit;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AnchorWriter;
use App\Services\Audit\AppendOnlyLog;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Audit\ChainRelinker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Empties one organization's activity log.
 *
 * This is the only code path in the product allowed to remove audit rows, and
 * it exists because the product asked for it, not because the design wants it.
 * Everything here is arranged around one bargain:
 *
 *   entries can be destroyed - the fact that they were destroyed cannot.
 *
 * So a wipe always ends by writing a marker recording who did it, how many
 * rows went, and the chain head that stood immediately before. A marker is
 * itself never removable by a later wipe, which means the history of wipes
 * only ever grows and can never be flattened to "nothing happened here".
 *
 * What is genuinely lost, and cannot be recovered by any arrangement of code:
 * re-linking the survivors re-hashes them, so tampering that predates a wipe
 * stops being provable. A wipe launders the history before it. That is the
 * price of the feature; the marker is what keeps it visible.
 */
class WipeActivityLog
{
    /** The event a marker carries. Never removed by a wipe. */
    public const MARKER = 'audit.wiped';

    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
        private AnchorWriter $anchors,
        private ChainRelinker $relinker,
    ) {}

    /**
     * @param  Project|null  $project  Narrows the wipe to one project's entries.
     *                                 Without it the whole organization goes,
     *                                 project rows included.
     */
    public function __invoke(Organization $organization, User $actor, ?Project $project = null): int
    {
        // An id in a URL is not proof of belonging, and a wipe is the last
        // place to find that out late.
        if ($project !== null && $project->organization_id !== $organization->id) {
            throw new AuthorizationException('That project does not belong to this organization.');
        }

        $scope = AuditScope::make($organization->id, $project?->id);

        // `audit.wipe` is organization-native, so a project wipe takes the same
        // grant as an organization one - a narrower target, never a narrower
        // permission. Nobody holds "may destroy the evidence for project X only".
        if (! $this->access->can($actor, Permission::WipeActivity, $organization)) {
            $this->audit->failure(
                'audit.wipe-denied',
                properties: array_filter([
                    'organization' => $organization->name,
                    'project' => $project?->name,
                ]),
                scope: $scope,
                causer: $actor,
            );

            throw new AuthorizationException('You do not have permission to wipe this activity log.');
        }

        $removable = AuditLog::query()
            ->forOrganization($organization->id)
            ->where('event', '!=', self::MARKER)
            ->when($project !== null, fn ($query) => $query->forProject($project->id));

        $removed = (clone $removable)->count();

        // The head BEFORE anything is touched. Recorded in the marker so the
        // rewrite has a witness: anyone holding an older anchor can see
        // exactly which chain this log used to be.
        $previousHead = AuditLog::query()->orderByDesc('id')->value('hash');

        $driver = DB::getDriverName();

        // The triggers are the reason a wipe cannot happen by accident anywhere
        // else. They come off for the length of the wipe and go straight back
        // on. This is DROP/CREATE TRIGGER - DDL - and MUST stay OUTSIDE the
        // transaction: on MySQL and MariaDB a DDL statement forces an implicit
        // commit, so dropping a trigger inside DB::transaction() would silently
        // commit the transaction the wrapper opened and leave its later COMMIT
        // with nothing to close ("There is no active transaction"). SQLite does
        // not implicit-commit here, which is why the test suite never saw it.
        foreach (AppendOnlyLog::unprotect($driver) as $statement) {
            DB::unprepared($statement);
        }

        try {
            // Only the DML is transactional - and now it genuinely is. The
            // delete and the re-link either both land or neither does; a failure
            // half way through no longer leaves a partly re-hashed chain.
            DB::transaction(function () use ($removable): void {
                $removable->delete();

                // The chain is global, so those deletions left holes in other
                // organizations' links too. Without this nothing verifies again.
                $this->relinker->relink();
            });
        } finally {
            // Always re-protect, even if the wipe threw - the log must never be
            // left mutable.
            foreach (AppendOnlyLog::protect($driver) as $statement) {
                DB::unprepared($statement);
            }
        }

        // Written after the triggers are back, through the ordinary recorder,
        // so the marker is chained exactly like every other entry.
        $this->audit->record(
            self::MARKER,
            properties: array_filter([
                'organization' => $organization->name,
                'project' => $project?->name,
                'removed' => $removed,
                'previous_head' => $previousHead,
            ], fn (mixed $value) => $value !== null),
            scope: $scope,
            causer: $actor,
        );

        // The old anchor describes a chain that no longer exists; leaving it
        // would report tamper on every verify and teach everyone to ignore
        // the alarm.
        $this->anchors->write();

        return $removed;
    }
}
