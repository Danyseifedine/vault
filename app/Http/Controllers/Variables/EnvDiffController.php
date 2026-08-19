<?php

namespace App\Http\Controllers\Variables;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Env\DiffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Compares two environments key by key.
 *
 * The response says a key is missing, differs, or is in sync - never what
 * either value is. That is what makes a diff safe to show to someone who
 * cannot reveal: knowing prod and staging disagree is operationally useful and
 * discloses nothing.
 *
 * It still takes view access to BOTH sides: "differs" is information about
 * prod, so it may not be learned from staging alone.
 */
class EnvDiffController extends Controller
{
    public function __invoke(
        Request $request,
        Organization $organization,
        Project $project,
        AccessResolver $access,
        DiffService $diff,
        AuditRecorder $audit,
    ): JsonResponse {
        $left = $this->environment($project, $request->string('left')->value());
        $right = $this->environment($project, $request->string('right')->value());

        $user = $request->user();

        foreach ([$left, $right] as $environment) {
            abort_unless($access->can($user, Permission::ViewVariables, $environment), 403);
        }

        $rows = $diff->compare($left, $right);

        $audit->record(
            'diff.viewed',
            properties: ['left' => $left->slug, 'right' => $right->slug, 'keys' => count($rows)],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $user,
        );

        return response()->json([
            'left' => $left->slug,
            'right' => $right->slug,
            'rows' => $rows,
        ]);
    }

    private function environment(Project $project, string $slug): Environment
    {
        if ($slug === '') {
            throw ValidationException::withMessages([
                'left' => 'Choose two environments to compare.',
            ]);
        }

        return $project->environments()->where('slug', $slug)->firstOrFail();
    }
}
