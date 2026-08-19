<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A password that has been accepted but not yet confirmed by a code leaves you
 * in a half-authenticated state, held in the session as `login.id`. Fortify
 * gives you no way out of it - logout needs a session you do not have yet - so
 * the only exit was clearing cookies. This is the exit.
 */
class CancelTwoFactorChallengeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->session()->forget(['login.id', 'login.remember']);

        return redirect()->route('login');
    }
}
