<?php

namespace App\Listeners;

use App\Mail\NewLoginMail;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the account on every successful sign-in (which, for a two-factor
 * account, fires only after the code has been accepted - the Login event does
 * not fire on the password alone).
 *
 * The request details are read HERE, while the request still exists, and handed
 * to the mailable; the actual send is queued so a slow or failing mail server
 * never holds up - or breaks - a sign-in. Anything that does go wrong is logged
 * and swallowed for the same reason: an alert failing is not a reason to refuse
 * someone entry they have already earned.
 */
class SendNewLoginAlert
{
    public function __construct(private Request $request) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        try {
            $agent = (string) ($this->request->userAgent() ?? '');

            Mail::to($event->user->email)->queue(new NewLoginMail(
                user: $event->user,
                ip: (string) ($this->request->ip() ?? 'unknown'),
                device: $this->describeDevice($agent),
                userAgent: $agent !== '' ? $agent : 'unknown',
                at: Carbon::now(),
            ));
        } catch (\Throwable $e) {
            // A failed alert must never block a sign-in that already succeeded.
            Log::warning('New-login alert could not be queued: '.$e->getMessage());
        }
    }

    /** A readable "Browser on OS" from a user-agent string, best effort. */
    private function describeDevice(string $agent): string
    {
        if ($agent === '') {
            return 'an unknown device';
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'a browser',
        };

        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'an unknown system',
        };

        return "{$browser} on {$os}";
    }
}
