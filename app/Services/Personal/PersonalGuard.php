<?php

namespace App\Services\Personal;

use App\Models\PersonalGroup;
use App\Models\PersonalSecret;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Ownership is the whole security model of the personal vault.
 *
 * There is deliberately no role, permission or administrative override in this
 * file: "fully private" has to mean private from everybody, including whoever
 * runs the organization, or it means nothing.
 */
final class PersonalGuard
{
    public function __construct(private AuditRecorder $audit) {}

    /** @throws AuthorizationException */
    public function requireOwner(PersonalSecret $secret, User $user): void
    {
        if ($secret->user_id === $user->id) {
            return;
        }

        $this->recordIntrusion($user, ['item' => $secret->id]);

        throw new AuthorizationException('This item belongs to someone else.');
    }

    /** @throws AuthorizationException */
    public function requireOwnerOfGroup(PersonalGroup $group, User $user): void
    {
        if ($group->user_id === $user->id) {
            return;
        }

        $this->recordIntrusion($user, ['group' => $group->id]);

        throw new AuthorizationException('This group belongs to someone else.');
    }

    /**
     * Filing an item into a group you do not own would leak its name into
     * someone else's vault, so it is refused as invalid input.
     *
     * @throws ValidationException
     */
    public function requireOwnGroupId(?int $groupId, User $user): void
    {
        if ($groupId === null) {
            return;
        }

        $exists = PersonalGroup::query()->ownedBy($user)->whereKey($groupId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'personal_group_id' => 'That group does not exist in your vault.',
            ]);
        }
    }

    /** @param  array<string, mixed>  $properties */
    private function recordIntrusion(User $user, array $properties): void
    {
        $this->audit->failure(
            'personal.access-denied',
            properties: $properties,
            scope: AuditScope::personal(),
            causer: $user,
        );
    }
}
