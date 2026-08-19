<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Permission;
use App\Enums\RevealRequirement;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * A value never rides along in the page prop unless revealing it right there
 * would be free AND permitted.
 *
 * Public was treated as harmless and always inlined. Two ways that leaked: a
 * viewer with variables.view but not variables.reveal got the cleartext anyway,
 * and an environment whose Public policy was deliberately raised to demand a PIN
 * was ignored - the value shipped regardless, with no audit entry, skipping the
 * one door (RevealGuard) that is supposed to decide.
 */
class PublicValueExposureTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private Project $project;

    private Environment $dev;

    private Variable $publicVar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');
        $this->project = Project::factory()->for($this->organization)->create(['slug' => 'lebify']);
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev', 'slug' => 'dev']);

        $this->publicVar = Variable::factory()->for($this->project)->create([
            'key' => 'API_BASE_URL',
            'sensitivity' => Sensitivity::Public,
        ]);

        app(SetVariableValue::class)(
            $this->publicVar,
            $this->dev,
            $this->owner,
            'https://api.fake.example',
        );
    }

    private function setPolicy(Sensitivity $sensitivity, RevealRequirement $requirement): void
    {
        $this->dev->revealPolicies()->updateOrCreate(
            ['sensitivity' => $sensitivity->value],
            ['requirement' => $requirement->value],
        );
    }

    /** @return array<string, mixed> */
    private function variablesFor(User $as): array
    {
        $response = $this->actingAs($as)
            ->get(route('projects.show', [$this->organization, $this->project]));

        $response->assertOk();

        return collect($response->viewData('page')['props']['variables'])
            ->firstWhere('key', 'API_BASE_URL');
    }

    public function test_a_reader_on_an_open_policy_sees_the_public_value_inline(): void
    {
        $reader = $this->joinMember($this->organization);
        $this->grant($reader, [Permission::ViewVariables, Permission::RevealValues], $this->dev);
        $this->setPolicy(Sensitivity::Public, RevealRequirement::None);

        $this->assertSame(
            'https://api.fake.example',
            $this->variablesFor($reader)['values']['dev'],
        );
    }

    public function test_view_without_reveal_never_receives_the_plaintext(): void
    {
        $viewer = $this->joinMember($this->organization);
        // View only - no reveal grant, even though the policy is wide open.
        $this->grant($viewer, Permission::ViewVariables, $this->dev);
        $this->setPolicy(Sensitivity::Public, RevealRequirement::None);

        // Present (so the row shows "set"), but masked - not the value.
        $this->assertSame('', $this->variablesFor($viewer)['values']['dev']);
    }

    public function test_a_public_policy_raised_to_pin_holds_the_value_back(): void
    {
        $reader = $this->joinMember($this->organization);
        $this->grant($reader, [Permission::ViewVariables, Permission::RevealValues], $this->dev);
        // The admin deliberately protected even Public here.
        $this->setPolicy(Sensitivity::Public, RevealRequirement::Pin);

        $this->assertSame('', $this->variablesFor($reader)['values']['dev']);
    }

    public function test_someone_who_cannot_see_the_project_is_refused_outright(): void
    {
        // A member of the org with no grants at all on this project: the page
        // itself is denied, so no value could travel even in principle.
        $outsider = $this->joinMember($this->organization);

        $this->actingAs($outsider)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertForbidden();
    }
}
