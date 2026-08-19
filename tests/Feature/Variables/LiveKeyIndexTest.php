<?php

namespace Tests\Feature\Variables;

use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\DeleteVariable;
use App\Models\Project;
use App\Models\User;
use App\Services\Variables\LiveKeyIndex;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Key uniqueness must survive a delete.
 *
 * Tested per driver as well as end to end, because the SQLite and MySQL
 * expressions are completely different and only one of them runs in CI.
 */
class LiveKeyIndexTest extends TestCase
{
    use RefreshDatabase;

    private const DRIVERS = ['sqlite', 'mysql', 'mariadb'];

    public function test_every_driver_we_run_on_has_a_live_key_index(): void
    {
        foreach (self::DRIVERS as $driver) {
            $sql = implode(' ', LiveKeyIndex::create($driver));

            $this->assertStringContainsString('UNIQUE INDEX', $sql, "{$driver} has no unique index");
            $this->assertStringContainsString('deleted_at', $sql, "{$driver} does not exclude deleted rows");
        }
    }

    public function test_an_unsupported_driver_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);

        LiveKeyIndex::create('pgsql');
    }

    public function test_dropping_is_defined_for_every_supported_driver(): void
    {
        foreach (self::DRIVERS as $driver) {
            $this->assertNotEmpty(LiveKeyIndex::drop($driver));
        }
    }

    public function test_two_live_variables_cannot_share_a_key_in_one_project(): void
    {
        $project = Project::factory()->create();
        $author = User::factory()->onboarded()->create();

        app(CreateVariable::class)($project, $author, 'DATABASE_URL');

        $this->expectException(QueryException::class);

        app(CreateVariable::class)($project, $author, 'DATABASE_URL');
    }

    public function test_the_same_key_is_free_again_once_the_variable_is_deleted(): void
    {
        $project = Project::factory()->create();
        $author = User::factory()->onboarded()->create();

        $first = app(CreateVariable::class)($project, $author, 'DATABASE_URL');
        app(DeleteVariable::class)($first, $author);

        $second = app(CreateVariable::class)($project, $author, 'DATABASE_URL');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('DATABASE_URL', $second->key);
    }

    public function test_deleting_twice_still_leaves_only_one_live_key(): void
    {
        $project = Project::factory()->create();
        $author = User::factory()->onboarded()->create();

        $first = app(CreateVariable::class)($project, $author, 'API_KEY');
        app(DeleteVariable::class)($first, $author);

        $second = app(CreateVariable::class)($project, $author, 'API_KEY');
        app(DeleteVariable::class)($second, $author);

        $third = app(CreateVariable::class)($project, $author, 'API_KEY');

        $this->assertSame(1, $project->variables()->count());
        $this->assertSame($third->id, $project->variables()->first()->id);
    }

    public function test_sibling_projects_may_each_define_the_same_key(): void
    {
        $author = User::factory()->onboarded()->create();
        $here = Project::factory()->create();
        $there = Project::factory()->create();

        app(CreateVariable::class)($here, $author, 'DATABASE_URL');
        $other = app(CreateVariable::class)($there, $author, 'DATABASE_URL');

        $this->assertSame('DATABASE_URL', $other->key);
    }
}
