<?php

namespace App\Actions\PersonalVault;

use App\Models\PersonalGroup;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A folder inside one person's vault. Names only need to be unique to their
 * owner - two people may each keep a group called "Work".
 */
class CreatePersonalGroup
{
    public function __construct(private AuditRecorder $audit) {}

    public function __invoke(User $owner, string $name, int $position = 0): PersonalGroup
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A group needs a name.']);
        }

        $taken = PersonalGroup::query()->ownedBy($owner)->get()
            ->contains(fn (PersonalGroup $group) => Str::lower($group->name) === Str::lower($name));

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "You already have a group called \"{$name}\".",
            ]);
        }

        $group = PersonalGroup::create([
            'user_id' => $owner->id,
            'name' => $name,
            'position' => $position,
        ]);

        $this->audit->record(
            'personal.group-created',
            subject: $group,
            properties: ['name' => $name],
            scope: AuditScope::personal(),
            causer: $owner,
        );

        return $group;
    }
}
