<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Organizations\CreateOrganization;
use App\Enums\Permission;
use App\Enums\RevealRequirement;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * What a reveal will COST this viewer, sent with the page.
 *
 * The screen used to guess from the sensitivity label alone: everything asked
 * for a PIN, and 🔴 critical always asked for the account password too. That is
 * the default matrix, not the policy - so an environment configured wide open
 * still demanded a PIN the server was never going to check, and one hardened
 * beyond the defaults asked for too little and got refused after the round trip.
 *
 * The effective requirement now travels with the viewer's own abilities, and
 * only for environments where they actually hold `variables.reveal` - it says
 * nothing to someone who could not read the value anyway.
 */
class RevealRequirementPayloadTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private Project $project;

    private Environment $dev;

    private Environment $prod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');
        $this->project = Project::factory()->for($this->organization)->create(['slug' => 'lebify']);
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev', 'slug' => 'dev']);
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod', 'slug' => 'prod']);
    }

    /** @return array<string, mixed> */
    private function viewer(?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->owner)
            ->get(route('projects.show', [$this->organization, $this->project]));

        $response->assertOk();

        return $response->viewData('page')['props']['viewer'];
    }

    private function setPolicy(Environment $environment, Sensitivity $sensitivity, RevealRequirement $requirement): void
    {
        $environment->revealPolicies()->updateOrCreate(
            ['sensitivity' => $sensitivity->value],
            ['requirement' => $requirement->value],
        );
    }

    public function test_the_seeded_defaults_travel_with_the_page(): void
    {
        $policies = $this->viewer()['environments']['dev']['policies'];

        $this->assertSame('none', $policies['public']);
        $this->assertSame('pin', $policies['sensitive']);
        $this->assertSame('pin_password', $policies['critical']);
    }

    /** The exact case that started this: dev opened right up. */
    public function test_an_environment_set_wide_open_reports_no_cost_at_all(): void
    {
        foreach (Sensitivity::cases() as $sensitivity) {
            $this->setPolicy($this->dev, $sensitivity, RevealRequirement::None);
        }

        $policies = $this->viewer()['environments']['dev']['policies'];

        $this->assertEqualsCanonicalizing(
            ['public' => 'none', 'sensitive' => 'none', 'critical' => 'none'],
            $policies,
        );
    }

    public function test_each_environment_reports_its_own_matrix(): void
    {
        foreach (Sensitivity::cases() as $sensitivity) {
            $this->setPolicy($this->dev, $sensitivity, RevealRequirement::None);
        }

        $this->setPolicy($this->prod, Sensitivity::Public, RevealRequirement::Pin);

        $environments = $this->viewer()['environments'];

        $this->assertSame('none', $environments['dev']['policies']['critical']);
        $this->assertSame('pin', $environments['prod']['policies']['public']);
    }

    public function test_tightening_beyond_the_defaults_is_reported_too(): void
    {
        $this->setPolicy($this->prod, Sensitivity::Public, RevealRequirement::PinAndPassword);

        $this->assertSame(
            'pin_password',
            $this->viewer()['environments']['prod']['policies']['public'],
        );
    }

    public function test_someone_without_reveal_here_is_told_nothing_about_the_cost(): void
    {
        $member = $this->joinMember($this->organization);
        // Sees the environment, but may not read a value in it.
        $this->grant($member, Permission::ViewVariables, $this->dev);

        $dev = $this->viewer($member)['environments']['dev'];

        $this->assertFalse($dev['reveal']);
        $this->assertNull($dev['policies']);
    }

    public function test_a_reader_is_told_the_cost_only_where_they_hold_reveal(): void
    {
        $member = $this->joinMember($this->organization);
        $this->grant($member, [Permission::ViewVariables, Permission::RevealValues], $this->dev);
        $this->grant($member, Permission::ViewVariables, $this->prod);

        $environments = $this->viewer($member)['environments'];

        $this->assertIsArray($environments['dev']['policies']);
        $this->assertNull($environments['prod']['policies']);
    }
}
