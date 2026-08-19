<?php

namespace Tests\Feature\Variables;

use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\DeleteVariable;
use App\Actions\Variables\SetVariableValue;
use App\Models\Environment;
use App\Models\Group;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Removing a variable, and filing one under a group.
 *
 * Authorization for these lives in VariablePolicy at the HTTP edge, the same
 * as the rest of the variables domain - these tests are about the behaviour.
 */
class VariableManagementTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Environment $prod;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
        $this->author = User::factory()->onboarded()->create();
    }

    private function variable(string $key = 'DATABASE_URL'): Variable
    {
        return app(CreateVariable::class)($this->project, $this->author, $key);
    }

    private function events(): array
    {
        return DB::table('activity_log')->pluck('event')->all();
    }

    public function test_deleting_a_variable_is_reversible_and_audited(): void
    {
        $variable = $this->variable();

        app(DeleteVariable::class)($variable, $this->author);

        $this->assertSoftDeleted('variables', ['id' => $variable->id]);
        $this->assertContains('variable.deleted', $this->events());
    }

    /**
     * The value must survive the delete: a soft-deleted variable that threw
     * away its secret would be un-restorable, which is not "soft" at all.
     */
    public function test_the_value_survives_a_delete_and_never_appears_in_the_audit_entry(): void
    {
        $variable = $this->variable();
        app(SetVariableValue::class)($variable, $this->prod, $this->author, 'postgres://fake-secret');

        app(DeleteVariable::class)($variable, $this->author);

        $this->assertDatabaseHas('variable_values', ['variable_id' => $variable->id]);

        $properties = DB::table('activity_log')->pluck('properties')->implode(' ');
        $this->assertStringNotContainsString('postgres://fake-secret', $properties);
    }

    public function test_the_audit_entry_records_how_many_environments_were_affected(): void
    {
        $dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
        $variable = $this->variable();

        app(SetVariableValue::class)($variable, $this->prod, $this->author, 'a');
        app(SetVariableValue::class)($variable, $dev, $this->author, 'b');

        app(DeleteVariable::class)($variable, $this->author);

        $entry = DB::table('activity_log')->where('event', 'variable.deleted')->latest('id')->first();

        $this->assertStringContainsString('"environments":2', (string) $entry->properties);
    }

    public function test_a_deleted_key_can_be_created_again(): void
    {
        $first = $this->variable('DATABASE_URL');
        app(DeleteVariable::class)($first, $this->author);

        $second = $this->variable('DATABASE_URL');

        $this->assertNotSame($first->id, $second->id);
    }

    // ── Grouping ────────────────────────────────────────────────────────────

    public function test_a_variable_can_be_filed_under_a_group_when_it_is_created(): void
    {
        $group = Group::create(['project_id' => $this->project->id, 'name' => 'Database']);

        $variable = app(CreateVariable::class)($this->project, $this->author, 'DATABASE_URL', $group->id);

        $this->assertSame($group->id, $variable->group_id);
    }

    /** No group is a real answer, and the screens read it as "ungrouped". */
    public function test_a_variable_without_a_group_is_left_ungrouped(): void
    {
        $this->assertNull($this->variable()->group_id);
    }

    public function test_a_group_from_another_project_cannot_be_used(): void
    {
        $foreign = Group::create([
            'project_id' => Project::factory()->create()->id,
            'name' => 'Database',
        ]);

        $this->expectException(ValidationException::class);

        app(CreateVariable::class)($this->project, $this->author, 'DATABASE_URL', $foreign->id);
    }

    public function test_a_group_that_does_not_exist_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(CreateVariable::class)($this->project, $this->author, 'DATABASE_URL', 9999);
    }
}
