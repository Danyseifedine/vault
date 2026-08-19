<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use App\Services\Access\TagAuthority;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Renames a tag. Its scope is deliberately NOT editable: widening one later
 * would retroactively legitimise attachments that were refused at the time.
 */
class UpdateTag
{
    public function __construct(
        private TagAuthority $authority,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Tag $tag, User $actor, string $name): Tag
    {
        $name = trim($name);
        $organization = $this->authority->authorize($tag, $actor, 'tag.update-denied', ['name' => $name]);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A tag needs a name.']);
        }

        $taken = Tag::inScope($tag->organization_id ?? 0, $tag->project_id, $tag->environment_id)
            ->contains(fn (Tag $other) => $other->id !== $tag->id
                && Str::lower((string) $other->name) === Str::lower($name));

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "A tag called \"{$name}\" already exists in this scope.",
            ]);
        }

        $previous = (string) $tag->name;
        $tag->name = $name;
        $tag->save();

        $this->audit->record(
            'tag.updated',
            subject: $tag,
            properties: ['from' => $previous, 'to' => $name],
            scope: AuditScope::make($organization->id, $tag->project_id),
            causer: $actor,
        );

        return $tag;
    }
}
