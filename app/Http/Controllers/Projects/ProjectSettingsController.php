<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\UpdateProjectSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateProjectSettingsRequest;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class ProjectSettingsController extends Controller
{
    public function update(
        UpdateProjectSettingsRequest $request,
        Organization $organization,
        Project $project,
        UpdateProjectSettings $updateSettings,
    ): RedirectResponse {
        /** @var array{audit_views?: bool, pin_max_attempts?: int, pin_lockout_minutes?: int} $changes */
        $changes = $request->validated();

        $updateSettings($project, $request->user(), $changes);

        return back()->with('success', 'Settings updated.');
    }
}
