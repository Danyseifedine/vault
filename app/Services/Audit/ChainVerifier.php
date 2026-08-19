<?php

namespace App\Services\Audit;

use App\Models\AuditLog;

/**
 * Walks the audit log recomputing every hash.
 *
 * Two things break a chain: an entry whose content no longer matches its own
 * hash (edited), and an entry whose `previous_hash` no longer matches the
 * entry before it (something was removed or reordered).
 */
class ChainVerifier
{
    public function verify(): ChainVerificationResult
    {
        $checked = 0;
        $previousHash = null;

        foreach (AuditLog::query()->orderBy('id')->lazy() as $entry) {
            $checked++;

            if ($entry->previous_hash !== $previousHash) {
                return ChainVerificationResult::broken(
                    checked: $checked,
                    brokenAtId: $entry->id,
                    reason: "Broken link at entry #{$entry->id}: it points at a different predecessor than the one before it - an entry was removed or reordered.",
                );
            }

            if ($entry->hash !== $entry->computeChainHash()) {
                return ChainVerificationResult::broken(
                    checked: $checked,
                    brokenAtId: $entry->id,
                    reason: "Altered content at entry #{$entry->id}: its stored hash does not match its own data.",
                );
            }

            $previousHash = $entry->hash;
        }

        return ChainVerificationResult::intact($checked, $previousHash);
    }
}
