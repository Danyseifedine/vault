<?php

namespace App\Services\Access;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may change an existing tag.
 *
 * The answer depends on its reach, exactly as it did when the tag was created:
 * an organization-wide label takes `tags.create-global` on the organization, a
 * project or environment one takes `tags.create` covering that project.
 */
final class TagAuthority
{
    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return Organization The tag's organization, since every caller needs it next.
     *
     * @throws AuthorizationException
     */
    public function authorize(Tag $tag, User $actor, string $deniedEvent, array $properties = []): Organization
    {
        $organization = Organization::query()->findOrFail($tag->organization_id);

        $allowed = $tag->isGlobal()
            ? $this->access->can($actor, Permission::CreateGlobalTags, $organization)
            : $this->access->can(
                $actor,
                Permission::CreateTags,
                Project::query()->findOrFail($tag->project_id),
            );

        if ($allowed) {
            return $organization;
        }

        $this->audit->failure(
            $deniedEvent,
            subject: $tag,
            properties: $properties + ['tag' => (string) $tag->name],
            scope: AuditScope::make($organization->id, $tag->project_id),
            causer: $actor,
        );

        throw new AuthorizationException($tag->isGlobal()
            ? 'Changing organization-wide tags takes the tags.create-global permission.'
            : 'You cannot change tags in this project.');
    }
}
