<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateEnvironment;
use App\Actions\Projects\DeleteEnvironment;
use App\Actions\Projects\UpdateEnvironment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\NamedResourceRequest;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class EnvironmentController extends Controller
{
    public function store(
        NamedResourceRequest $request,
        Organization $organization,
        Project $project,
        CreateEnvironment $createEnvironment,
    ): RedirectResponse {
        $this->authorize('manageEnvironments', $project);

        $environment = $createEnvironment(
            $project,
            $request->user(),
            $request->string('name')->value(),
            $request->integer('position'),
        );

        return back()->with('success', "Environment “{$environment->name}” created.");
    }

    public function update(
        NamedResourceRequest $request,
        Organization $organization,
        Project $project,
        Environment $environment,
        UpdateEnvironment $updateEnvironment,
    ): RedirectResponse {
        $updateEnvironment(
            $environment,
            $request->user(),
            $request->string('name')->value(),
            $request->has('position') ? $request->integer('position') : null,
        );

        return back()->with('success', 'Environment updated.');
    }

    public function destroy(
        Organization $organization,
        Project $project,
        Environment $environment,
        DeleteEnvironment $deleteEnvironment,
    ): RedirectResponse {
        $deleteEnvironment($environment, request()->user());

        return back()->with('success', "Environment “{$environment->name}” deleted.");
    }
}
