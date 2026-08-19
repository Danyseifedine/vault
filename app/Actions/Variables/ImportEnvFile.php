<?php

namespace App\Actions\Variables;

use App\Actions\Projects\CreateGroup;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Group;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Env\EnvClassifier;
use App\Services\Env\EnvParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a pasted .env into real variables and values.
 *
 * Keys the project already defines are updated (keeping their labels and
 * history); new ones are created with a guessed sensitivity AND a guessed
 * group, both from EnvClassifier, that a human can correct afterwards. Grouping
 * new variables needs groups.manage: without it the import still runs, it just
 * files the newcomers under whatever groups already exist and leaves the rest
 * ungrouped rather than refusing the whole thing.
 */
class ImportEnvFile
{
    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
        private EnvParser $parser,
        private EnvClassifier $classifier,
        private CreateVariable $createVariable,
        private CreateGroup $createGroup,
        private SetVariableValue $setValue,
    ) {}

    /**
     * @return array{created: int, updated: int}
     */
    public function __invoke(Environment $environment, User $user, string $contents): array
    {
        if (! $this->access->can($user, Permission::ImportEnv, $environment)) {
            $this->audit->failure(
                'import.denied',
                properties: ['environment' => $environment->slug],
                scope: AuditScope::make($environment->project->organization_id, $environment->project_id),
                causer: $user,
            );

            throw new AuthorizationException('You do not have permission to import into this environment.');
        }

        $parsed = $this->parser->parse($contents);
        $project = $environment->project;

        // One group guess per key, up front - so a run of SHOPIFY_* keys can be
        // clustered together rather than decided one at a time.
        $groupGuess = $this->classifier->groups(array_keys($parsed));
        $mayGroup = $this->access->can($user, Permission::ManageGroups, $project);

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($parsed, $groupGuess, $mayGroup, $project, $environment, $user, &$created, &$updated): void {
            // Resolved group ids, cached so a group is looked up or created once
            // per import no matter how many variables land in it.
            $groupIds = [];

            foreach ($parsed as $key => $value) {
                $variable = $project->variables()->where('key', $key)->first();

                if ($variable === null) {
                    $variable = ($this->createVariable)(
                        $project,
                        $user,
                        $key,
                        groupId: $this->resolveGroupId(
                            $project,
                            $user,
                            $groupGuess[$key] ?? null,
                            $mayGroup,
                            $groupIds,
                        ),
                        sensitivity: $this->classifier->sensitivity($key, $value),
                    );
                    $created++;
                } else {
                    $updated++;
                }

                ($this->setValue)($variable, $environment, $user, $value);
            }
        });

        $this->audit->record(
            'import.completed',
            properties: [
                'environment' => $environment->slug,
                'created' => $created,
                'updated' => $updated,
            ],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $user,
        );

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * A guessed group name to a real group id, reused across the import.
     *
     * An existing group (matched case-insensitively) is filed into without any
     * permission - that is just picking a folder. A group that does not exist
     * yet is only created when the importer holds groups.manage; otherwise the
     * variable stays ungrouped rather than the import failing. Names are never
     * refused as duplicates here: two variables guessed into "Database" share
     * the one group.
     *
     * @param  array<string, int|null>  $cache  keyed by lowercased group name
     */
    private function resolveGroupId(
        Project $project,
        User $user,
        ?string $name,
        bool $mayGroup,
        array &$cache,
    ): ?int {
        if ($name === null) {
            return null;
        }

        $slug = mb_strtolower($name);

        if (array_key_exists($slug, $cache)) {
            return $cache[$slug];
        }

        $existing = Group::query()
            ->where('project_id', $project->id)
            ->whereRaw('lower(name) = ?', [$slug])
            ->first();

        if ($existing !== null) {
            return $cache[$slug] = $existing->id;
        }

        if (! $mayGroup) {
            return $cache[$slug] = null;
        }

        return $cache[$slug] = ($this->createGroup)($project, $user, $name)->id;
    }
}
