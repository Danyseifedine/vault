<?php

namespace App\Actions\Projects;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\ProjectChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateProject
{
    public function __construct(
        private ProjectChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Project $project, User $actor, string $name, ?string $description = null): Project
    {
        $name = trim($name);

        $this->guard->authorize($actor, $project, Permission::UpdateSettings, 'project.update-denied', [
            'name' => $name,
        ]);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A project needs a name.']);
        }

        $this->guardAgainstDuplicate($project, $name);

        $previous = $project->name;

        // Clearing the slug lets sluggable regenerate it from the new name;
        // a stale slug would leave the URL lying about what the project is.
        $project->fill(['name' => $name, 'slug' => null, 'description' => $description])->save();

        $this->audit->record(
            'project.updated',
            subject: $project,
            properties: ['from' => $previous, 'to' => $name],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $actor,
        );

        return $project;
    }

    private function guardAgainstDuplicate(Project $project, string $name): void
    {
        $taken = Project::query()
            ->where('organization_id', $project->organization_id)
            ->whereKeyNot($project->id)
            ->get()
            ->contains(fn (Project $other) => Str::lower($other->name) === Str::lower($name));

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "This organization already has a project called \"{$name}\".",
            ]);
        }
    }
}
