<?php

namespace App\Actions\PersonalVault;

use App\Enums\SecretItemType;
use App\Models\PersonalSecret;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Personal\PersonalGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Stores an uploaded file in the personal vault.
 *
 * The contents are encrypted BEFORE they reach the disk, so a copied storage
 * folder is as useless as a database dump. Anything less would make files the
 * one unprotected corner of a secrets manager.
 */
class StorePersonalFile
{
    public function __construct(
        private AuditRecorder $audit,
        private PersonalGuard $guard,
    ) {}

    public function __invoke(
        User $owner,
        UploadedFile $file,
        ?string $name = null,
        ?int $groupId = null,
        ?string $description = null,
    ): PersonalSecret {
        $this->guard->requireOwnGroupId($groupId, $owner);

        return DB::transaction(function () use ($owner, $file, $name, $groupId, $description): PersonalSecret {
            $secret = PersonalSecret::create([
                'user_id' => $owner->id,
                'personal_group_id' => $groupId,
                'type' => SecretItemType::File,
                'name' => $name ?: $file->getClientOriginalName(),
                'value' => null,
                'description' => $description,
            ]);

            $contents = $file->get();

            if ($contents === false) {
                throw new \RuntimeException("Could not read the uploaded file {$file->getClientOriginalName()}.");
            }

            Storage::disk($this->disk())->put(
                $secret->storagePath(),
                Crypt::encryptString($contents),
            );

            $this->audit->record(
                'personal.file-stored',
                subject: $secret,
                properties: ['name' => $secret->name],
                scope: AuditScope::personal(),
                causer: $owner,
            );

            return $secret;
        });
    }

    private function disk(): string
    {
        return config('filesystems.default', 'local');
    }
}
