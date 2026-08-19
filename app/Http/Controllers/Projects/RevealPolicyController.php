<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\UpdateRevealPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateRevealPolicyRequest;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

/**
 * The environment × sensitivity matrix - what a reveal costs here.
 */
class RevealPolicyController extends Controller
{
    public function update(
        UpdateRevealPolicyRequest $request,
        Organization $organization,
        Project $project,
        Environment $environment,
        UpdateRevealPolicy $updatePolicy,
    ): RedirectResponse {
        $updatePolicy(
            $environment,
            $request->user(),
            $request->sensitivity(),
            $request->requirement(),
        );

        return back()->with('success', 'Reveal policy updated.');
    }
}
