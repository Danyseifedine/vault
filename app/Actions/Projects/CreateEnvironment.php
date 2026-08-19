<?php

namespace App\Actions\Projects;

use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;

class CreateEnvironment
{
    public function __construct(private AuditRecorder $audit) {}

    public function __invoke(
        Project $project,
        User $creator,
        string $name,
        int $position = 0,
        bool $audit = true,
    ): Environment {
        return DB::transaction(function () use ($project, $creator, $name, $position, $audit): Environment {
            $environment = Environment::create([
                'project_id' => $project->id,
                'name' => $name,
                'position' => $position,
            ]);

            // Seed the reveal matrix with safe defaults so a forgotten
            // configuration fails closed, never open.
            foreach (Sensitivity::cases() as $sensitivity) {
                $environment->revealPolicies()->create([
                    'sensitivity' => $sensitivity,
                    'requirement' => $sensitivity->defaultRequirement(),
                ]);
            }

            // No creator seeding here: an org-wide or project-wide grant
            // already covers an environment born after it. Whoever can create
            // environments reaches them through the rows they already hold.
            if ($audit) {
                $this->audit->record(
                    'environment.created',
                    subject: $environment,
                    properties: ['name' => $environment->name],
                    scope: AuditScope::make($project->organization_id, $project->id),
                    causer: $creator,
                );
            }

            return $environment;
        });
    }
}
