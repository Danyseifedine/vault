<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify registers its recovery-code endpoints unconditionally, and we cannot
 * edit a vendor route file. So they are closed here instead: reading them would
 * hand out codes nothing accepts, and regenerating them would quietly undo
 * EnableTwoFactorAuthentication's cleanup.
 */
class RefuseRecoveryCodes
{
    private const CLOSED = [
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->route()?->getName(), self::CLOSED, true)) {
            abort(404);
        }

        return $next($request);
    }
}
