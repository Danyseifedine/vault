<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Organizes variables by concern (database, redis, mail) so a project with
 * sixty keys reads like a table of contents instead of a wall of text.
 */
class Group extends Model
{
    use HasSlug;

    protected $fillable = ['project_id', 'name', 'slug', 'position'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->extraScope(fn ($query) => $query->where('project_id', $this->project_id));
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<Variable, $this> */
    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class);
    }
}
