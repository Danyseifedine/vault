<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-project security posture.
 *
 * Note what is NOT here: any switch for auditing changes. Create, update and
 * delete are always recorded - a vault that can forget who changed a secret
 * cannot answer the one question it exists to answer.
 *
 * @property bool $audit_views
 * @property int $pin_max_attempts
 * @property int $pin_lockout_minutes
 */
class ProjectSettings extends Model
{
    protected $table = 'project_settings';

    protected $fillable = ['project_id', 'audit_views', 'pin_max_attempts', 'pin_lockout_minutes'];

    protected function casts(): array
    {
        return [
            'audit_views' => 'boolean',
            'pin_max_attempts' => 'integer',
            'pin_lockout_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
