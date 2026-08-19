<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot of a previous value. Insert-only: rolling back writes
 * a NEW version rather than resurrecting an old row, so history stays honest.
 *
 * @property int $version
 * @property string $value
 */
class VariableValueVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['variable_value_id', 'version', 'value', 'changed_by'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<VariableValue, $this> */
    public function variableValue(): BelongsTo
    {
        return $this->belongsTo(VariableValue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
