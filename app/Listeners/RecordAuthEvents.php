<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;

/**
 * Signing in belongs in the log, and until this
 * listener only Google sign-ins were recorded. Failures too: a run of
 * auth.login-failed against one address is the entry an investigation starts
 * from.
 */
class RecordAuthEvents
{
    public function __construct(private AuditRecorder $audit) {}

    public function recordLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->record(
            'auth.login',
            properties: ['guard' => $event->guard],
            scope: AuditScope::none(),
            causer: $event->user,
        );
    }

    public function recordFailure(Failed $event): void
    {
        // The attempted email is what an investigation needs; the user may be
        // null when the address does not exist, and the password never leaves
        // the request.
        $this->audit->failure(
            'auth.login-failed',
            properties: ['email' => $event->credentials['email'] ?? null],
            scope: AuditScope::none(),
            causer: $event->user instanceof User ? $event->user : null,
        );
    }
}
