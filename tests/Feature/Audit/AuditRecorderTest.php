<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function recorder(): AuditRecorder
    {
        return app(AuditRecorder::class);
    }

    public function test_it_records_an_entry_with_causer_and_event(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->recorder()->record('variable.created', properties: ['key' => 'LOG_LEVEL']);

        $entry = AuditLog::query()->latest('id')->first();

        $this->assertSame('variable.created', $entry->event);
        $this->assertSame($user->id, $entry->causer_id);
        $this->assertSame('LOG_LEVEL', $entry->properties['key']);
    }

    public function test_it_captures_request_context_and_scope(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->recorder()->record(
            'variable.revealed',
            scope: AuditScope::make(organizationId: 7, projectId: 3),
        );

        $entry = AuditLog::query()->latest('id')->first();

        $this->assertSame(7, $entry->organization_id);
        $this->assertSame(3, $entry->project_id);
        $this->assertNotNull($entry->ip);
        $this->assertNotNull($entry->user_agent);
    }

    public function test_the_first_entry_has_no_previous_hash(): void
    {
        $this->recorder()->record('auth.login');

        $entry = AuditLog::query()->latest('id')->first();

        $this->assertNull($entry->previous_hash);
        $this->assertNotNull($entry->hash);
        $this->assertSame(64, strlen($entry->hash));
    }

    public function test_each_entry_links_to_the_previous_one(): void
    {
        $this->recorder()->record('auth.login');
        $this->recorder()->record('variable.created');
        $this->recorder()->record('variable.revealed');

        $entries = AuditLog::query()->orderBy('id')->get();

        $this->assertNull($entries[0]->previous_hash);
        $this->assertSame($entries[0]->hash, $entries[1]->previous_hash);
        $this->assertSame($entries[1]->hash, $entries[2]->previous_hash);
    }

    public function test_the_stored_hash_is_reproducible_from_the_stored_row(): void
    {
        $this->recorder()->record('variable.created', properties: ['key' => 'REDIS_URL']);

        $entry = AuditLog::query()->latest('id')->first();
        $stored = $entry->hash;

        $this->assertSame($stored, $entry->fresh()->computeChainHash());
    }

    public function test_it_records_failures(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->recorder()->failure('pin.failed', properties: ['attempt' => 2]);

        $entry = AuditLog::query()->latest('id')->first();

        $this->assertSame('pin.failed', $entry->event);
        $this->assertTrue($entry->properties['failed']);
        $this->assertSame(2, $entry->properties['attempt']);
    }

    public function test_secret_values_are_encrypted_never_stored_in_plain_text(): void
    {
        $this->recorder()->record(
            'variable.updated',
            properties: ['key' => 'DATABASE_URL'],
            secrets: ['old' => 'postgres://old-value', 'new' => 'postgres://new-value'],
        );

        $rawProperties = DB::table('activity_log')->latest('id')->value('properties');

        $this->assertStringNotContainsString('postgres://old-value', $rawProperties);
        $this->assertStringNotContainsString('postgres://new-value', $rawProperties);

        $entry = AuditLog::query()->latest('id')->first();
        $this->assertSame('postgres://old-value', Crypt::decryptString($entry->properties['secrets']['old']));
    }

    public function test_the_audit_log_rejects_updates_at_the_database_level(): void
    {
        $this->recorder()->record('auth.login');

        $this->expectException(QueryException::class);

        DB::table('activity_log')->latest('id')->limit(1)->update(['event' => 'tampered']);
    }

    public function test_the_audit_log_rejects_deletes_at_the_database_level(): void
    {
        $this->recorder()->record('auth.login');

        $this->expectException(QueryException::class);

        DB::table('activity_log')->latest('id')->limit(1)->delete();
    }
}
