<?php

namespace App\Actions\SharedVault;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\SharedSecret;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Shared\SharedVaultGuard;

/**
 * Removes a shared item.
 *
 * Soft delete, deliberately: a team credential deleted by mistake is somebody
 * locked out of a server. The encrypted file stays on disk for the same
 * reason - a soft delete that destroys the bytes is not soft at all.
 */
class DeleteSharedSecret
{
    public function __construct(
        private AuditRecorder $audit,
        private SharedVaultGuard $guard,
    ) {}

    public function __invoke(SharedSecret $secret, Organization $organization, User $actor): void
    {
        $this->guard->requireOwnItem($secret, $organization);

        $this->guard->authorize(
            $actor,
            $organization,
            Permission::ManageSharedVault,
            'shared.change-denied',
            ['name' => $secret->name],
        );

        $this->audit->record(
            'shared.deleted',
            subject: $secret,
            properties: ['name' => $secret->name, 'type' => $secret->type->value],
            scope: AuditScope::make($organization->id),
            causer: $actor,
        );

        $secret->delete();
    }
}
