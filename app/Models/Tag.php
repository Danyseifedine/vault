<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Tags\Tag as SpatieTag;

/**
 * A label with a reach.
 *
 * Three scopes, narrowing: organization-wide (project and environment null),
 * one project, or one environment inside a project. The scope is what stops
 * "prod-only" from being attached to something that has no prod value at all.
 *
 * @property int|null $organization_id
 * @property int|null $project_id
 * @property int|null $environment_id
 */
class Tag extends SpatieTag
{
    protected $fillable = ['name', 'slug', 'type', 'order_column', 'organization_id', 'project_id', 'environment_id'];

    public function isGlobal(): bool
    {
        return $this->project_id === null && $this->environment_id === null;
    }

    public function isEnvironmentScoped(): bool
    {
        return $this->environment_id !== null;
    }

    /**
     * Everything a variable in this project could legitimately wear: the
     * project's own tags, its environments' tags, and the organization's
     * global vocabulary.
     *
     * @return Collection<int, static>
     */
    public static function availableFor(Project $project): Collection
    {
        return static::query()
            ->where('organization_id', $project->organization_id)
            ->where(fn (Builder $query) => $query
                ->whereNull('project_id')
                ->orWhere('project_id', $project->id))
            ->get();
    }

    /**
     * Tags already defined in one scope, so a duplicate name can be caught
     * before it is created. Kept in PHP because the name column is translated
     * JSON, which no database compares comfortably.
     *
     * @return Collection<int, static>
     */
    public static function inScope(int $organizationId, ?int $projectId, ?int $environmentId): Collection
    {
        return static::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('environment_id', $environmentId)
            ->get();
    }
}
