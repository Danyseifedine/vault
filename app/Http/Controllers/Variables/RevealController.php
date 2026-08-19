<?php

namespace App\Http\Controllers\Variables;

use App\Http\Controllers\Concerns\ScopesToProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Variables\RevealVariableRequest;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Variable;
use App\Services\Reveal\RevealGuard;
use App\Services\Reveal\RevealOutcome;
use Illuminate\Http\JsonResponse;

/**
 * The one endpoint that returns a secret.
 *
 * It answers JSON rather than an Inertia page on purpose: a revealed value is
 * shown once, in place, and must never end up in a page prop, the history
 * stack, or a partial reload. All of the deciding happens in RevealGuard -
 * this controller only translates its outcome into a status code.
 */
class RevealController extends Controller
{
    use ScopesToProject;

    private const STATUS = [
        RevealOutcome::DENIED => 403,
        RevealOutcome::LOCKED => 429,
        RevealOutcome::PIN_REQUIRED => 422,
        RevealOutcome::PIN_INCORRECT => 422,
        RevealOutcome::PASSWORD_REQUIRED => 422,
        RevealOutcome::PASSWORD_INCORRECT => 422,
    ];

    public function store(
        RevealVariableRequest $request,
        Organization $organization,
        Project $project,
        Variable $variable,
        Environment $environment,
        RevealGuard $guard,
    ): JsonResponse {
        $this->assertContained($organization, $project, $variable, $environment);

        $outcome = $guard->reveal(
            $request->user(),
            $variable,
            $environment,
            $request->input('pin'),
            $request->input('password'),
        );

        if ($outcome->granted) {
            return response()->json([
                'key' => $variable->key,
                'environment' => $environment->slug,
                'value' => $outcome->value,
            ]);
        }

        return response()->json([
            'reason' => $outcome->reason,
            'attempts_remaining' => $outcome->attemptsRemaining,
            'locked_until' => $outcome->lockedUntil?->toIso8601String(),
        ], self::STATUS[$outcome->reason] ?? 422);
    }
}
