<?php

namespace App\Actions\Projects;

use App\Enums\Permission;
use App\Enums\RevealRequirement;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\User;
use App\Services\Access\ProjectChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;

/**
 * Sets what a reveal costs for one sensitivity in one environment.
 *
 * Loosening this is the single most consequential setting in the product, so
 * it is administrative only - full access to the environment is not enough -
 * and both the old and new requirement are recorded.
 */
class UpdateRevealPolicy
{
    public function __construct(
        private ProjectChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(
        Environment $environment,
        User $actor,
        Sensitivity $sensitivity,
        RevealRequirement $requirement,
    ): void {
        $project = $environment->project;

        $this->guard->authorize($actor, $project, Permission::UpdateSettings, 'reveal-policy.update-denied', [
            'environment' => $environment->slug,
            'sensitivity' => $sensitivity->value,
            'requirement' => $requirement->value,
        ]);

        $previous = $environment->requirementFor($sensitivity);

        $environment->revealPolicies()->updateOrCreate(
            ['sensitivity' => $sensitivity],
            ['requirement' => $requirement],
        );

        $this->audit->record(
            'reveal-policy.updated',
            subject: $environment,
            properties: [
                'environment' => $environment->slug,
                'sensitivity' => $sensitivity->value,
                'from' => $previous->value,
                'to' => $requirement->value,
            ],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $actor,
        );
    }
}
