<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use App\Services\Access\TagAuthority;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;

/**
 * Removes a label entirely.
 *
 * Everything wearing it loses it - a tag left attached to variables after being
 * deleted would keep filtering results that no longer have a name for why.
 */
class DeleteTag
{
    public function __construct(
        private TagAuthority $authority,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Tag $tag, User $actor): void
    {
        $organization = $this->authority->authorize($tag, $actor, 'tag.delete-denied');

        DB::transaction(function () use ($tag, $organization, $actor): void {
            $attached = DB::table('taggables')->where('tag_id', $tag->id)->count();

            DB::table('taggables')->where('tag_id', $tag->id)->delete();

            $this->audit->record(
                'tag.deleted',
                properties: ['tag' => (string) $tag->name, 'detached_from' => $attached],
                scope: AuditScope::make($organization->id, $tag->project_id),
                causer: $actor,
            );

            $tag->delete();
        });
    }
}
