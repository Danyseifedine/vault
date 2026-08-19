<?php

namespace App\Console\Commands;

use App\Services\Audit\AnchorWriter;
use Illuminate\Console\Command;

class AnchorAuditChain extends Command
{
    protected $signature = 'vault:anchor-audit-chain';

    protected $description = 'Store the latest audit chain hash outside the database';

    public function handle(AnchorWriter $anchors): int
    {
        $hash = $anchors->write();

        if ($hash === null) {
            $this->info('Audit log is empty - nothing to anchor.');

            return self::SUCCESS;
        }

        $this->info("Anchored audit chain head: {$hash}");

        return self::SUCCESS;
    }
}
