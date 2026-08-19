<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Re-links the audit chain after rows have been removed.
 *
 * READ THIS BEFORE USING IT ANYWHERE ELSE. This is the one operation the hash
 * chain exists to make impossible, and it is here for exactly one caller:
 * WipeActivityLog. Every other path must leave the chain alone.
 *
 * The chain is global - an entry points at whatever row preceded it in the
 * whole table, whichever organization that belonged to. So removing one
 * organization's rows punches holes in everybody's links, and the survivors
 * have to be re-pointed or nothing verifies again, ever.
 *
 * The cost is real and cannot be engineered away: once the survivors are
 * re-hashed, any tampering that happened BEFORE the wipe becomes unprovable,
 * because the evidence of it was the mismatch we just recomputed away. That is
 * why the caller writes an indelible marker recording the pre-wipe head hash:
 * the rewrite itself is the thing that can never be hidden.
 *
 * Runs only inside the window where WipeActivityLog has dropped the
 * append-only triggers, since re-linking is by definition an UPDATE.
 */
class ChainRelinker
{
    /**
     * Walk every remaining entry in id order, re-pointing and re-hashing.
     *
     * @return int How many rows were re-linked.
     */
    public function relink(): int
    {
        $touched = 0;
        $previousHash = null;

        foreach (AuditLog::query()->orderBy('id')->lazy() as $entry) {
            $expected = $entry->previous_hash === $previousHash
                ? $entry->hash
                : null;

            if ($expected === null) {
                $entry->previous_hash = $previousHash;
                $entry->hash = $entry->computeChainHash();

                // Straight to the query builder: saving the model would fire
                // the `creating` hook's sibling events and, more importantly,
                // Activity's own touch behaviour. The row is being repaired,
                // not changed.
                DB::table(AppendOnlyLog::TABLE)
                    ->where('id', $entry->id)
                    ->update([
                        'previous_hash' => $entry->previous_hash,
                        'hash' => $entry->hash,
                    ]);

                $touched++;
            }

            $previousHash = $entry->hash;
        }

        return $touched;
    }
}
