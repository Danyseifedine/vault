<?php

namespace App\Actions\PersonalVault;

use App\Models\PersonalSecret;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Personal\PersonalGuard;
use Illuminate\Validation\ValidationException;

/**
 * Edits an item: its name, note, folder, and - for a secret - its value.
 *
 * The value is optional and a blank one means "leave the stored one alone",
 * the same bargain the shared vault makes. That is what keeps a rename from
 * eating a secret: only something you actually typed can overwrite anything.
 *
 * A file is never rewritten here. Its bytes were encrypted on the way to disk
 * and replacing them is uploading a new file, not editing a label.
 */
class UpdatePersonalItem
{
    public function __construct(
        private PersonalGuard $guard,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(
        PersonalSecret $item,
        User $owner,
        string $name,
        ?int $groupId = null,
        ?string $description = null,
        #[\SensitiveParameter] ?string $value = null,
    ): PersonalSecret {
        $this->guard->requireOwner($item, $owner);

        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'An item needs a name.']);
        }

        $this->guard->requireOwnGroupId($groupId, $owner);

        $previous = $item->name;
        $rotating = $value !== null && $value !== '' && ! $item->isFile();

        $item->fill([
            'name' => $name,
            'personal_group_id' => $groupId,
            'description' => $description,
        ]);

        if ($rotating) {
            $item->value = $value;
        }

        $item->save();

        /*
         * Two different facts, so two different words. "When did I last rotate
         * this token?" is a question worth being able to answer at a glance in
         * the private trail, and it should not be buried under every rename.
         * Neither entry ever carries a value - not the old one, not the new
         * one.
         */
        $this->audit->record(
            $rotating ? 'personal.value-changed' : 'personal.updated',
            subject: $item,
            properties: ['from' => $previous, 'to' => $name],
            scope: AuditScope::personal(),
            causer: $owner,
        );

        return $item;
    }
}
