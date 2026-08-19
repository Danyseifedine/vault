<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Invitations\SendInvitation;
use App\Actions\Organizations\CreateOrganization;
use App\Actions\Projects\CreateProject;
use App\Actions\Variables\CreateVariable;
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
 * What each feed may show each viewer - pinned, so a dropped filter fails
 * loudly instead of quietly widening an audience.
 */
class FeedVisibilityTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private User $member;

    private Organization $organization;

    private Project $reachable;

    private Project $hidden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');

        $this->reachable = app(CreateProject::class)($this->organization, $this->owner, 'Open');
        $this->hidden = app(CreateProject::class)($this->organization, $this->owner, 'Secret Ops');

        $this->member = $this->joinMember($this->organization);
        $this->grantReader($this->member, $this->reachable->environments()->firstOrFail());
    }

    public function test_the_activity_series_does_not_count_projects_the_viewer_cannot_reach(): void
    {
        // Ten entries in the hidden project; counting them would leak the
        // hidden project's activity VOLUME even with every name stripped.
        foreach (range(1, 10) as $i) {
            app(AuditRecorder::class)->record(
                'variable.created',
                properties: ['key' => "HIDDEN_{$i}"],
                scope: AuditScope::make($this->organization->id, $this->hidden->id),
                causer: $this->owner,
            );
        }

        $response = $this->actingAs($this->member)
            ->get("/orgs/{$this->organization->slug}");

        $response->assertInertia(function (AssertableInertia $page) {
            $series = $page->toArray()['props']['activitySeries'];
            $total = array_sum(array_column($series, 'ok'))
                + array_sum(array_column($series, 'fail'));

            // Only entries the member may see are counted - the ten hidden
            // ones are not among them.
            $this->assertLessThan(10, $total);
        });
    }

    public function test_org_project_cards_exclude_projects_the_member_cannot_reach(): void
    {
        $this->actingAs($this->member)
            ->get("/orgs/{$this->organization->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects', 1)
                ->where('projects.0.name', 'Open'));
    }

    public function test_the_owner_still_sees_every_project_card(): void
    {
        $this->actingAs($this->owner)
            ->get("/orgs/{$this->organization->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects', 2));
    }

    public function test_org_scoped_entries_with_emails_are_not_shown_to_plain_members(): void
    {
        app(SendInvitation::class)(
            $this->organization,
            $this->owner,
            'recruit@example.com',
        );

        $response = $this->actingAs($this->member)
            ->get("/orgs/{$this->organization->slug}");

        $this->assertStringNotContainsString(
            'recruit@example.com',
            $response->getContent() ?: '',
        );
    }

    public function test_home_recent_activity_forgets_organizations_you_left(): void
    {
        app(CreateVariable::class)($this->reachable, $this->owner, 'LEFT_BEHIND_KEY');

        // The owner leaves their own org (simulated by detaching membership).
        $this->organization->members()->detach($this->owner->id);

        $response = $this->actingAs($this->owner)->get(route('dashboard'));

        $this->assertStringNotContainsString(
            'LEFT_BEHIND_KEY',
            $response->getContent() ?: '',
        );
    }

    public function test_the_personal_trail_excludes_the_owners_own_org_actions(): void
    {
        app(CreateVariable::class)($this->reachable, $this->owner, 'ORG_ONLY_KEY');

        $this->actingAs($this->owner)
            ->get('/personal')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activity', 0));
    }

    public function test_project_audit_rows_hide_environments_the_viewer_cannot_reach(): void
    {
        $prod = $this->reachable->environments()->where('slug', 'prod')->firstOrFail();

        app(AuditRecorder::class)->record(
            'reveal.granted',
            properties: ['key' => 'PROD_ONLY_KEY', 'environment' => $prod->slug],
            scope: AuditScope::make($this->organization->id, $this->reachable->id),
            causer: $this->owner,
        );

        // The member holds read on ONE environment of this project, not prod.
        $response = $this->actingAs($this->member)
            ->get("/orgs/{$this->organization->slug}/projects/{$this->reachable->slug}");

        $this->assertStringNotContainsString(
            'PROD_ONLY_KEY',
            $response->getContent() ?: '',
        );
    }
}
