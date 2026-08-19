<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Tags\AttachTags;
use App\Actions\Tags\CreateTag;
use App\Actions\Tags\DeleteTag;
use App\Actions\Tags\UpdateTag;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tags\StoreTagRequest;
use App\Http\Requests\Variables\IdListRequest;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Variable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    /**
     * The scope is inferred from what was supplied: an environment id makes an
     * environment tag, a project id a project tag, neither an organization-wide
     * one - which takes the wider `tags.create-global` grant.
     */
    public function store(
        StoreTagRequest $request,
        Organization $organization,
        CreateTag $createTag,
    ): RedirectResponse {
        $name = $request->string('name')->value();
        $author = $request->user();

        if ($request->filled('environment_id')) {
            $environment = Environment::query()
                ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
                ->findOrFail($request->integer('environment_id'));

            $createTag->forEnvironment($environment, $author, $name);
        } elseif ($request->filled('project_id')) {
            $project = $organization->projects()->findOrFail($request->integer('project_id'));

            $createTag->forProject($project, $author, $name);
        } else {
            $createTag->global($organization, $author, $name);
        }

        return back()->with('success', "Tag “{$name}” created.");
    }

    public function update(
        StoreTagRequest $request,
        Organization $organization,
        Tag $tag,
        UpdateTag $updateTag,
    ): RedirectResponse {
        abort_unless($tag->organization_id === $organization->id, 404);

        $updateTag($tag, $request->user(), $request->string('name')->value());

        return back()->with('success', 'Tag renamed.');
    }

    public function destroy(
        Request $request,
        Organization $organization,
        Tag $tag,
        DeleteTag $deleteTag,
    ): RedirectResponse {
        abort_unless($tag->organization_id === $organization->id, 404);

        $deleteTag($tag, $request->user());

        return back()->with('success', 'Tag deleted.');
    }

    public function sync(
        IdListRequest $request,
        Organization $organization,
        Project $project,
        Variable $variable,
        AttachTags $attachTags,
    ): RedirectResponse {
        $attachTags($variable, $request->user(), $request->ids());

        return back()->with('success', 'Tags updated.');
    }
}
