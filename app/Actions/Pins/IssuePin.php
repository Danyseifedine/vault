<?php

namespace App\Actions\Pins;

use App\Enums\Permission;
use App\Enums\PinStatus;
use App\Models\Organization;
use App\Models\Pin;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Issues a reveal PIN to someone.
 *
 * Takes the `pins.manage` grant, held org-wide. A PIN is the second gate in
 * front of every secret, so the authority to hand one out is worth granting
 * deliberately rather than bundling with anything else.
 */
class IssuePin
{
    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(
        Organization $organization,
        User $issuer,
        User $holder,
        #[\SensitiveParameter] string $pin,
        ?string $label = null,
    ): Pin {
        if (! $this->access->can($issuer, Permission::ManagePins, $organization)) {
            $this->audit->failure(
                'pin.issue-denied',
                properties: ['holder' => $holder->email],
                scope: AuditScope::make(organizationId: $organization->id),
                causer: $issuer,
            );

            throw new AuthorizationException('Issuing reveal PINs takes the pins.manage permission.');
        }

        if (! preg_match('/^\d{4}$/', $pin)) {
            throw ValidationException::withMessages(['pin' => 'A reveal PIN must be exactly four digits.']);
        }

        if (! $organization->hasMember($holder)) {
            throw ValidationException::withMessages(['holder' => 'That person is not a member of this organization.']);
        }

        $issued = Pin::create([
            'organization_id' => $organization->id,
            'user_id' => $holder->id,
            'pin_hash' => Hash::make($pin),
            'label' => $label,
            'status' => PinStatus::Active,
            'created_by' => $issuer->id,
        ]);

        $this->audit->record(
            'pin.issued',
            subject: $issued,
            properties: ['holder' => $holder->email, 'label' => $label],
            scope: AuditScope::make(organizationId: $organization->id),
            causer: $issuer,
        );

        return $issued;
    }
}
