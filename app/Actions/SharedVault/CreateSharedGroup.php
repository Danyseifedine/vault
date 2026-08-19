<?php

namespace App\Actions\SharedVault;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\SharedGroup;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Shared\SharedVaultGuard;
use Illuminate\Validation\ValidationException;

/** Groups shared items by what they belong to: "staging server", "Apple". */
class CreateSharedGroup
{
    public function __construct(
        private AuditRecorder $audit,
        private SharedVaultGuard $guard,
    ) {}

    public function __invoke(Organization $organization, User $actor, string $name): SharedGroup
    {
        $name = trim($name);

        $this->guard->authorize(
            $actor,
            $organization,
            Permission::ManageSharedVault,
            'shared.change-denied',
            ['name' => $name],
        );

        $taken = $organization->sharedGroups()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => 'A group by that name already exists here.',
            ]);
        }

        $group = $organization->sharedGroups()->create(['name' => $name]);

        $this->audit->record(
            'shared-group.created',
            subject: $group,
            properties: ['name' => $name],
            scope: AuditScope::make($organization->id),
            causer: $actor,
        );

        return $group;
    }
}
