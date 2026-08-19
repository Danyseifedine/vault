<?php

namespace Tests\Feature\Audit;

use App\Services\Audit\AppendOnlyLog;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\ChainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChainVerifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The chain anchor lives on disk. Without a fake, a real anchor left
        // by `vault:anchor-audit-chain` on this machine would leak into the
        // tests and fail them for the wrong reason.
        Storage::fake(config('audit.anchor_disk', 'local'));
    }

    private function seedChain(int $count = 5): void
    {
        $recorder = app(AuditRecorder::class);

        foreach (range(1, $count) as $i) {
            $recorder->record('variable.created', properties: ['n' => $i]);
        }
    }

    /**
     * An attacker with raw database access could also drop our triggers - * so the chain, not the trigger, is the real tamper evidence.
     */
    private function dropAppendOnlyGuards(): void
    {
        foreach (AppendOnlyLog::unprotect(DB::getDriverName()) as $statement) {
            DB::unprepared($statement);
        }
    }

    public function test_it_passes_on_an_intact_chain(): void
    {
        $this->seedChain();

        $result = app(ChainVerifier::class)->verify();

        $this->assertTrue($result->intact);
        $this->assertSame(5, $result->checked);
        $this->assertNull($result->brokenAtId);
    }

    public function test_it_passes_on_an_empty_log(): void
    {
        $result = app(ChainVerifier::class)->verify();

        $this->assertTrue($result->intact);
        $this->assertSame(0, $result->checked);
    }

    /**
     * Regression: the hash must cover what the properties MEAN, not the JSON
     * text a particular database happened to store. Re-encoding the column
     * with different spacing and key order must leave the chain intact - * otherwise a storage engine that normalises JSON silently "breaks" every
     * entry and the alarm becomes noise nobody trusts.
     */
    public function test_the_chain_survives_json_reformatting_by_the_database(): void
    {
        app(AuditRecorder::class)->record('variable.created', properties: [
            'key' => 'DATABASE_URL',
            'environment' => 'prod',
            'nested' => ['b' => 2, 'a' => 1],
        ]);

        $this->dropAppendOnlyGuards();

        $row = DB::table('activity_log')->latest('id')->first();
        $decoded = json_decode($row->properties, associative: true);
        krsort($decoded);

        // Same content, different bytes: spaces after the colons, keys reversed.
        DB::table('activity_log')->where('id', $row->id)->update([
            'properties' => json_encode($decoded, JSON_PRETTY_PRINT),
        ]);

        $result = app(ChainVerifier::class)->verify();

        $this->assertTrue($result->intact, $result->reason ?? '');
    }

    public function test_it_still_detects_a_genuine_change_to_the_properties(): void
    {
        app(AuditRecorder::class)->record('variable.created', properties: ['key' => 'DATABASE_URL']);

        $this->dropAppendOnlyGuards();

        $row = DB::table('activity_log')->latest('id')->first();
        DB::table('activity_log')->where('id', $row->id)->update([
            'properties' => json_encode(['key' => 'SOMETHING_ELSE']),
        ]);

        $result = app(ChainVerifier::class)->verify();

        $this->assertFalse($result->intact);
        $this->assertSame($row->id, $result->brokenAtId);
    }

    public function test_it_detects_an_edited_entry_and_names_it(): void
    {
        $this->seedChain();
        $this->dropAppendOnlyGuards();

        $target = DB::table('activity_log')->orderBy('id')->skip(2)->first();
        DB::table('activity_log')->where('id', $target->id)->update(['event' => 'quietly.changed']);

        $result = app(ChainVerifier::class)->verify();

        $this->assertFalse($result->intact);
        $this->assertSame($target->id, $result->brokenAtId);
        $this->assertStringContainsString('content', $result->reason);
    }

    public function test_it_detects_a_deleted_entry(): void
    {
        $this->seedChain();
        $this->dropAppendOnlyGuards();

        $target = DB::table('activity_log')->orderBy('id')->skip(2)->first();
        DB::table('activity_log')->where('id', $target->id)->delete();

        $result = app(ChainVerifier::class)->verify();

        $this->assertFalse($result->intact);
        $this->assertStringContainsString('link', $result->reason);
    }

    public function test_the_command_reports_a_healthy_chain(): void
    {
        $this->seedChain(3);

        $this->artisan('vault:verify-audit-chain')
            ->expectsOutputToContain('intact')
            ->assertExitCode(0);
    }

    public function test_the_command_fails_loudly_on_a_broken_chain(): void
    {
        $this->seedChain(3);
        $this->dropAppendOnlyGuards();
        DB::table('activity_log')->orderBy('id')->limit(1)->update(['event' => 'tampered']);

        $this->artisan('vault:verify-audit-chain')->assertExitCode(1);
    }
}
