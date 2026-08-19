<?php

namespace App\Models;

use App\Enums\GrantScope;
use App\Enums\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One permission held by one user over one scope: the whole organization
 * (both scope ids null), one project (project set), or one environment (both
 * set - environment rows always carry their project_id too, so containment
 * queries never need a join).
 *
 * @property int $id
 * @property int $user_id
 * @property int $organization_id
 * @property int|null $project_id
 * @property int|null $environment_id
 * @property Permission $permission
 * @property int|null $created_by
 */
class Grant extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'project_id',
        'environment_id',
        'permission',
        'created_by',
    ];

    /**
     * `project_scope` and `environment_scope` are database-only generated
     * columns (MySQL/MariaDB) backing the scope-uniqueness index; the app never
     * reads them and they never serialize. Absent on SQLite, where hiding them
     * is a no-op.
     *
     * @var list<string>
     */
    protected $hidden = ['project_scope', 'environment_scope'];

    protected function casts(): array
    {
        return ['permission' => Permission::class];
    }

    public function scopeLevel(): GrantScope
    {
        if ($this->environment_id !== null) {
            return GrantScope::Environment;
        }

        return $this->project_id !== null ? GrantScope::Project : GrantScope::Organization;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
