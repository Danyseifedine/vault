<?php

namespace App\Actions\PersonalVault;

use App\Models\PersonalSecret;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Personal\PersonalGuard;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Reads a personal item back.
 *
 * The ownership check here has no administrative escape hatch on purpose:
 * "fully private" has to mean private from everybody, including whoever runs
 * the organization, or it means nothing.
 */
class RevealPersonalSecret
{
    public function __construct(
        private AuditRecorder $audit,
        private PersonalGuard $guard,
    ) {}

    public function __invoke(PersonalSecret $secret, User $requester): string
    {
        $this->guard->requireOwner($secret, $requester);

        $contents = $secret->isFile()
            ? Crypt::decryptString(Storage::disk(config('filesystems.default', 'local'))->get($secret->storagePath()))
            : (string) $secret->value;

        // Logged to the owner's private trail only: an entry saying
        // "revealed: personal GitHub token" must never appear in an org feed.
        $this->audit->record(
            'personal.revealed',
            subject: $secret,
            properties: ['name' => $secret->name, 'type' => $secret->type->value],
            scope: AuditScope::personal(),
            causer: $requester,
        );

        return $contents;
    }
}
