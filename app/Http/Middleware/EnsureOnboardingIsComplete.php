<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single gate in front of the whole application.
 *
 * A user must be active (invitation claimed), carry confirmed two-factor
 * authentication, and have completed their profile. Anyone short of that is
 * returned to the stepper, which decides which step they are actually on -
 * so there is one place that knows the order, not three.
 */
class EnsureOnboardingIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasFinishedOnboarding()) {
            return redirect()->route('onboarding.show');
        }

        return $next($request);
    }
}
