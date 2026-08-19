<?php

namespace App\Services\Reveal;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\User;
use App\Models\Variable;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * The ONLY place a stored secret is ever decrypted.
 *
 * Everything a reveal must satisfy lives here in one readable sequence:
 * permission, app scope, the environment's requirement for that sensitivity,
 * an active PIN, the account password for critical values, and a rate limit -
 * then, and only then, the value, always with an audit entry either way.
 *
 * If you find yourself calling ->value on a VariableValue anywhere else, that
 * is a bug.
 */
class RevealGuard
{
    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
        private PinGate $pins,
    ) {}

    public function reveal(
        User $user,
        Variable $variable,
        Environment $environment,
        #[\SensitiveParameter] ?string $pin = null,
        #[\SensitiveParameter] ?string $password = null,
    ): RevealOutcome {
        $project = $variable->project;
        $scope = AuditScope::make($project->organization_id, $project->id);
        $context = ['key' => $variable->key, 'environment' => $environment->slug];

        if ($lockedUntil = $this->lockedUntil($user, $variable)) {
            $this->audit->failure('reveal.locked-out', $variable, $context, scope: $scope, causer: $user);

            return RevealOutcome::locked($lockedUntil);
        }

        if (! $this->access->can($user, Permission::RevealValues, $environment)) {
            $this->audit->failure('reveal.denied', $variable, $context, scope: $scope, causer: $user);

            return RevealOutcome::refused(RevealOutcome::DENIED);
        }

        $requirement = $environment->requirementFor($variable->sensitivity);

        if ($requirement->requiresPin()) {
            if ($pin === null) {
                return RevealOutcome::refused(RevealOutcome::PIN_REQUIRED);
            }

            if (! $this->pinMatches($user, $variable, $pin)) {
                $remaining = $this->registerFailedAttempt($user, $variable);

                $this->audit->failure(
                    'pin.failed',
                    $variable,
                    $context + ['attempts_remaining' => $remaining],
                    scope: $scope,
                    causer: $user,
                );

                return $remaining <= 0
                    ? RevealOutcome::locked($this->lockedUntil($user, $variable))
                    : RevealOutcome::refused(RevealOutcome::PIN_INCORRECT, $remaining);
            }
        }

        if ($requirement->requiresPassword()) {
            if ($password === null) {
                return RevealOutcome::refused(RevealOutcome::PASSWORD_REQUIRED);
            }

            if ($user->password === null || ! Hash::check($password, $user->password)) {
                $this->audit->failure('reveal.password-incorrect', $variable, $context, scope: $scope, causer: $user);

                return RevealOutcome::refused(RevealOutcome::PASSWORD_INCORRECT);
            }
        }

        $value = $variable->valueIn($environment);

        if ($value === null) {
            return RevealOutcome::refused(RevealOutcome::DENIED);
        }

        $this->clearAttempts($user, $variable);

        $this->audit->record(
            'variable.revealed',
            subject: $variable,
            properties: $context + ['sensitivity' => $variable->sensitivity->value],
            scope: $scope,
            causer: $user,
        );

        return RevealOutcome::granted($value->value);
    }

    private function pinMatches(User $user, Variable $variable, #[\SensitiveParameter] string $candidate): bool
    {
        return $this->pins->matches($user, $variable->project->organization_id, $candidate);
    }

    private function throttleKey(User $user, Variable $variable): string
    {
        return "reveal-attempts:{$user->id}:{$variable->project_id}";
    }

    /** @return int Attempts left before the lockout bites. */
    private function registerFailedAttempt(User $user, Variable $variable): int
    {
        $settings = $variable->project->settings;

        return $this->pins->registerFailure(
            $this->throttleKey($user, $variable),
            $settings->pin_max_attempts,
            $settings->pin_lockout_minutes,
        );
    }

    private function lockedUntil(User $user, Variable $variable): ?Carbon
    {
        return $this->pins->lockedUntil($this->throttleKey($user, $variable));
    }

    private function clearAttempts(User $user, Variable $variable): void
    {
        $this->pins->clear($this->throttleKey($user, $variable));
    }
}
