<?php

namespace App\Services\Audit;

/**
 * Where in the hierarchy an audited action happened.
 *
 * Both ids null means the entry belongs to no organization - either a
 * system/auth event, or personal-vault activity, which must never surface in
 * an organization or project feed.
 */
final readonly class AuditScope
{
    public function __construct(
        public ?int $organizationId = null,
        public ?int $projectId = null,
    ) {}

    public static function make(?int $organizationId = null, ?int $projectId = null): self
    {
        return new self($organizationId, $projectId);
    }

    public static function none(): self
    {
        return new self;
    }

    /** Personal vault: deliberately unscoped, visible only to its owner. */
    public static function personal(): self
    {
        return new self;
    }
}
