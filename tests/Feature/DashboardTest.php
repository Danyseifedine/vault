<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_fully_onboarded_users_can_visit_the_dashboard()
    {
        $user = User::factory()->onboarded()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_users_who_have_not_finished_onboarding_cannot()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertRedirect(route('onboarding.show'));
    }

    public function test_a_new_account_starts_at_zero()
    {
        $this->actingAs(User::factory()->onboarded()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('organizations', [])
                ->where('stats.organizations', 0)
                ->where('stats.projects', 0)
                ->where('stats.environments', 0)
                ->where('stats.variables', 0),
            );
    }

    public function test_the_totals_describe_what_this_person_can_reach()
    {
        $user = User::factory()->onboarded()->create();
        $organization = Organization::factory()->create(['created_by' => $user->id]);
        $project = Project::factory()->for($organization)->create();
        Variable::factory()->count(3)->for($project)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.organizations', 1)
                ->where('stats.projects', 1)
                ->where('stats.environments', $project->environments()->count())
                ->where('stats.variables', 3),
            );
    }

    /**
     * The counts are part of the access chain, not decoration: a total that
     * includes other people's organizations tells you they exist.
     */
    public function test_the_totals_ignore_organizations_you_were_never_added_to()
    {
        $stranger = User::factory()->onboarded()->create();
        $elsewhere = Organization::factory()->create();
        Variable::factory()->count(2)->for(Project::factory()->for($elsewhere)->create())->create();

        $this->actingAs($stranger)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.organizations', 0)
                ->where('stats.projects', 0)
                ->where('stats.variables', 0),
            );
    }

    public function test_the_dashboard_never_names_a_variable()
    {
        $user = User::factory()->onboarded()->create();
        $project = Project::factory()->for(Organization::factory()->create(['created_by' => $user->id]))->create();
        $variable = Variable::factory()->for($project)->create(['key' => 'STRIPE_SECRET_KEY']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee($variable->key);
    }
}
