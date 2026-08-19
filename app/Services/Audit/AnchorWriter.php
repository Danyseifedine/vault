<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the newest chain hash somewhere outside the database.
 *
 * Without an external anchor, an attacker who rewrote every row could also
 * recompute every hash and leave a perfectly consistent - but false - chain.
 * Comparing the head against a stored copy closes that gap.
 */
class AnchorWriter
{
    private const PATH = 'audit/chain-anchor.json';

    public function write(): ?string
    {
        $head = AuditLog::query()->orderByDesc('id')->first();

        if ($head === null) {
            return null;
        }

        Storage::disk(config('audit.anchor_disk', 'local'))->put(self::PATH, json_encode([
            'entry_id' => $head->id,
            'hash' => $head->hash,
            'anchored_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $head->hash;
    }

    /**
     * Forget the anchor. Only for a database that no longer has the history the
     * anchor described - after `migrate:fresh`, where keeping it would report
     * tamper on every run and teach everyone to ignore the alarm.
     */
    public function clear(): void
    {
        Storage::disk(config('audit.anchor_disk', 'local'))->delete(self::PATH);
    }

    /** @return array{entry_id: int, hash: string, anchored_at: string}|null */
    public function read(): ?array
    {
        $disk = Storage::disk(config('audit.anchor_disk', 'local'));

        if (! $disk->exists(self::PATH)) {
            return null;
        }

        return json_decode($disk->get(self::PATH), associative: true);
    }
}
