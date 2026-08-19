<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\AdoptUngroupedVariables;
use App\Actions\Projects\CreateGroup;
use App\Actions\Projects\DeleteGroup;
use App\Actions\Projects\UpdateGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\NamedResourceRequest;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class GroupController extends Controller
{
    public function store(
        NamedResourceRequest $request,
        Organization $organization,
        Project $project,
        CreateGroup $createGroup,
    ): RedirectResponse {
        $createGroup(
            $project,
            $request->user(),
            $request->string('name')->value(),
            $request->integer('position'),
        );

        return back()->with('success', 'Group created.');
    }

    public function update(
        NamedResourceRequest $request,
        Organization $organization,
        Project $project,
        Group $group,
        UpdateGroup $updateGroup,
    ): RedirectResponse {
        $updateGroup(
            $group,
            $request->user(),
            $request->string('name')->value(),
            $request->has('position') ? $request->integer('position') : null,
        );

        return back()->with('success', 'Group updated.');
    }

    public function destroy(
        Organization $organization,
        Project $project,
        Group $group,
        DeleteGroup $deleteGroup,
    ): RedirectResponse {
        $deleteGroup($group, request()->user());

        return back()->with('success', 'Group deleted.');
    }

    /**
     * Names the ungrouped bucket: moves every variable with no group into a
     * group (created or reused) with the given name.
     */
    public function adoptUngrouped(
        NamedResourceRequest $request,
        Organization $organization,
        Project $project,
        AdoptUngroupedVariables $adopt,
    ): RedirectResponse {
        $group = $adopt($project, $request->user(), $request->string('name')->value());

        return back()->with('success', "Ungrouped variables moved into {$group->name}.");
    }
}
