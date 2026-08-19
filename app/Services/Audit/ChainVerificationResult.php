<?php

namespace App\Services\Audit;

final readonly class ChainVerificationResult
{
    public function __construct(
        public bool $intact,
        public int $checked,
        public ?int $brokenAtId = null,
        public ?string $reason = null,
        public ?string $headHash = null,
    ) {}

    public static function intact(int $checked, ?string $headHash): self
    {
        return new self(intact: true, checked: $checked, headHash: $headHash);
    }

    public static function broken(int $checked, int $brokenAtId, string $reason): self
    {
        return new self(intact: false, checked: $checked, brokenAtId: $brokenAtId, reason: $reason);
    }
}
