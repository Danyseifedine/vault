<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One secret value, in one environment.
 *
 * `value` is hidden from serialization AND encrypted at rest. Nothing outside
 * App\Services\Reveal\RevealGuard should ever read it.
 *
 * @property string $value
 * @property int $version
 */
class VariableValue extends Model
{
    protected $fillable = ['variable_id', 'environment_id', 'value', 'version', 'updated_by'];

    /** Belt and braces: even an accidental ->toArray() cannot leak the value. */
    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Variable, $this> */
    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return HasMany<VariableValueVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(VariableValueVersion::class)->orderByDesc('version');
    }
}
