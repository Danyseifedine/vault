<?php

namespace Tests\Feature\Variables;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Sensitivity;
use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The value routes make a variable and an environment siblings, resolved by id
 * and slug with no relation to scope through. Without a containment check, a
 * member who legitimately holds a grant on their OWN environment could name a
 * FOREIGN variable in the URL and have authorization decided against the wrong
 * tenant - writing into another org's variable, injecting a row into its
 * hash-chained audit log, and leaving that variable un-editable in its home
 * project (its "every environment it lives in" set now includes an environment
 * nobody there can reach).
 *
 * The URL segments must belong together before authorization is even asked.
 */
class CrossTenantContainmentTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $attacker;

    private Organization $orgA;

    private Environment $devA;

    private Organization $orgB;

    private Project $projectB;

    private Environment $devB;

    private Variable $variableB;

    protected function setUp(): void
    {
        parent::setUp();

        // Attacker's own tenant, where they hold full access on dev.
        $ownerA = User::factory()->onboarded()->organizationCreator()->create();
        $this->orgA = app(CreateOrganization::class)($ownerA, 'Alpha');
        $projectA = Project::factory()->for($this->orgA)->create(['slug' => 'alpha-app']);
        $this->devA = Environment::factory()->for($projectA)->create(['name' => 'dev', 'slug' => 'dev']);

        $this->attacker = $this->joinMember($this->orgA);
        $this->grantFullAccess($this->attacker, $this->devA);

        // A completely separate tenant the attacker has no membership in.
        $ownerB = User::factory()->onboarded()->organizationCreator()->create();
        $this->orgB = app(CreateOrganization::class)($ownerB, 'Bravo');
        $this->projectB = Project::factory()->for($this->orgB)->create(['slug' => 'bravo-app']);
        $this->devB = Environment::factory()->for($this->projectB)->create(['name' => 'dev', 'slug' => 'dev']);
        $this->variableB = Variable::factory()->for($this->projectB)->create([
            'key' => 'SECRET_B',
            'sensitivity' => Sensitivity::Sensitive,
        ]);
    }

    /**
     * The attacker frames the URL around their OWN org/project/environment but
     * points {variable} at a foreign variable id. Every segment except the
     * variable is theirs, so scope-only authorization would pass.
     *
     * @param  array<int, mixed>  $extra
     */
    private function crossTenantUrl(string $routeName, array $extra = []): string
    {
        return route($routeName, array_merge([
            $this->orgA,
            $this->devA->project,
            $this->variableB,
            $this->devA,
        ], $extra));
    }

    public function test_writing_a_foreign_variable_through_your_own_scope_is_refused(): void
    {
        $before = AuditLog::count();

        $this->actingAs($this->attacker)
            ->put($this->crossTenantUrl('values.update'), ['value' => 'injected'])
            ->assertNotFound();

        // No value row leaked into org B's variable...
        $this->assertNull($this->variableB->fresh()->valueIn($this->devB));
        $this->assertNull($this->variableB->fresh()->valueIn($this->devA));
        // ...and no audit row was injected into anyone's chain.
        $this->assertSame($before, AuditLog::count());
    }

    public function test_revealing_a_foreign_variable_through_your_own_scope_is_refused(): void
    {
        // Fixture only: give the foreign variable a real value to try to steal.
        app(SetVariableValue::class)($this->variableB, $this->devB, $this->attacker, 'plaintext-B');

        $this->actingAs($this->attacker)
            ->postJson($this->crossTenantUrl('values.reveal'), [])
            ->assertNotFound();
    }

    public function test_reading_foreign_history_through_your_own_scope_is_refused(): void
    {
        $this->actingAs($this->attacker)
            ->getJson($this->crossTenantUrl('values.history'))
            ->assertNotFound();
    }

    public function test_the_legitimate_owner_still_writes_normally(): void
    {
        $writer = $this->joinMember($this->orgB);
        $this->grantFullAccess($writer, $this->devB);

        $this->actingAs($writer)
            ->put(
                route('values.update', [$this->orgB, $this->projectB, $this->variableB, $this->devB]),
                ['value' => 'legit-value'],
            )
            ->assertRedirect();

        $this->assertSame('legit-value', $this->variableB->fresh()->valueIn($this->devB)->value);
    }
}
