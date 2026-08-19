<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Invitations\SendInvitation;
use App\Actions\Members\SetMemberGrants;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\MemberGrantsRequest;
use App\Http\Requests\Members\SendInvitationRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Who is in the organization and exactly what each of them may do.
 *
 * Grant changes authorize internally through MemberChangeGuard, which is also
 * what records the refusals - so this controller stays a translation layer
 * and nothing more.
 */
class MemberController extends Controller
{
    public function invite(
        SendInvitationRequest $request,
        Organization $organization,
        SendInvitation $sendInvitation,
    ): RedirectResponse {
        $this->authorize('invite', $organization);

        $sendInvitation(
            $organization,
            $request->user(),
            $request->string('email')->value(),
            $request->grants(),
        );

        return back()->with('success', "Invitation sent to {$request->string('email')}.");
    }

    public function grants(
        MemberGrantsRequest $request,
        Organization $organization,
        User $member,
        SetMemberGrants $setGrants,
    ): RedirectResponse {
        $scope = $request->input('project_id') === null
            ? null
            : Project::query()
                ->where('organization_id', $organization->id)
                ->findOrFail($request->integer('project_id'));

        $setGrants($organization, $request->user(), $member, $request->grants(), $scope);

        return back()->with('success', "Access updated for {$member->email}.");
    }
}
