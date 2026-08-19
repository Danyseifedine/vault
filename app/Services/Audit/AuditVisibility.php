<?php

namespace App\Services\Audit;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose audit entries a viewer may read.
 *
 * The log names people: who revealed which key, who failed a PIN, who was
 * invited. That makes it the one feed where "you can see this project" is not
 * enough - reading another person's trail is its own permission,
 * `audit.view-all`, held org-wide. Everyone else reads their own trail and
 * nothing more.
 *
 * Every feed asks this class, so widening the audience is a change to one
 * file rather than four.
 */
final class AuditVisibility
{
    public function __construct(private AccessResolver $access) {}

    public function readsEveryone(User $viewer, Organization $organization): bool
    {
        return $this->access->can($viewer, Permission::ViewAllActivity, $organization);
    }

    /**
     * Narrow a query to the entries $viewer may read in $organization.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function apply(Builder $query, User $viewer, Organization $organization): Builder
    {
        if ($this->readsEveryone($viewer, $organization)) {
            return $query;
        }

        return $query
            ->where('causer_type', $viewer->getMorphClass())
            ->where('causer_id', $viewer->id);
    }
}
