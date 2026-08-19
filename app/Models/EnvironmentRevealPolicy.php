<?php

namespace App\Models;

use App\Enums\RevealRequirement;
use App\Enums\Sensitivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Sensitivity $sensitivity
 * @property RevealRequirement $requirement
 */
class EnvironmentRevealPolicy extends Model
{
    protected $fillable = ['environment_id', 'sensitivity', 'requirement'];

    protected function casts(): array
    {
        return [
            'sensitivity' => Sensitivity::class,
            'requirement' => RevealRequirement::class,
        ];
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
