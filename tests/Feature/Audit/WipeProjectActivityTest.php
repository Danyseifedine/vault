<?php

namespace Tests\Feature\Audit;

use App\Actions\Audit\WipeActivityLog;
use App\Actions\Organizations\CreateOrganization;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use App\Services\Audit\ChainVerifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Emptying ONE project's audit log.
 *
 * Same bargain as the organization wipe, narrower blast radius: the marker is
 * still written, still names the project, and still cannot itself be wiped.
 * `audit.wipe` is organization-scoped, so a project wipe takes the same grant -
 * a narrower target, never a narrower permission.
 */
class WipeProjectActivityTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private Project $project;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');
        $this->project = Project::factory()->for($this->organization)->create(['slug' => 'lebify']);
        $this->other = Project::factory()->for($this->organization)->create(['slug' => 'other']);
    }

    private function record(string $event, ?Project $project = null): void
    {
        app(AuditRecorder::class)->record(
            $event,
            properties: ['key' => 'FAKE'],
            scope: AuditScope::make(
                $this->organization->id,
                $project?->id,
            ),
            causer: $this->owner,
        );
    }

    private function wipe(User $actor, ?Project $project = null): int
    {
        return app(WipeActivityLog::class)(
            $this->organization,
            $actor,
            $project ?? $this->project,
        );
    }

    /** @return array<int, string> */
    private function eventsOf(?Project $project): array
    {
        return DB::table('activity_log')
            ->where('project_id', $project?->id)
            ->pluck('event')
            ->all();
    }

    public function test_it_empties_only_that_project(): void
    {
        $this->record('variable.revealed', $this->project);
        $this->record('variable.revealed', $this->other);
        $this->record('members.invited');

        $this->wipe($this->owner);

        $this->assertNotContains('variable.revealed', $this->eventsOf($this->project));
        $this->assertContains('variable.revealed', $this->eventsOf($this->other));
        // Organization-level entries carry no project and are not this wipe's business.
        $this->assertContains('members.invited', $this->eventsOf(null));
    }

    public function test_the_marker_names_the_project_and_stays_on_the_record(): void
    {
        $this->record('variable.revealed', $this->project);

        $removed = $this->wipe($this->owner);

        $marker = AuditLog::query()->where('event', 'audit.wiped')->latest('id')->first();

        $this->assertSame(1, $removed);
        $this->assertNotNull($marker);
        $this->assertSame($this->project->id, $marker->project_id);
        $this->assertSame($this->project->name, $marker->properties['project']);
        $this->assertSame($this->owner->id, $marker->causer_id);
    }

    public function test_a_marker_survives_a_later_wipe_of_the_same_project(): void
    {
        $this->record('variable.revealed', $this->project);
        $this->wipe($this->owner);
        $this->wipe($this->owner);

        $this->assertCount(
            2,
            AuditLog::query()->where('event', 'audit.wiped')->get(),
        );
    }

    public function test_an_organization_wipe_also_clears_its_projects(): void
    {
        $this->record('variable.revealed', $this->project);
        $this->record('variable.revealed', $this->other);

        // No project argument: the whole organization, projects included.
        app(WipeActivityLog::class)($this->organization, $this->owner);

        $this->assertNotContains('variable.revealed', $this->eventsOf($this->project));
        $this->assertNotContains('variable.revealed', $this->eventsOf($this->other));
    }

    public function test_the_chain_still_verifies_after_a_project_wipe(): void
    {
        $this->record('variable.revealed', $this->project);
        $this->record('variable.revealed', $this->other);
        $this->record('variable.created', $this->project);
        $this->record('variable.created', $this->other);

        $this->wipe($this->owner);

        $this->assertTrue(app(ChainVerifier::class)->verify()->intact);
    }

    public function test_a_project_from_another_organization_is_refused(): void
    {
        $stranger = User::factory()->onboarded()->organizationCreator()->create();
        $otherOrg = app(CreateOrganization::class)($stranger, 'Other');
        $foreign = Project::factory()->for($otherOrg)->create();

        $this->record('variable.revealed', $foreign);

        $this->expectException(AuthorizationException::class);

        app(WipeActivityLog::class)($this->organization, $this->owner, $foreign);
    }

    public function test_without_the_permission_it_is_refused_and_audited(): void
    {
        $this->record('variable.revealed', $this->project);

        $member = $this->joinMember($this->organization);
        $this->grant($member, Permission::ViewAllActivity, $this->organization);

        try {
            $this->wipe($member);
            $this->fail('a member without audit.wipe must not empty a project log');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertContains('variable.revealed', $this->eventsOf($this->project));
        $this->assertContains('audit.wipe-denied', $this->eventsOf($this->project));
    }

    // ── Over HTTP ───────────────────────────────────────────────────────────

    public function test_the_endpoint_wipes_for_a_holder(): void
    {
        $this->record('variable.revealed', $this->project);

        $this->actingAs($this->owner)
            ->delete(route('projects.activity.destroy', [$this->organization, $this->project]))
            ->assertRedirect();

        $this->assertNotContains('variable.revealed', $this->eventsOf($this->project));
    }

    public function test_the_endpoint_refuses_a_member_without_the_permission(): void
    {
        $this->record('variable.revealed', $this->project);
        $member = $this->joinMember($this->organization);

        $this->actingAs($member)
            ->delete(route('projects.activity.destroy', [$this->organization, $this->project]))
            ->assertForbidden();

        $this->assertContains('variable.revealed', $this->eventsOf($this->project));
    }

    public function test_a_guest_cannot_wipe_a_project_log(): void
    {
        $this->record('variable.revealed', $this->project);

        $this->delete(route('projects.activity.destroy', [$this->organization, $this->project]))
            ->assertRedirect(route('login'));

        $this->assertContains('variable.revealed', $this->eventsOf($this->project));
    }
}
