<?php

namespace App\Console\Commands;

use App\Services\Audit\AnchorWriter;
use App\Services\Audit\ChainVerifier;
use Illuminate\Console\Command;

class VerifyAuditChain extends Command
{
    protected $signature = 'vault:verify-audit-chain';

    protected $description = 'Verify that the audit log has not been altered';

    public function handle(ChainVerifier $verifier, AnchorWriter $anchors): int
    {
        $result = $verifier->verify();

        if (! $result->intact) {
            $this->error('Audit chain BROKEN.');
            $this->line($result->reason);
            $this->line("Entries checked before the break: {$result->checked}");

            return self::FAILURE;
        }

        $this->info("Audit chain intact - {$result->checked} entries verified.");

        $anchor = $anchors->read();

        if ($anchor !== null && $result->headHash !== null && $anchor['hash'] !== $result->headHash) {
            $anchoredAt = $anchor['anchored_at'];

            // The chain is self-consistent but disagrees with the copy we
            // stored outside the database - the signature of a wholesale rewrite.
            $this->error("Chain does not match the external anchor written at {$anchoredAt}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
