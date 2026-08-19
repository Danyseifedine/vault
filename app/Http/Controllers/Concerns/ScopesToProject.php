<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Variable;

/**
 * Containment for the routes where a variable and an environment are siblings
 * rather than parent and child (values.update / history / rollback / reveal).
 *
 * Those routes use `withoutScopedBindings()`, so `{variable}` is resolved by id
 * and `{environment}` by slug with NO check that either belongs to the
 * `{project}` (or that the project belongs to the `{organization}`) in the URL.
 * Without this, a member who legitimately holds a grant on their OWN
 * environment could pass a foreign variable id and have authorization decided
 * against the wrong tenant - a cross-organization write, an audit row injected
 * into someone else's hash chain, and a variable left un-editable in its home
 * project. Authorization answers "may you act on this environment"; it does not
 * answer "do these URL segments even belong together". That is this method.
 *
 * 404, not 403: an out-of-scope id is indistinguishable from one that does not
 * exist, which is the same answer route-model binding gives everywhere else.
 */
trait ScopesToProject
{
    protected function assertContained(
        Organization $organization,
        Project $project,
        Variable $variable,
        Environment $environment,
    ): void {
        abort_unless(
            $project->organization_id === $organization->id
                && $variable->project_id === $project->id
                && $environment->project_id === $project->id,
            404,
        );
    }
}
