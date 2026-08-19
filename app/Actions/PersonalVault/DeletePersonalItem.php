<?php

namespace App\Actions\PersonalVault;

use App\Models\PersonalGroup;
use App\Models\PersonalSecret;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Personal\PersonalGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes something from a personal vault.
 *
 * Items are soft-deleted so a misclick is recoverable - except a file's
 * encrypted bytes, which are removed for real. Keeping ciphertext on disk for
 * something the owner asked to delete is not a promise this product should make.
 */
class DeletePersonalItem
{
    public function __construct(
        private PersonalGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(PersonalSecret $secret, User $owner): void
    {
        $this->guard->requireOwner($secret, $owner);

        if ($secret->isFile()) {
            Storage::disk(config('filesystems.default', 'local'))->delete($secret->storagePath());
        }

        $this->audit->record(
            'personal.deleted',
            subject: $secret,
            properties: ['name' => $secret->name, 'type' => $secret->type->value],
            scope: AuditScope::personal(),
            causer: $owner,
        );

        $secret->delete();
    }

    /** Removing a folder must never remove what was filed in it. */
    public function group(PersonalGroup $group, User $owner): void
    {
        $this->guard->requireOwnerOfGroup($group, $owner);

        DB::transaction(function () use ($group, $owner): void {
            $group->secrets()->update(['personal_group_id' => null]);

            $this->audit->record(
                'personal.group-deleted',
                properties: ['name' => $group->name],
                scope: AuditScope::personal(),
                causer: $owner,
            );

            $group->delete();
        });
    }
}
