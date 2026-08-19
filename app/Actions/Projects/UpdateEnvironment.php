<?php

namespace App\Actions\Projects;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\User;
use App\Services\Access\ProjectChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Projects\UniqueNameGuard;

/**
 * Renames or reorders an environment.
 *
 * The slug follows the name, so the URL stops lying - values, policies and
 * grants all hang off the id and are untouched by it.
 */
class UpdateEnvironment
{
    public function __construct(
        private ProjectChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Environment $environment, User $actor, string $name, ?int $position = null): Environment
    {
        $name = trim($name);
        $project = $environment->project;

        $this->guard->authorize($actor, $project, Permission::ManageEnvironments, 'environment.update-denied', [
            'environment' => $environment->slug,
            'name' => $name,
        ]);

        UniqueNameGuard::guard($project->environments()->get(), $name, 'environment', ignoreId: $environment->id);

        $previous = $environment->name;
        $attributes = ['name' => $name, 'slug' => null];

        if ($position !== null) {
            $attributes['position'] = $position;
        }

        $environment->fill($attributes)->save();

        $this->audit->record(
            'environment.updated',
            subject: $environment,
            properties: ['from' => $previous, 'to' => $name],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $actor,
        );

        return $environment;
    }
}
