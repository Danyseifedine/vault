<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Invitations\SendInvitation;
use App\Actions\Organizations\CreateOrganization;
use App\Actions\Projects\CreateProject;
use App\Actions\Variables\CreateVariable;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Whose activity you may read.
 *
 * The audit log is a record of people, not just of changes: it names who
 * revealed what and when. So it is shut by default - you read your own trail,
 * and only the holder of `audit.view-all` reads everyone's. The organization's
 * creator is seeded that grant like every other; it can be handed out, and it
 * can be taken away.
 */
class AuditVisibilityTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private User $member;

    private Organization $organization;

    private Project $project;

    private Environment $dev;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded()->organizationCreator()->create(['name' => 'Ownie Owner']);
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');
        $this->project = app(CreateProject::class)($this->organization, $this->owner, 'Billing');
        $this->dev = $this->project->environments()->where('slug', 'dev')->firstOrFail();

        $this->member = $this->joinMember($this->organization, User::factory()->onboarded()->create(['name' => 'Memb Member']));
        $this->grantWriter($this->member, $this->dev);
    }

    /** An entry each: the owner's, then the member's own. */
    private function seedBothTrails(): void
    {
        app(CreateVariable::class)($this->project, $this->owner, 'OWNER_MADE_THIS');
        app(CreateVariable::class)($this->project, $this->member, 'MEMBER_MADE_THIS');
    }

    /** @return array<string, mixed> */
    private function propsOfOrganizationPage(User $viewer): array
    {
        return $this->actingAs($viewer)
            ->get("/orgs/{$this->organization->slug}")
            ->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function propsOfProjectPage(User $viewer): array
    {
        return $this->actingAs($viewer)
            ->get("/orgs/{$this->organization->slug}/projects/{$this->project->slug}")
            ->viewData('page')['props'];
    }

    public function test_the_grant_holder_reads_everyones_activity(): void
    {
        $this->seedBothTrails();

        $props = $this->propsOfOrganizationPage($this->owner);
        $actors = collect($props['activity'])->pluck('actor')->unique();

        $this->assertContains('Ownie Owner', $actors);
        $this->assertContains('Memb Member', $actors);
    }

    public function test_a_member_without_the_grant_reads_only_their_own_activity(): void
    {
        $this->seedBothTrails();

        $props = $this->propsOfOrganizationPage($this->member);
        $activity = collect($props['activity']);

        // Their own trail is there, and it is the whole of it.
        $this->assertNotEmpty($activity);
        $this->assertSame(['Memb Member'], $activity->pluck('actor')->unique()->values()->all());
        $this->assertTrue($activity->contains(fn (array $row) => str_contains($row['path'], 'MEMBER_MADE_THIS')));
        $this->assertFalse($activity->contains(fn (array $row) => str_contains($row['path'], 'OWNER_MADE_THIS')));
    }

    public function test_the_audit_table_on_the_organization_page_follows_the_same_rule(): void
    {
        $this->seedBothTrails();

        // The audit table is paged now, so the rows sit under `rows`.
        $rows = collect($this->propsOfOrganizationPage($this->member)['auditLog']['rows']);

        $this->assertNotEmpty($rows);
        $this->assertSame(['Memb Member'], $rows->pluck('actor')->unique()->values()->all());
    }

    public function test_the_project_audit_screen_follows_the_same_rule(): void
    {
        $this->seedBothTrails();

        // Paged now, so the rows sit under `rows` here too.
        $mine = collect($this->propsOfProjectPage($this->member)['auditLog']['rows']);
        $theirs = collect($this->propsOfProjectPage($this->owner)['auditLog']['rows']);

        $this->assertSame(['Memb Member'], $mine->pluck('actor')->unique()->values()->all());
        $this->assertContains('Memb Member', $theirs->pluck('actor')->unique());
        $this->assertContains('Ownie Owner', $theirs->pluck('actor')->unique());
    }

    public function test_granting_the_permission_opens_everyones_activity(): void
    {
        $this->seedBothTrails();
        $this->grant($this->member, Permission::ViewAllActivity, $this->organization);

        $actors = collect($this->propsOfOrganizationPage($this->member)['activity'])
            ->pluck('actor')
            ->unique();

        $this->assertContains('Ownie Owner', $actors);
    }

    public function test_revoking_the_permission_shuts_the_owner_out_of_other_trails(): void
    {
        $this->seedBothTrails();

        Grant::query()
            ->where('user_id', $this->owner->id)
            ->where('permission', Permission::ViewAllActivity->value)
            ->delete();

        $actors = collect($this->propsOfOrganizationPage($this->owner)['activity'])
            ->pluck('actor')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['Ownie Owner'], $actors);
    }

    public function test_inviting_people_is_not_a_licence_to_read_their_trail(): void
    {
        $this->seedBothTrails();
        $this->grant($this->member, Permission::InviteMembers, $this->organization);

        app(SendInvitation::class)($this->organization, $this->owner, 'recruit@example.com');

        $props = $this->propsOfOrganizationPage($this->member);

        $this->assertSame(
            ['Memb Member'],
            collect($props['activity'])->pluck('actor')->unique()->values()->all(),
        );
        $this->assertFalse(
            collect($props['activity'])->contains(fn (array $row) => str_contains($row['path'], 'recruit@example.com')),
        );
    }

    public function test_the_activity_chart_counts_only_the_entries_you_may_read(): void
    {
        foreach (range(1, 6) as $index) {
            app(CreateVariable::class)($this->project, $this->owner, "OWNER_KEY_{$index}");
        }

        app(CreateVariable::class)($this->project, $this->member, 'MEMBER_KEY');

        $series = $this->propsOfOrganizationPage($this->member)['activitySeries'];
        $total = array_sum(array_column($series, 'ok')) + array_sum(array_column($series, 'fail'));

        // One entry: their own. The owner's six are not counted, because even
        // a bare count says how busy someone else's day was.
        $this->assertSame(1, $total);
    }

    public function test_a_project_card_does_not_name_someone_elses_last_action(): void
    {
        app(CreateVariable::class)($this->project, $this->member, 'MEMBER_MADE_THIS');
        // The newest entry in the project belongs to the owner.
        app(CreateVariable::class)($this->project, $this->owner, 'OWNER_MADE_THIS');

        $card = $this->propsOfOrganizationPage($this->member)['projects'][0];

        $this->assertStringNotContainsString('Ownie Owner', (string) $card['lastActivity']);
        $this->assertStringContainsString('Memb Member', (string) $card['lastActivity']);
    }

    public function test_failed_pin_counts_are_not_a_window_into_someone_elses_failures(): void
    {
        app(AuditRecorder::class)->failure(
            'pin.failed',
            properties: ['attempt' => 1],
            scope: AuditScope::make($this->organization->id),
            causer: $this->owner,
        );

        $failedFor = fn (User $viewer) => collect($this->propsOfOrganizationPage($viewer)['health'])
            ->firstWhere('label', 'Failed PINs')['value'];

        $this->assertSame('1', $failedFor($this->owner));
        $this->assertSame('0', $failedFor($this->member));
    }

    public function test_a_guest_is_sent_to_the_login_page_rather_than_a_feed(): void
    {
        $this->get("/orgs/{$this->organization->slug}")->assertRedirect('/login');
        $this->get("/orgs/{$this->organization->slug}/projects/{$this->project->slug}")
            ->assertRedirect('/login');
    }

    public function test_the_permission_is_organization_native_and_offered_at_invite_time(): void
    {
        $this->assertTrue(Permission::ViewAllActivity->isAdministrative());

        // The invite checklist is built from the org-native list, so a new
        // permission that is not there cannot be granted from the UI at all.
        $this->actingAs($this->owner)
            ->get("/orgs/{$this->organization->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('organization.canInvite', true));

        $this->assertContains(
            Permission::ViewAllActivity,
            array_filter(Permission::cases(), fn (Permission $p) => $p->isAdministrative()),
        );
    }
}
