<?php

namespace Database\Seeders;

use App\Services\Audit\AnchorWriter;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * No demo data. This is a secrets manager, and a vault that arrives full of
     * invented variables teaches people to ignore what is in it - you stop
     * reading a screen you have learned is fiction. Just the one local account;
     * everything else you make yourself, which is also the only way to find out
     * whether making it actually works.
     */
    public function run(): void
    {
        $this->call([DevOwnerSeeder::class]);

        // An anchor from the destroyed database describes history this one does
        // not have, so leaving it would make `vault:verify-audit-chain` cry
        // tamper after every reseed - and an alarm you learn to ignore is worse
        // than no alarm. Nothing to anchor yet, so it is simply forgotten.
        $anchors = app(AnchorWriter::class);

        if ($anchors->write() === null) {
            $anchors->clear();
        }
    }
}
