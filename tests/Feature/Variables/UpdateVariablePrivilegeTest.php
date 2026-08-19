<?php

namespace Tests\Feature\Variables;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Projects\CreateProject;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Editing a variable's definition - key, sensitivity, labels - reaches every
 * environment at once, exactly like deleting it. So it takes write rights in
 * every environment the variable lives in: write on dev must not be able to
 * relabel a prod-critical variable to public and strip its PIN requirement.
 */
class UpdateVariablePrivilegeTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private User $devWriter;

    private Project $project;

    private Variable $variable;

    private Environment $dev;

    private Environment $prod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $organization = app(CreateOrganization::class)($this->owner, 'Acme');
        $this->project = app(CreateProject::class)($organization, $this->owner, 'API');

        $this->dev = $this->project->environments()->where('slug', 'dev')->firstOrFail();
        $this->prod = $this->project->environments()->where('slug', 'prod')->firstOrFail();

        $this->variable = app(CreateVariable::class)(
            $this->project,
            $this->owner,
            'STRIPE_SECRET_KEY',
            sensitivity: Sensitivity::Critical,
        );

        // A value in dev AND prod - the definition now spans both.
        app(SetVariableValue::class)($this->variable, $this->dev, $this->owner, 'sk_test_fake_dev');
        app(SetVariableValue::class)($this->variable, $this->prod, $this->owner, 'sk_live_fake_prod');

        $this->devWriter = $this->joinMember($organization);
        $this->grantWriter($this->devWriter, $this->dev);
    }

    private function url(): string
    {
        return "/orgs/{$this->project->organization->slug}/projects/{$this->project->slug}/variables/{$this->variable->id}";
    }

    public function test_write_on_dev_alone_cannot_relabel_a_variable_living_in_prod(): void
    {
        $this->actingAs($this->devWriter)
            ->patch($this->url(), [
                'key' => 'STRIPE_SECRET_KEY',
                'sensitivity' => 'public',
                'change_safety' => 'safe',
            ])
            ->assertForbidden();

        $this->assertSame(
            Sensitivity::Critical,
            $this->variable->fresh()->sensitivity,
        );
    }

    public function test_write_everywhere_the_variable_lives_may_update_it(): void
    {
        $this->grantWriter($this->devWriter, $this->prod);

        $this->actingAs($this->devWriter)
            ->patch($this->url(), [
                'key' => 'STRIPE_SECRET_KEY',
                'sensitivity' => 'sensitive',
                'change_safety' => 'safe',
            ])
            ->assertRedirect();

        $this->assertSame(
            Sensitivity::Sensitive,
            $this->variable->fresh()->sensitivity,
        );
        $this->assertDatabaseHas('activity_log', ['event' => 'variable.updated']);
    }

    public function test_the_owner_may_update_regardless(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->url(), [
                'key' => 'STRIPE_SECRET_KEY',
                'sensitivity' => 'sensitive',
                'change_safety' => 'breaking',
            ])
            ->assertRedirect();

        $this->assertSame(
            Sensitivity::Sensitive,
            $this->variable->fresh()->sensitivity,
        );
    }

    public function test_the_update_response_never_carries_a_value(): void
    {
        $response = $this->actingAs($this->owner)
            ->from($this->url())
            ->patch($this->url(), [
                'key' => 'STRIPE_SECRET_KEY',
                'sensitivity' => 'sensitive',
                'change_safety' => 'safe',
            ]);

        $this->assertStringNotContainsString('sk_live_fake_prod', $response->getContent() ?: '');
        $this->assertStringNotContainsString('sk_test_fake_dev', $response->getContent() ?: '');
    }
}
