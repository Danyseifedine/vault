<?php

namespace App\Actions\PersonalVault;

use App\Enums\SecretItemType;
use App\Models\PersonalSecret;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Personal\PersonalGuard;
use Illuminate\Validation\ValidationException;

/**
 * Stores a private credential. Encrypted by the model cast, audited only to
 * the owner's own trail, and never visible to anyone else.
 */
class CreatePersonalSecret
{
    public function __construct(
        private PersonalGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(
        User $owner,
        string $name,
        #[\SensitiveParameter] string $value,
        ?int $groupId = null,
        ?string $description = null,
    ): PersonalSecret {
        $name = trim($name);

        if ($name === '' || $value === '') {
            throw ValidationException::withMessages([
                'name' => 'A personal secret needs both a name and a value.',
            ]);
        }

        $this->guard->requireOwnGroupId($groupId, $owner);

        $secret = PersonalSecret::create([
            'user_id' => $owner->id,
            'personal_group_id' => $groupId,
            'type' => SecretItemType::Secret,
            'name' => $name,
            'value' => $value,
            'description' => $description,
        ]);

        $this->audit->record(
            'personal.secret-created',
            subject: $secret,
            properties: ['name' => $name],
            scope: AuditScope::personal(),
            causer: $owner,
        );

        return $secret;
    }
}
