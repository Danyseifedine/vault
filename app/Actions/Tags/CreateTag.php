<?php

namespace App\Actions\Tags;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Defines a label and how far it reaches.
 *
 * Three entry points rather than a scope argument, because the authority
 * differs: an organization-wide tag takes `tags.create-global` on the
 * organization, while project and environment tags take `tags.create`
 * covering their project.
 */
class CreateTag
{
    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
    ) {}

    public function global(Organization $organization, User $author, string $name): Tag
    {
        return $this->create(
            $organization, $author, $name,
            permission: Permission::CreateGlobalTags,
            authority: $organization,
            scope: AuditScope::make(organizationId: $organization->id),
        );
    }

    public function forProject(Project $project, User $author, string $name): Tag
    {
        return $this->create(
            $project->organization, $author, $name,
            permission: Permission::CreateTags,
            authority: $project,
            scope: AuditScope::make($project->organization_id, $project->id),
            projectId: $project->id,
        );
    }

    public function forEnvironment(Environment $environment, User $author, string $name): Tag
    {
        $project = $environment->project;

        return $this->create(
            $project->organization, $author, $name,
            permission: Permission::CreateTags,
            authority: $project,
            scope: AuditScope::make($project->organization_id, $project->id),
            projectId: $project->id,
            environmentId: $environment->id,
        );
    }

    private function create(
        Organization $organization,
        User $author,
        string $name,
        Permission $permission,
        Organization|Project $authority,
        AuditScope $scope,
        ?int $projectId = null,
        ?int $environmentId = null,
    ): Tag {
        $name = trim($name);

        if (! $this->access->can($author, $permission, $authority)) {
            $this->audit->failure(
                'tag.create-denied',
                properties: ['name' => $name, 'scope' => $permission->value],
                scope: $scope,
                causer: $author,
            );

            throw new AuthorizationException($permission === Permission::CreateGlobalTags
                ? 'Creating organization-wide tags takes the tags.create-global permission.'
                : 'You cannot create tags in this project.');
        }

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A tag needs a name.']);
        }

        $this->guardAgainstDuplicate($organization->id, $projectId, $environmentId, $name);

        $tag = Tag::create([
            'name' => $name,
            'organization_id' => $organization->id,
            'project_id' => $projectId,
            'environment_id' => $environmentId,
        ]);

        $this->audit->record(
            'tag.created',
            subject: $tag,
            properties: ['name' => $name, 'project_id' => $projectId, 'environment_id' => $environmentId],
            scope: $scope,
            causer: $author,
        );

        return $tag;
    }

    /**
     * Names are compared in PHP: spatie stores them as translated JSON, which
     * no database compares the way a person would.
     */
    private function guardAgainstDuplicate(int $organizationId, ?int $projectId, ?int $environmentId, string $name): void
    {
        $taken = Tag::inScope($organizationId, $projectId, $environmentId)
            ->contains(fn (Tag $tag) => Str::lower((string) $tag->name) === Str::lower($name));

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "A tag called \"{$name}\" already exists in this scope.",
            ]);
        }
    }
}
