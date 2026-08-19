<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Dashboard\ProjectDashboard;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function show(Organization $organization, Project $project, ProjectDashboard $dashboard): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/show', [
            ...$dashboard->for($project, request()->user(), request()->only(['page', 'perPage', 'filter'])),
            'screen' => $this->screen(
                ['overview', 'vars', 'diff', 'audit', 'members', 'import', 'settings'],
                'overview',
            ),
        ]);
    }

    public function store(
        StoreProjectRequest $request,
        Organization $organization,
        CreateProject $createProject,
    ): RedirectResponse {
        $this->authorize('createProject', $organization);

        $project = $createProject(
            $organization,
            $request->user(),
            $request->string('name')->value(),
            $request->input('description'),
            $request->environments(),
        );

        return to_route('projects.show', [$organization, $project])
            ->with('success', "Project “{$project->name}” created.");
    }

    public function update(
        UpdateProjectRequest $request,
        Organization $organization,
        Project $project,
        UpdateProject $updateProject,
    ): RedirectResponse {
        $updateProject(
            $project,
            $request->user(),
            $request->string('name')->value(),
            $request->input('description'),
        );

        return back()->with('success', 'Project updated.');
    }

    public function destroy(
        Organization $organization,
        Project $project,
        DeleteProject $deleteProject,
    ): RedirectResponse {
        $deleteProject($project, request()->user());

        return to_route('organizations.show', $organization)
            ->with('success', "Project “{$project->name}” deleted.");
    }
}
