<?php

namespace App\Services\Reveal;

use Illuminate\Support\Carbon;

/**
 * The result of asking to see a secret. Distinguishes *why* a reveal failed so
 * the UI can respond honestly - "wrong PIN" and "you don't have access" are
 * very different messages.
 */
final readonly class RevealOutcome
{
    public const DENIED = 'denied';

    public const PIN_REQUIRED = 'pin_required';

    public const PIN_INCORRECT = 'pin_incorrect';

    public const PASSWORD_REQUIRED = 'password_required';

    public const PASSWORD_INCORRECT = 'password_incorrect';

    public const LOCKED = 'locked';

    /**
     * Asked for a file's bytes through a JSON endpoint. A keystore is not
     * UTF-8, so json_encode would refuse it - files are read by streaming
     * (download) or by a bounded look (preview) instead.
     */
    public const FILE_ITEM = 'file_item';

    private function __construct(
        public bool $granted,
        public ?string $value = null,
        public ?string $reason = null,
        public ?int $attemptsRemaining = null,
        public ?Carbon $lockedUntil = null,
    ) {}

    public static function granted(#[\SensitiveParameter] string $value): self
    {
        return new self(granted: true, value: $value);
    }

    public static function refused(string $reason, ?int $attemptsRemaining = null): self
    {
        return new self(granted: false, reason: $reason, attemptsRemaining: $attemptsRemaining);
    }

    public static function locked(Carbon $until): self
    {
        return new self(granted: false, reason: self::LOCKED, lockedUntil: $until);
    }
}
