<?php

namespace App\Services\Shared;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\SharedSecret;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The gate in front of the shared vault.
 *
 * Every refusal is recorded, because a denied attempt to read the production
 * database password is exactly the kind of thing someone should be able to
 * find afterwards. Containment is checked here too: an item's id in the URL
 * proves nothing about which organization it belongs to.
 */
final class SharedVaultGuard
{
    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
    ) {}

    public function canView(User $user, Organization $organization): bool
    {
        return $this->access->can($user, Permission::ViewSharedVault, $organization);
    }

    public function canReveal(User $user, Organization $organization): bool
    {
        return $this->access->can($user, Permission::RevealSharedVault, $organization);
    }

    public function canManage(User $user, Organization $organization): bool
    {
        return $this->access->can($user, Permission::ManageSharedVault, $organization);
    }

    /**
     * @param  array<string, mixed>  $properties
     *
     * @throws AuthorizationException
     */
    public function authorize(
        User $user,
        Organization $organization,
        Permission $permission,
        string $failureEvent,
        array $properties = [],
    ): void {
        if ($this->access->can($user, $permission, $organization)) {
            return;
        }

        $this->audit->failure(
            $failureEvent,
            properties: $properties + ['permission' => $permission->value],
            scope: AuditScope::make($organization->id),
            causer: $user,
        );

        throw new AuthorizationException('You do not have permission to do that in this vault.');
    }

    /** An id from a URL is not proof of belonging - this is. */
    public function requireOwnItem(SharedSecret $secret, Organization $organization): void
    {
        if ($secret->organization_id !== $organization->id) {
            throw new AuthorizationException('That item does not belong to this organization.');
        }
    }

    /** A group id from the wire must belong to the same organization. */
    public function requireOwnGroupId(?int $groupId, Organization $organization): void
    {
        if ($groupId === null) {
            return;
        }

        $belongs = $organization->sharedGroups()->whereKey($groupId)->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'shared_group_id' => 'That group does not belong to this organization.',
            ]);
        }
    }
}
