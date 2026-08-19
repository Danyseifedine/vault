<?php

namespace App\Models;

use App\Enums\ChangeSafety;
use App\Enums\Sensitivity;
use Database\Factories\VariableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Tags\HasTags;

/**
 * The definition of a variable - its key, meaning and labels. The values live
 * in VariableValue, one per environment.
 *
 * @property string $key
 * @property Sensitivity $sensitivity
 * @property ChangeSafety $change_safety
 */
class Variable extends Model
{
    /** @use HasFactory<VariableFactory> */
    use HasFactory, HasTags, SoftDeletes;

    protected $fillable = [
        'project_id', 'group_id', 'key', 'description',
        'sensitivity', 'change_safety', 'created_by',
    ];

    /**
     * `live_key` is a database-only generated column (MySQL/MariaDB) backing the
     * unique-among-live-rows index; it is never read by the app and never
     * serialized. Absent on SQLite, where hiding it is simply a no-op.
     *
     * @var list<string>
     */
    protected $hidden = ['live_key'];

    protected function casts(): array
    {
        return [
            'sensitivity' => Sensitivity::class,
            'change_safety' => ChangeSafety::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return HasMany<VariableValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(VariableValue::class);
    }

    public function valueIn(Environment $environment): ?VariableValue
    {
        return $this->values()->where('environment_id', $environment->id)->first();
    }

    public function isCritical(): bool
    {
        return $this->sensitivity === Sensitivity::Critical;
    }
}
