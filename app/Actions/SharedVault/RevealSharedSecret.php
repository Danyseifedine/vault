<?php

namespace App\Actions\SharedVault;

use App\Models\Organization;
use App\Models\SharedSecret;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Reveal\PinGate;
use App\Services\Reveal\RevealOutcome;
use App\Services\Shared\SharedVaultGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * The only path that decrypts a shared vault item.
 *
 * Reading one always costs a PIN, whatever the item is. Variables carry a
 * sensitivity and an environment policy that can waive it; a shared item has
 * neither, and the things that live here - a root password, a signing key -
 * are the ones you would have set to "PIN" anyway.
 *
 * The attempt counting is the same instrument the variable reveal uses
 * (PinGate), so a four digit PIN is no easier to guess here.
 */
class RevealSharedSecret
{
    /** No project settings to read at organization level, so: the defaults. */
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_MINUTES = 15;

    public function __construct(
        private AuditRecorder $audit,
        private SharedVaultGuard $guard,
        private PinGate $pins,
    ) {}

    public function __invoke(
        SharedSecret $secret,
        Organization $organization,
        User $user,
        #[\SensitiveParameter] ?string $pin = null,
    ): RevealOutcome {
        $this->guard->requireOwnItem($secret, $organization);

        $scope = AuditScope::make($organization->id);
        $context = ['name' => $secret->name, 'type' => $secret->type->value];
        $key = $this->throttleKey($user, $organization);

        if ($lockedUntil = $this->pins->lockedUntil($key)) {
            $this->audit->failure('shared.locked-out', $secret, $context, scope: $scope, causer: $user);

            return RevealOutcome::locked($lockedUntil);
        }

        if (! $this->guard->canReveal($user, $organization)) {
            $this->audit->failure('shared.reveal-denied', $secret, $context, scope: $scope, causer: $user);

            return RevealOutcome::refused(RevealOutcome::DENIED);
        }

        if ($pin === null) {
            return RevealOutcome::refused(RevealOutcome::PIN_REQUIRED);
        }

        if (! $this->pins->matches($user, $organization->id, $pin)) {
            $remaining = $this->pins->registerFailure($key, self::MAX_ATTEMPTS, self::LOCKOUT_MINUTES);

            $this->audit->failure(
                'pin.failed',
                $secret,
                $context + ['attempts_remaining' => $remaining],
                scope: $scope,
                causer: $user,
            );

            if ($remaining > 0) {
                return RevealOutcome::refused(RevealOutcome::PIN_INCORRECT, $remaining);
            }

            return RevealOutcome::locked(
                $this->pins->lockedUntil($key)
                    ?? Carbon::now()->addMinutes(self::LOCKOUT_MINUTES),
            );
        }

        $this->pins->clear($key);

        $this->audit->record(
            'shared.revealed',
            subject: $secret,
            properties: $context,
            scope: $scope,
            causer: $user,
        );

        return RevealOutcome::granted($this->contentsOf($secret));
    }

    /**
     * The JSON reveal path, which can only carry a typed secret.
     *
     * A file's bytes are not UTF-8 and would make json_encode throw, so a file
     * is turned away here and read through download or preview instead. The
     * refusal comes BEFORE the PIN is checked, so asking for the wrong thing
     * never costs an attempt - but only someone already holding `shared.reveal`
     * is told it is a file. To anyone else it is an ordinary denial, because
     * "that id is a file" is not a fact an id-enumerating stranger should get.
     */
    public function secret(
        SharedSecret $secret,
        Organization $organization,
        User $user,
        #[\SensitiveParameter] ?string $pin = null,
    ): RevealOutcome {
        $this->guard->requireOwnItem($secret, $organization);

        if ($secret->isFile() && $this->guard->canReveal($user, $organization)) {
            return RevealOutcome::refused(RevealOutcome::FILE_ITEM);
        }

        return $this($secret, $organization, $user, $pin);
    }

    /** True when this viewer may even be offered the reveal button. */
    public function allowed(User $user, Organization $organization): bool
    {
        return $this->guard->canReveal($user, $organization)
            && $this->guard->canView($user, $organization);
    }

    private function contentsOf(SharedSecret $secret): string
    {
        if (! $secret->isFile()) {
            return (string) $secret->value;
        }

        return Crypt::decryptString(
            Storage::disk(config('filesystems.default', 'local'))->get($secret->storagePath()),
        );
    }

    private function throttleKey(User $user, Organization $organization): string
    {
        return "shared-reveal-attempts:{$user->id}:{$organization->id}";
    }
}
