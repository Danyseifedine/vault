<?php

namespace App\Services\Reveal;

use App\Models\Pin;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * The PIN half of a reveal: does this PIN belong to this person here, and have
 * they run out of tries?
 *
 * Two things reveal secrets - a variable's value and a shared vault item - and
 * a four digit PIN is only safe while the attempt counting is exactly as strict
 * in both. So the counting lives here once, and each caller supplies its own
 * throttle key and limits.
 */
final class PinGate
{
    /** Any ACTIVE PIN the user holds in this organization opens the door. */
    public function matches(User $user, int $organizationId, #[\SensitiveParameter] string $candidate): bool
    {
        $pins = Pin::query()
            ->active()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->get();

        foreach ($pins as $pin) {
            if (Hash::check($candidate, $pin->pin_hash)) {
                return true;
            }
        }

        return false;
    }

    public function lockedUntil(string $key): ?Carbon
    {
        $until = Cache::get("{$key}:locked");

        return $until instanceof \DateTimeInterface ? Carbon::instance($until) : null;
    }

    /** @return int Attempts left before the lockout bites; 0 means locked now. */
    public function registerFailure(string $key, int $maxAttempts, int $lockoutMinutes): int
    {
        $attempts = ((int) Cache::get($key, 0)) + 1;

        Cache::put($key, $attempts, now()->addMinutes($lockoutMinutes));

        if ($attempts >= $maxAttempts) {
            Cache::put("{$key}:locked", now()->addMinutes($lockoutMinutes), now()->addMinutes($lockoutMinutes));

            return 0;
        }

        return $maxAttempts - $attempts;
    }

    public function clear(string $key): void
    {
        Cache::forget($key);
        Cache::forget("{$key}:locked");
    }
}
