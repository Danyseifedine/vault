<?php

namespace App\Models;

use App\Enums\RevealRequirement;
use App\Enums\Sensitivity;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $slug
 */
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory, HasSlug;

    protected $fillable = ['project_id', 'name', 'slug', 'position'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getSlugOptions(): SlugOptions
    {
        // Every project has its own "prod"; uniqueness is per project.
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

    /** @return HasMany<EnvironmentRevealPolicy, $this> */
    public function revealPolicies(): HasMany
    {
        return $this->hasMany(EnvironmentRevealPolicy::class);
    }

    /** @return HasMany<VariableValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(VariableValue::class);
    }

    /** What a reveal of this sensitivity costs here. */
    public function requirementFor(Sensitivity $sensitivity): RevealRequirement
    {
        $policy = $this->revealPolicies->firstWhere('sensitivity', $sensitivity);

        // A missing row must never mean "free" - fall back to the safe default.
        if ($policy === null) {
            return $sensitivity->defaultRequirement();
        }

        return $policy->requirement;
    }
}
