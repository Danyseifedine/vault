<?php

namespace App\Actions\Projects;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\User;
use App\Services\Access\ProjectChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Validation\ValidationException;

/**
 * Removes an environment and everything that only existed inside it.
 *
 * This one really deletes: values, versions and the reveal matrix go with it.
 * The audit entry records how much was destroyed, because afterwards nothing
 * else can say.
 */
class DeleteEnvironment
{
    public function __construct(
        private ProjectChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Environment $environment, User $actor): void
    {
        $project = $environment->project;

        $this->guard->authorize($actor, $project, Permission::ManageEnvironments, 'environment.delete-denied', [
            'environment' => $environment->slug,
        ]);

        if ($project->environments()->count() <= 1) {
            throw ValidationException::withMessages([
                'environment' => 'A project must keep at least one environment.',
            ]);
        }

        $this->audit->record(
            'environment.deleted',
            subject: $project,
            properties: [
                'environment' => $environment->slug,
                'values' => $environment->values()->count(),
            ],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $actor,
        );

        $environment->delete();
    }
}
