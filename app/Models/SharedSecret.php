<?php

namespace App\Models;

use App\Enums\SecretItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A credential the organization holds in common - a server password, an SSH
 * key, a certificate - rather than one belonging to a project's `.env` or to
 * one person.
 *
 * Unlike a personal item, other people read this one, so it answers to grants:
 * `shared.view` to know it exists, `shared.reveal` to read it, and a PIN every
 * time. `value` is encrypted at rest and hidden from serialization, because
 * the list payload must never carry it.
 *
 * @property SecretItemType $type
 * @property string|null $value
 */
class SharedSecret extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'shared_group_id', 'type', 'name', 'value', 'description', 'created_by',
    ];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'type' => SecretItemType::class,
            'value' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<SharedGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SharedGroup::class, 'shared_group_id');
    }

    public function isFile(): bool
    {
        return $this->type === SecretItemType::File;
    }

    /** Where this item's encrypted file lives, if it is a file item. */
    public function storagePath(): string
    {
        return "shared/{$this->organization_id}/{$this->id}.enc";
    }
}
