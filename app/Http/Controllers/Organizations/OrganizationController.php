<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Organizations\DeleteOrganization;
use App\Actions\Organizations\UpdateOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Services\Dashboard\OrganizationDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function show(Organization $organization, OrganizationDashboard $dashboard): Response
    {
        $this->authorize('view', $organization);

        return Inertia::render('organizations/show', [
            ...$dashboard->for($organization, request()->user(), request()->only(['page', 'perPage', 'filter'])),
            'screen' => $this->screen(['home', 'projects', 'members', 'tags', 'shared', 'activity'], 'home'),
        ]);
    }

    /**
     * Starting an organization is an account capability, not a grant: there is
     * no organization yet to scope a grant row to.
     *
     * No authorize() call here on purpose - CreateOrganization checks the
     * capability itself and records the refusal on the way out, and a second
     * gate here would answer 403 without leaving a trace of who tried.
     */
    public function store(StoreOrganizationRequest $request, CreateOrganization $createOrganization): RedirectResponse
    {
        $organization = $createOrganization($request->user(), $request->string('name')->value());

        return to_route('organizations.show', $organization)
            ->with('success', "“{$organization->name}” created.");
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization,
        UpdateOrganization $updateOrganization,
    ): RedirectResponse {
        $updateOrganization($organization, $request->user(), $request->string('name')->value());

        return back()->with('success', 'Organization renamed.');
    }

    /**
     * Back to the dashboard rather than to the organization: the thing that was
     * being looked at no longer exists.
     */
    public function destroy(
        Request $request,
        Organization $organization,
        DeleteOrganization $deleteOrganization,
    ): RedirectResponse {
        $name = $organization->name;

        $deleteOrganization($organization, $request->user());

        return to_route('dashboard')->with('success', "“{$name}” and everything in it was deleted.");
    }
}
