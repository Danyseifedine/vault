<?php

namespace App\Services\Env;

use App\Models\Environment;
use App\Models\Project;

/**
 * Answers "which keys does this environment not have yet?".
 *
 * Because a variable is defined once per project and valued per environment,
 * a missing value row simply IS the gap - no bookkeeping required.
 */
class CompletenessService
{
    /** @return array<int, string> Keys defined in the project but absent here. */
    public function missingKeys(Environment $environment): array
    {
        return $environment->project
            ->variables()
            ->whereDoesntHave('values', fn ($query) => $query->where('environment_id', $environment->id))
            ->orderBy('key')
            ->pluck('key')
            ->all();
    }

    public function missingCount(Environment $environment): int
    {
        return count($this->missingKeys($environment));
    }

    public function isComplete(Environment $environment): bool
    {
        return $this->missingCount($environment) === 0;
    }

    /**
     * How many variables in a project are incomplete somewhere.
     *
     * @return array<string, int> environment slug => missing count
     */
    public function projectSummary(Project $project): array
    {
        $summary = [];

        foreach ($project->environments as $environment) {
            $summary[$environment->slug] = $this->missingCount($environment);
        }

        return $summary;
    }
}
