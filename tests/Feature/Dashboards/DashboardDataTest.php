<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

class DashboardDataTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Project $project;

    private Environment $dev;

    private Environment $prod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->organization->members()->first();
        $this->project = Project::factory()->for($this->organization)->create(['name' => 'lebify']);
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
    }

    private function seedVariable(string $key, Sensitivity $sensitivity, string $value, ?Environment $environment = null): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, $key, sensitivity: $sensitivity);
        app(SetVariableValue::class)($variable, $environment ?? $this->prod, $this->owner, $value);
    }

    public function test_the_organization_dashboard_matches_the_frontend_contract(): void
    {
        $this->seedVariable('DATABASE_URL', Sensitivity::Critical, 'postgres://fake');

        $this->actingAs($this->owner)
            ->get(route('organizations.show', $this->organization))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('organizations/show')
                ->where('organization.slug', $this->organization->slug)
                ->has('projects.0', fn ($project) => $project
                    ->where('slug', 'lebify')
                    ->where('envs', 2)
                    ->where('vars', 1)
                    ->has('mix')
                    ->has('missing')
                    ->etc())
                ->has('members')
                ->has('invites')
                ->has('auditLog')
                ->has('health', 4));
    }

    public function test_the_project_dashboard_matches_the_frontend_contract(): void
    {
        $this->seedVariable('LOG_LEVEL', Sensitivity::Public, 'info');

        $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/show')
                ->where('project.slug', 'lebify')
                ->where('project.organizationSlug', $this->organization->slug)
                ->has('environments', 2)
                ->has('variables.0', fn ($variable) => $variable
                    ->where('key', 'LOG_LEVEL')
                    ->where('sensitivity', 'public')
                    ->has('values')
                    ->has('tags')
                    ->etc())
                ->has('auditLog'));
    }

    /**
     * The active tab is resolved SERVER-side from the URL, so the first render
     * already stands on the right screen - a client that guesses and corrects
     * itself flashes the wrong tab on every refresh.
     */
    public function test_the_active_screen_rides_in_the_url_and_is_validated(): void
    {
        $this->actingAs($this->owner)
            ->get(route('organizations.show', [$this->organization, 'screen' => 'tags']))
            ->assertInertia(fn ($page) => $page->where('screen', 'tags'));

        $this->actingAs($this->owner)
            ->get(route('organizations.show', $this->organization))
            ->assertInertia(fn ($page) => $page->where('screen', 'home'));

        $this->actingAs($this->owner)
            ->get(route('organizations.show', [$this->organization, 'screen' => 'nonsense']))
            ->assertInertia(fn ($page) => $page->where('screen', 'home'));

        $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project, 'screen' => 'settings']))
            ->assertInertia(fn ($page) => $page->where('screen', 'settings'));

        $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project, 'screen' => 'nope']))
            ->assertInertia(fn ($page) => $page->where('screen', 'overview'));
    }

    public function test_only_public_values_ever_reach_the_browser(): void
    {
        $this->seedVariable('LOG_LEVEL', Sensitivity::Public, 'info');
        $this->seedVariable('DATABASE_URL', Sensitivity::Critical, 'postgres://super-secret');
        $this->seedVariable('SMTP_PASSWORD', Sensitivity::Sensitive, 'mail-secret');

        $response = $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project]));

        $body = $response->getContent();

        // The harmless one is present; the dangerous ones never left the server.
        $this->assertStringContainsString('LOG_LEVEL', $body);
        $this->assertStringNotContainsString('postgres://super-secret', $body);
        $this->assertStringNotContainsString('mail-secret', $body);
    }

    public function test_a_non_member_cannot_see_the_organization(): void
    {
        $outsider = User::factory()->onboarded()->create();

        $this->actingAs($outsider)
            ->get(route('organizations.show', $this->organization))
            ->assertForbidden();
    }

    public function test_a_member_without_environment_access_cannot_open_the_project(): void
    {
        $member = $this->joinMember($this->organization);

        $this->actingAs($member)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertForbidden();
    }

    public function test_a_project_cannot_be_reached_through_the_wrong_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        $this->fullManager($otherOrganization, $this->owner);

        $this->actingAs($this->owner)
            ->get(route('projects.show', [$otherOrganization, $this->project]))
            ->assertNotFound();
    }

    public function test_activity_is_filtered_to_projects_the_viewer_can_reach(): void
    {
        $this->seedVariable('SECRET_KEY', Sensitivity::Critical, 'fake');

        $hidden = Project::factory()->for($this->organization)->create(['name' => 'off-limits']);
        $hiddenEnvironment = Environment::factory()->for($hidden)->create(['name' => 'prod']);
        $hiddenVariable = app(CreateVariable::class)($hidden, $this->owner, 'HIDDEN_KEY');
        app(SetVariableValue::class)($hiddenVariable, $hiddenEnvironment, $this->owner, 'nope');

        $member = $this->joinMember($this->organization);
        $this->grantReader($member, $this->prod);

        $response = $this->actingAs($member)->get(route('organizations.show', $this->organization));

        $this->assertStringNotContainsString('HIDDEN_KEY', $response->getContent());
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('organizations.show', $this->organization))->assertRedirect(route('login'));
    }
}
