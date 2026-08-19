<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Organizes shared items by what they belong to: "staging server", "Apple". */
class SharedGroup extends Model
{
    protected $fillable = ['organization_id', 'name', 'position'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<SharedSecret, $this> */
    public function secrets(): HasMany
    {
        return $this->hasMany(SharedSecret::class);
    }
}
