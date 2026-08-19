<?php

namespace App\Models;

use App\Enums\SecretItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A credential that belongs to one person.
 *
 * The `owned` scope is not a convenience - it is the security boundary.
 * Every query for these must go through it.
 *
 * @property SecretItemType $type
 * @property string|null $value
 */
class PersonalSecret extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'personal_group_id', 'type', 'name', 'value', 'description'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'type' => SecretItemType::class,
            'value' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PersonalGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PersonalGroup::class, 'personal_group_id');
    }

    /**
     * The only sanctioned way to query personal items.
     *
     * @param  Builder<PersonalSecret>  $query
     * @return Builder<PersonalSecret>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function isFile(): bool
    {
        return $this->type === SecretItemType::File;
    }

    /** Where this item's encrypted file lives, if it is a file item. */
    public function storagePath(): string
    {
        return "personal/{$this->user_id}/{$this->id}.enc";
    }
}
