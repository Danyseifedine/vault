<?php

namespace App\Models;

use App\Enums\PinStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reveal PIN. Issued by a `pins.manage` holder, never chosen by the person
 * it belongs to - which is what makes blocking one an instant kill switch for
 * that person's ability to reveal, without touching their account.
 *
 * @property string $pin_hash
 * @property PinStatus $status
 */
class Pin extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'pin_hash', 'label', 'status', 'created_by'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'status' => PinStatus::class,
            'blocked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Pin>  $query
     * @return Builder<Pin>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PinStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === PinStatus::Active;
    }
}
