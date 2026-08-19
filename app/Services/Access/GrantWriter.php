<?php

namespace App\Services\Access;

use App\Enums\GrantScope;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place that turns grant specs from the wire into rows - invitations
 * and member editing both come through here, so the validation of what a
 * grant may look like exists exactly once.
 */
final class GrantWriter
{
    /**
     * Validate and canonicalize raw specs against one organization.
     *
     * Refused: unknown permissions, ids that do not belong to the
     * organization, a project/environment pair that disagrees, and a
     * permission placed at a scope it cannot ride on (GrantScope::coveredBy).
     * Environment specs get their project filled in, so every row carries the
     * full containment path.
     *
     * @param  array<int, array<string, mixed>>  $specs
     * @return list<array{permission: Permission, project_id: ?int, environment_id: ?int}>
     */
    public function normalize(Organization $organization, array $specs): array
    {
        $projects = $organization->projects()->pluck('id')->all();
        $environments = Environment::query()
            ->whereIn('project_id', $projects)
            ->pluck('project_id', 'id')
            ->all();

        $normalized = [];

        foreach ($specs as $index => $spec) {
            $permission = Permission::tryFrom((string) ($spec['permission'] ?? ''));

            if ($permission === null) {
                $this->refuse($index, 'That permission does not exist.');
            }

            $projectId = isset($spec['project_id']) ? (int) $spec['project_id'] : null;
            $environmentId = isset($spec['environment_id']) ? (int) $spec['environment_id'] : null;

            if ($environmentId !== null) {
                $owningProject = $environments[$environmentId] ?? null;

                if ($owningProject === null) {
                    $this->refuse($index, 'That environment does not belong to this organization.');
                }

                if ($projectId !== null && $projectId !== $owningProject) {
                    $this->refuse($index, 'That environment is not part of that project.');
                }

                $projectId = $owningProject;
            } elseif ($projectId !== null && ! in_array($projectId, $projects, true)) {
                $this->refuse($index, 'That project does not belong to this organization.');
            }

            $rowScope = match (true) {
                $environmentId !== null => GrantScope::Environment,
                $projectId !== null => GrantScope::Project,
                default => GrantScope::Organization,
            };

            if (! $permission->scope()->coveredBy($rowScope)) {
                $this->refuse($index, "\"{$permission->label()}\" cannot be scoped to a {$rowScope->value}.");
            }

            $normalized[$this->keyOf($permission, $projectId, $environmentId)] = [
                'permission' => $permission,
                'project_id' => $projectId,
                'environment_id' => $environmentId,
            ];
        }

        return array_values($normalized);
    }

    /**
     * Make $member's grants BE $specs - additions written, absences revoked,
     * in one transaction. With $scope, only rows inside that project are
     * replaced (and every spec must sit inside it); without, the whole
     * organization's set is.
     *
     * @param  array<int, array<string, mixed>>  $specs
     * @return array{0: int, 1: int} [granted, revoked]
     */
    public function replace(
        Organization $organization,
        User $member,
        array $specs,
        User $actor,
        ?Project $scope = null,
    ): array {
        $wanted = $this->normalize($organization, $specs);

        if ($scope !== null) {
            foreach ($wanted as $index => $row) {
                if ($row['project_id'] !== $scope->id) {
                    $this->refuse($index, 'Every grant here must sit inside this project.');
                }
            }
        }

        return DB::transaction(function () use ($organization, $member, $wanted, $actor, $scope): array {
            $existing = Grant::query()
                ->where('user_id', $member->id)
                ->where('organization_id', $organization->id)
                ->when($scope, fn ($query) => $query->where('project_id', $scope->id))
                ->get();

            $wantedKeys = array_map(
                fn (array $row) => $this->keyOf($row['permission'], $row['project_id'], $row['environment_id']),
                $wanted,
            );

            $revoked = 0;

            foreach ($existing as $grant) {
                $key = $this->keyOf($grant->permission, $grant->project_id, $grant->environment_id);

                if (! in_array($key, $wantedKeys, true)) {
                    $grant->delete();
                    $revoked++;
                }
            }

            $existingKeys = $existing
                ->map(fn (Grant $grant) => $this->keyOf($grant->permission, $grant->project_id, $grant->environment_id))
                ->all();

            $granted = 0;

            foreach ($wanted as $row) {
                if (in_array($this->keyOf($row['permission'], $row['project_id'], $row['environment_id']), $existingKeys, true)) {
                    continue;
                }

                Grant::create([
                    'user_id' => $member->id,
                    'organization_id' => $organization->id,
                    'project_id' => $row['project_id'],
                    'environment_id' => $row['environment_id'],
                    'permission' => $row['permission']->value,
                    'created_by' => $actor->id,
                ]);
                $granted++;
            }

            return [$granted, $revoked];
        });
    }

    /**
     * One org-scope row per permission - how an organization's creator starts.
     * Ordinary rows: visible, audited like any grant, and revocable later.
     */
    public function seedFullAccess(Organization $organization, User $creator): void
    {
        foreach (Permission::cases() as $permission) {
            Grant::firstOrCreate([
                'user_id' => $creator->id,
                'organization_id' => $organization->id,
                'project_id' => null,
                'environment_id' => null,
                'permission' => $permission->value,
            ], ['created_by' => $creator->id]);
        }
    }

    private function keyOf(Permission $permission, ?int $projectId, ?int $environmentId): string
    {
        return $permission->value.'|'.($projectId ?? 0).'|'.($environmentId ?? 0);
    }

    private function refuse(int|string $index, string $message): never
    {
        throw ValidationException::withMessages(["grants.{$index}" => $message]);
    }
}
