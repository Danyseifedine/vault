<?php

namespace Tests\Feature\Audit;

use App\Actions\Audit\WipeActivityLog;
use App\Actions\Organizations\CreateOrganization;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AnchorWriter;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Audit\ChainVerifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Emptying an organization's activity log.
 *
 * This is the one place the append-only rule bends, and it does so loudly. The
 * bargain: entries can be removed, but the FACT of their removal cannot. Every
 * wipe leaves a marker naming who did it, how many rows went, and what the
 * chain head was beforehand - and a marker is itself never wiped, so the
 * history of wipes only ever grows.
 */
class WipeActivityLogTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');
    }

    private function record(string $event, ?Organization $organization = null): void
    {
        app(AuditRecorder::class)->record(
            $event,
            properties: ['key' => 'FAKE_KEY'],
            scope: $organization === null
                ? AuditScope::none()
                : AuditScope::make($organization->id),
            causer: $this->owner,
        );
    }

    /** @return array<int, string> */
    private function events(?int $organizationId = null): array
    {
        $query = DB::table('activity_log');

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        return $query->pluck('event')->all();
    }

    private function wipe(User $actor): void
    {
        app(WipeActivityLog::class)($this->organization, $actor);
    }

    // ── The wipe itself ─────────────────────────────────────────────────────

    public function test_a_holder_of_the_permission_empties_the_organizations_log(): void
    {
        $this->record('variable.revealed', $this->organization);
        $this->record('variable.created', $this->organization);

        $this->wipe($this->owner);

        $remaining = $this->events($this->organization->id);

        $this->assertNotContains('variable.revealed', $remaining);
        $this->assertNotContains('variable.created', $remaining);
    }

    public function test_the_wipe_leaves_a_marker_naming_who_did_it_and_how_much_went(): void
    {
        $this->record('variable.revealed', $this->organization);
        $this->record('variable.created', $this->organization);

        // Creating the organization audited itself, so the count is whatever
        // is actually there rather than only what this test wrote.
        $before = AuditLog::query()
            ->forOrganization($this->organization->id)
            ->where('event', '!=', 'audit.wiped')
            ->count();

        $this->wipe($this->owner);

        $marker = AuditLog::query()->where('event', 'audit.wiped')->latest('id')->first();

        $this->assertNotNull($marker);
        $this->assertSame($this->owner->id, $marker->causer_id);
        $this->assertSame($this->organization->id, $marker->organization_id);
        $this->assertSame($before, $marker->properties['removed']);
        $this->assertGreaterThanOrEqual(2, $before);
        $this->assertNotEmpty($marker->properties['previous_head']);
    }

    public function test_a_marker_is_never_itself_wiped(): void
    {
        $this->record('variable.revealed', $this->organization);
        $this->wipe($this->owner);

        $this->record('variable.created', $this->organization);
        $this->wipe($this->owner);

        // Two wipes, two markers - the second did not erase the first.
        $this->assertCount(
            2,
            AuditLog::query()->where('event', 'audit.wiped')->get(),
        );
    }

    public function test_the_second_wipe_does_not_count_the_first_marker_as_removable(): void
    {
        $this->record('variable.revealed', $this->organization);
        $this->wipe($this->owner);

        $this->wipe($this->owner);

        $second = AuditLog::query()->where('event', 'audit.wiped')->latest('id')->first();

        $this->assertSame(0, $second->properties['removed']);
    }

    // ── Blast radius ────────────────────────────────────────────────────────

    public function test_another_organizations_entries_are_untouched(): void
    {
        $stranger = User::factory()->onboarded()->organizationCreator()->create();
        $other = app(CreateOrganization::class)($stranger, 'Other');

        $this->record('variable.revealed', $this->organization);
        $this->record('variable.revealed', $other);

        $this->wipe($this->owner);

        $this->assertContains('variable.revealed', $this->events($other->id));
    }

    public function test_personal_entries_are_untouched(): void
    {
        $this->record('personal.revealed');
        $this->record('variable.revealed', $this->organization);

        $this->wipe($this->owner);

        $personal = DB::table('activity_log')->whereNull('organization_id')->pluck('event')->all();

        $this->assertContains('personal.revealed', $personal);
    }

    // ── The chain survives ──────────────────────────────────────────────────

    public function test_the_chain_still_verifies_after_a_wipe(): void
    {
        $stranger = User::factory()->onboarded()->organizationCreator()->create();
        $other = app(CreateOrganization::class)($stranger, 'Other');

        // Interleaved on purpose: the chain is global, so removing one
        // organization's rows leaves holes among another's.
        $this->record('variable.revealed', $this->organization);
        $this->record('variable.revealed', $other);
        $this->record('variable.created', $this->organization);
        $this->record('variable.created', $other);

        $this->wipe($this->owner);

        $this->assertTrue(app(ChainVerifier::class)->verify()->intact);
    }

    public function test_the_external_anchor_is_rewritten_to_the_new_head(): void
    {
        $this->record('variable.revealed', $this->organization);

        $this->wipe($this->owner);

        $head = AuditLog::query()->orderByDesc('id')->first();
        $anchor = app(AnchorWriter::class)->read();

        $this->assertNotNull($anchor);
        $this->assertSame($head->hash, $anchor['hash']);
    }

    public function test_the_log_is_append_only_again_once_the_wipe_is_done(): void
    {
        $this->record('variable.revealed', $this->organization);
        $this->wipe($this->owner);

        $this->expectException(QueryException::class);

        DB::table('activity_log')->delete();
    }

    // ── Authorization ───────────────────────────────────────────────────────

    public function test_without_the_permission_it_is_refused_and_the_refusal_is_audited(): void
    {
        $this->record('variable.revealed', $this->organization);

        $member = $this->joinMember($this->organization);
        $this->grant($member, Permission::ViewAllActivity, $this->organization);

        try {
            $this->wipe($member);
            $this->fail('a member without audit.wipe must not empty the log');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertContains('variable.revealed', $this->events($this->organization->id));
        $this->assertContains('audit.wipe-denied', $this->events($this->organization->id));
    }

    public function test_reading_everyones_activity_is_not_permission_to_destroy_it(): void
    {
        $member = $this->joinMember($this->organization);
        $this->grant($member, Permission::ViewAllActivity, $this->organization);

        $this->expectException(AuthorizationException::class);

        $this->wipe($member);
    }

    // ── Over HTTP ───────────────────────────────────────────────────────────

    public function test_the_endpoint_wipes_for_a_holder(): void
    {
        $this->record('variable.revealed', $this->organization);

        $this->actingAs($this->owner)
            ->delete(route('activity.destroy', $this->organization))
            ->assertRedirect();

        $this->assertNotContains('variable.revealed', $this->events($this->organization->id));
    }

    public function test_the_endpoint_refuses_a_member_without_the_permission(): void
    {
        $this->record('variable.revealed', $this->organization);
        $member = $this->joinMember($this->organization);

        $this->actingAs($member)
            ->delete(route('activity.destroy', $this->organization))
            ->assertForbidden();

        $this->assertContains('variable.revealed', $this->events($this->organization->id));
    }

    public function test_a_guest_cannot_wipe(): void
    {
        $this->record('variable.revealed', $this->organization);

        $this->delete(route('activity.destroy', $this->organization))
            ->assertRedirect(route('login'));

        $this->assertContains('variable.revealed', $this->events($this->organization->id));
    }

    public function test_the_creator_starts_holding_the_permission(): void
    {
        $this->assertTrue(
            app(AccessResolver::class)
                ->can($this->owner, Permission::WipeActivity, $this->organization),
        );
    }
}
