<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalGroup extends Model
{
    protected $fillable = ['user_id', 'name', 'position'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PersonalSecret, $this> */
    public function secrets(): HasMany
    {
        return $this->hasMany(PersonalSecret::class);
    }

    /**
     * @param  Builder<PersonalGroup>  $query
     * @return Builder<PersonalGroup>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
