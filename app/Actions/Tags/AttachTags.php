<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use App\Models\Variable;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Sets the complete tag list on a variable.
 *
 * Every tag must reach this variable: a tag from another organization or
 * project is refused outright, and an environment tag only lands if the
 * variable actually has a value in that environment.
 */
class AttachTags
{
    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
    ) {}

    /** @param  array<int, int>  $tagIds  The full set - anything omitted is removed. */
    public function __invoke(Variable $variable, User $author, array $tagIds): void
    {
        $project = $variable->project;
        $organization = $project->organization;

        if (! $this->access->canTagVariables($author, $project)) {
            $this->audit->failure(
                'variable.tag-change-denied',
                subject: $variable,
                properties: ['key' => $variable->key],
                scope: AuditScope::make($organization->id, $project->id),
                causer: $author,
            );

            throw new AuthorizationException('You cannot change tags on this variable.');
        }

        $tags = Tag::query()->whereIn('id', array_unique($tagIds))->get();

        if ($tags->count() !== count(array_unique($tagIds))) {
            throw ValidationException::withMessages(['tags' => 'One of those tags no longer exists.']);
        }

        $tags->each(fn (Tag $tag) => $this->guardReach($tag, $variable));

        $variable->syncTags($tags->all());

        $this->audit->record(
            'variable.tags-changed',
            subject: $variable,
            properties: ['key' => $variable->key, 'tags' => $tags->map(fn (Tag $tag) => (string) $tag->name)->all()],
            scope: AuditScope::make($organization->id, $project->id),
            causer: $author,
        );
    }

    private function guardReach(Tag $tag, Variable $variable): void
    {
        $project = $variable->project;

        if ($tag->organization_id !== $project->organization_id) {
            throw ValidationException::withMessages([
                'tags' => "\"{$tag->name}\" belongs to another organization.",
            ]);
        }

        if ($tag->project_id !== null && $tag->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'tags' => "\"{$tag->name}\" belongs to another project.",
            ]);
        }

        if ($tag->environment_id === null) {
            return;
        }

        $existsThere = $variable->values()->where('environment_id', $tag->environment_id)->exists();

        if (! $existsThere) {
            throw ValidationException::withMessages([
                'tags' => "\"{$tag->name}\" only applies to variables that exist in that environment.",
            ]);
        }
    }
}
