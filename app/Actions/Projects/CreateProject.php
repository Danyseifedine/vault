<?php

namespace App\Actions\Projects;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;

class CreateProject
{
    public function __construct(
        private AuditRecorder $audit,
        private CreateEnvironment $createEnvironment,
    ) {}

    /**
     * @param  array<int, string>  $environments  Defaults to the usual three.
     */
    public function __invoke(
        Organization $organization,
        User $creator,
        string $name,
        ?string $description = null,
        array $environments = ['dev', 'staging', 'prod'],
    ): Project {
        return DB::transaction(function () use ($organization, $creator, $name, $description, $environments): Project {
            $project = Project::create([
                'organization_id' => $organization->id,
                'name' => $name,
                'description' => $description,
                'created_by' => $creator->id,
            ]);

            // Settings always exist: a project with no security posture would
            // have to fall back to defaults scattered through the code.
            ProjectSettings::create(['project_id' => $project->id]);

            foreach ($environments as $position => $environmentName) {
                ($this->createEnvironment)($project, $creator, $environmentName, $position, audit: false);
            }

            $this->audit->record(
                'project.created',
                subject: $project,
                properties: ['name' => $project->name, 'environments' => $environments],
                scope: AuditScope::make($organization->id, $project->id),
                causer: $creator,
            );

            return $project->refresh();
        });
    }
}
