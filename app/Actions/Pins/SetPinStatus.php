<?php

namespace App\Actions\Pins;

use App\Enums\Permission;
use App\Enums\PinStatus;
use App\Models\Pin;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Blocks or re-activates a PIN.
 *
 * Blocking is the kill switch: the holder's ability to reveal stops on the
 * next request, without disabling their account or touching their access.
 */
class SetPinStatus
{
    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Pin $pin, User $actor, PinStatus $status): Pin
    {
        $organization = $pin->organization;

        if (! $this->access->can($actor, Permission::ManagePins, $organization)) {
            $this->audit->failure(
                'pin.status-change-denied',
                subject: $pin,
                properties: ['attempted' => $status->value],
                scope: AuditScope::make(organizationId: $organization->id),
                causer: $actor,
            );

            throw new AuthorizationException('Managing reveal PINs takes the pins.manage permission.');
        }

        $pin->forceFill([
            'status' => $status,
            'blocked_at' => $status === PinStatus::Blocked ? now() : null,
            'blocked_by' => $status === PinStatus::Blocked ? $actor->id : null,
        ])->save();

        $this->audit->record(
            $status === PinStatus::Blocked ? 'pin.blocked' : 'pin.activated',
            subject: $pin,
            properties: ['holder' => $pin->user->email],
            scope: AuditScope::make(organizationId: $organization->id),
            causer: $actor,
        );

        return $pin;
    }
}
