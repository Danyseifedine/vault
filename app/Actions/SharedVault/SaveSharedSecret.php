<?php

namespace App\Actions\SharedVault;

use App\Enums\Permission;
use App\Enums\SecretItemType;
use App\Models\Organization;
use App\Models\SharedSecret;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Shared\SharedVaultGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Adds or edits an item in the organization's shared vault.
 *
 * One class for both kinds of item and both directions, because they differ
 * only in where the bytes end up: a secret's value rides an encrypted column,
 * a file's contents are encrypted BEFORE they touch the disk so a copied
 * storage folder is as useless as a database dump.
 *
 * No value ever reaches the audit entry - only its name and type.
 */
class SaveSharedSecret
{
    public function __construct(
        private AuditRecorder $audit,
        private SharedVaultGuard $guard,
    ) {}

    public function secret(
        Organization $organization,
        User $actor,
        string $name,
        #[\SensitiveParameter] string $value,
        ?int $groupId = null,
        ?string $description = null,
    ): SharedSecret {
        $this->authorize($organization, $actor, $name, $groupId);

        $secret = SharedSecret::create([
            'organization_id' => $organization->id,
            'shared_group_id' => $groupId,
            'type' => SecretItemType::Secret,
            'name' => $name,
            'value' => $value,
            'description' => $description,
            'created_by' => $actor->id,
        ]);

        $this->record('shared.created', $secret, $organization, $actor);

        return $secret;
    }

    public function file(
        Organization $organization,
        User $actor,
        UploadedFile $file,
        ?string $name = null,
        ?int $groupId = null,
        ?string $description = null,
    ): SharedSecret {
        $this->authorize($organization, $actor, $name ?: $file->getClientOriginalName(), $groupId);

        return DB::transaction(function () use ($organization, $actor, $file, $name, $groupId, $description): SharedSecret {
            $secret = SharedSecret::create([
                'organization_id' => $organization->id,
                'shared_group_id' => $groupId,
                'type' => SecretItemType::File,
                'name' => $name ?: $file->getClientOriginalName(),
                'value' => null,
                'description' => $description,
                'created_by' => $actor->id,
            ]);

            $contents = $file->get();

            if ($contents === false) {
                throw new \RuntimeException("Could not read the uploaded file {$file->getClientOriginalName()}.");
            }

            Storage::disk(config('filesystems.default', 'local'))->put(
                $secret->storagePath(),
                Crypt::encryptString($contents),
            );

            $this->record('shared.file-stored', $secret, $organization, $actor);

            return $secret;
        });
    }

    /**
     * Renames, re-files or re-describes an item. A null $value leaves the
     * stored one alone, so "rename this" never means "and blank the secret".
     */
    public function update(
        SharedSecret $secret,
        Organization $organization,
        User $actor,
        string $name,
        ?int $groupId = null,
        ?string $description = null,
        #[\SensitiveParameter] ?string $value = null,
    ): SharedSecret {
        $this->guard->requireOwnItem($secret, $organization);
        $this->authorize($organization, $actor, $name, $groupId);

        $secret->fill([
            'name' => $name,
            'shared_group_id' => $groupId,
            'description' => $description,
        ]);

        if ($value !== null && ! $secret->isFile()) {
            $secret->value = $value;
        }

        $secret->save();

        $this->record('shared.updated', $secret, $organization, $actor);

        return $secret;
    }

    private function authorize(Organization $organization, User $actor, string $name, ?int $groupId): void
    {
        $this->guard->authorize(
            $actor,
            $organization,
            Permission::ManageSharedVault,
            'shared.change-denied',
            ['name' => $name],
        );

        $this->guard->requireOwnGroupId($groupId, $organization);
    }

    private function record(string $event, SharedSecret $secret, Organization $organization, User $actor): void
    {
        $this->audit->record(
            $event,
            subject: $secret,
            properties: ['name' => $secret->name, 'type' => $secret->type->value],
            scope: AuditScope::make($organization->id),
            causer: $actor,
        );
    }
}
