<?php

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\RollbackVariableValue;
use App\Actions\Variables\SetVariableValue;
use App\Http\Controllers\Concerns\ScopesToProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Variables\SetVariableValueRequest;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Variable;
use App\Models\VariableValueVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * A variable's value in ONE environment. Everything here is governed by that
 * environment's access level - writing to dev says nothing about prod.
 */
class VariableValueController extends Controller
{
    use ScopesToProject;

    public function update(
        SetVariableValueRequest $request,
        Organization $organization,
        Project $project,
        Variable $variable,
        Environment $environment,
        SetVariableValue $setValue,
    ): RedirectResponse {
        $this->assertContained($organization, $project, $variable, $environment);
        $this->authorize('setValue', [$variable, $environment]);

        $setValue($variable, $environment, $request->user(), $request->string('value')->value());

        return back()->with('success', "{$variable->key} updated in {$environment->name}.");
    }

    /**
     * The change history for one value.
     *
     * Metadata only - who changed it and when, never what to. Seeing that a
     * value changed is not the same authority as seeing the value, so this
     * needs only the right to roll back, not to reveal.
     */
    public function history(
        Organization $organization,
        Project $project,
        Variable $variable,
        Environment $environment,
    ): JsonResponse {
        $this->assertContained($organization, $project, $variable, $environment);
        $this->authorize('rollback', [$variable, $environment]);

        $current = $variable->valueIn($environment);

        return response()->json([
            'versions' => $current === null ? [] : $current->versions()
                ->with('changedBy')
                ->latest('version')
                ->limit(20)
                ->get()
                ->map(fn (VariableValueVersion $version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'by' => $version->changedBy?->displayName() ?? 'system',
                    'at' => $version->created_at?->diffForHumans(),
                    'current' => $version->version === $current->version,
                ])
                ->all(),
        ]);
    }

    public function rollback(
        Organization $organization,
        Project $project,
        Variable $variable,
        Environment $environment,
        VariableValueVersion $version,
        RollbackVariableValue $rollback,
    ): RedirectResponse {
        $this->assertContained($organization, $project, $variable, $environment);
        $this->authorize('rollback', [$variable, $environment]);

        $current = $variable->valueIn($environment);

        if ($current === null) {
            throw ValidationException::withMessages([
                'version' => "{$variable->key} has no value in {$environment->name} to roll back.",
            ]);
        }

        $rollback($current, $version, request()->user());

        return back()->with('success', "{$variable->key} rolled back in {$environment->name}.");
    }
}
