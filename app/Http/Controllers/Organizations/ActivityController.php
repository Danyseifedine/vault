<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Audit\WipeActivityLog;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The organization's activity log, as a thing that can be emptied.
 *
 * No authorize() call here on purpose, matching how the shared vault and
 * organization creation do it: WipeActivityLog checks the permission itself
 * and records the refusal on the way out. A second gate here would answer 403
 * without leaving a trace of who tried to destroy an audit log, which is
 * precisely the attempt worth keeping.
 */
class ActivityController extends Controller
{
    public function destroy(
        Request $request,
        Organization $organization,
        WipeActivityLog $wipe,
    ): RedirectResponse {
        return $this->done($wipe($organization, $request->user()));
    }

    /** The same power, aimed at one project instead of the whole organization. */
    public function destroyForProject(
        Request $request,
        Organization $organization,
        Project $project,
        WipeActivityLog $wipe,
    ): RedirectResponse {
        return $this->done($wipe($organization, $request->user(), $project));
    }

    private function done(int $removed): RedirectResponse
    {
        return back()->with(
            'success',
            $removed === 0
                ? 'Nothing to remove - the wipe was recorded anyway.'
                : "{$removed} entries removed. The wipe itself is now on the record.",
        );
    }
}
