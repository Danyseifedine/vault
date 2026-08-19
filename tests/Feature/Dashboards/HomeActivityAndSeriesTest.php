<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\PersonalVault\CreatePersonalSecret;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The home dashboard's "what you did lately", and the organization
 * dashboard's activity-over-time series behind its chart.
 */
class HomeActivityAndSeriesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->onboarded()->organizationCreator()->create();
    }

    public function test_home_lists_the_users_own_recent_actions(): void
    {
        app(CreateOrganization::class)($this->user, 'Acme');
        app(CreatePersonalSecret::class)($this->user, 'GitHub token', 'ghp_fake_token');

        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard/index')
                ->has('recentActivity', 2)
                ->where('recentActivity.0.action', 'personal secret created')
                ->where('recentActivity.1.action', 'organization created'));
    }

    public function test_home_never_lists_someone_elses_actions(): void
    {
        $other = User::factory()->onboarded()->organizationCreator()->create();
        app(CreateOrganization::class)($other, 'Theirs');

        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentActivity', 0));
    }

    public function test_home_recent_activity_never_contains_a_value(): void
    {
        app(CreatePersonalSecret::class)($this->user, 'GitHub token', 'ghp_fake_token');

        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $this->assertStringNotContainsString('ghp_fake_token', $response->getContent() ?: '');
    }

    public function test_the_org_dashboard_carries_a_fourteen_day_activity_series(): void
    {
        $organization = app(CreateOrganization::class)($this->user, 'Acme');

        app(AuditRecorder::class)->failure(
            'pin.failed',
            properties: ['attempt' => 1],
            scope: AuditScope::make($organization->id),
            causer: $this->user,
        );

        $this->actingAs($this->user)
            ->get("/orgs/{$organization->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('organizations/show')
                ->has('activitySeries', 14)
                ->has('activitySeries.13', fn (AssertableInertia $day) => $day
                    ->has('day')
                    ->where('ok', 1)      // organization.created
                    ->where('fail', 1))); // pin.failed
    }
}
