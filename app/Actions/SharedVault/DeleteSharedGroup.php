<?php

namespace App\Actions\SharedVault;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\SharedGroup;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Shared\SharedVaultGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Removes a shared group.
 *
 * A group only organizes things; it does not hold them. Deleting one must
 * never take a credential with it, so everything filed inside becomes
 * ungrouped and stays exactly where it was - the same promise the personal
 * vault makes in DeletePersonalItem::group.
 */
class DeleteSharedGroup
{
    public function __construct(
        private AuditRecorder $audit,
        private SharedVaultGuard $guard,
    ) {}

    public function __invoke(SharedGroup $group, Organization $organization, User $actor): void
    {
        // An id in a URL is not proof of belonging - checked before anything
        // else, so a foreign group is refused rather than authorized against
        // the wrong organization.
        if ($group->organization_id !== $organization->id) {
            throw new AuthorizationException('That group does not belong to this organization.');
        }

        $this->guard->authorize(
            $actor,
            $organization,
            Permission::ManageSharedVault,
            'shared.change-denied',
            ['name' => $group->name],
        );

        DB::transaction(function () use ($group, $organization, $actor): void {
            $group->secrets()->update(['shared_group_id' => null]);

            $this->audit->record(
                'shared-group.deleted',
                properties: ['name' => $group->name],
                scope: AuditScope::make($organization->id),
                causer: $actor,
            );

            $group->delete();
        });
    }
}
