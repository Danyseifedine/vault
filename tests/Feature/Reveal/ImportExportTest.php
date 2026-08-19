<?php

namespace Tests\Feature\Reveal;

use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\ExportEnvFile;
use App\Actions\Variables\ImportEnvFile;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Permission;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Env\EnvParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Project $project;

    private Environment $prod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->organization->members()->first();
        $this->project = Project::factory()->for($this->organization)->create();
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
    }

    private function seedVariable(string $key, string $value): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, $key);

        app(SetVariableValue::class)($variable, $this->prod, $this->owner, $value);
    }

    public function test_export_produces_a_usable_env_file(): void
    {
        $this->seedVariable('LOG_LEVEL', 'info');
        $this->seedVariable('APP_NAME', 'The Vault');

        $contents = app(ExportEnvFile::class)($this->prod, $this->owner);

        $this->assertStringContainsString('LOG_LEVEL=info', $contents);
        // Values with spaces must come back quoted or the file is broken.
        $this->assertStringContainsString('APP_NAME="The Vault"', $contents);

        $reparsed = app(EnvParser::class)->parse($contents);
        $this->assertSame('The Vault', $reparsed['APP_NAME']);
    }

    /** Only this environment's values - a sibling environment is not in the file. */
    public function test_export_carries_one_environment_and_no_other(): void
    {
        $dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');

        app(SetVariableValue::class)($variable, $dev, $this->owner, 'postgres://dev-fake');
        $this->seedVariable('LOG_LEVEL', 'info');

        $contents = app(ExportEnvFile::class)($this->prod, $this->owner);

        $this->assertStringContainsString('LOG_LEVEL=info', $contents);
        $this->assertStringNotContainsString('postgres://dev-fake', $contents);
    }

    public function test_export_is_audited(): void
    {
        $this->seedVariable('LOG_LEVEL', 'info');

        app(ExportEnvFile::class)($this->prod, $this->owner);

        $this->assertDatabaseHas('activity_log', ['event' => 'export.generated']);
    }

    public function test_export_is_refused_without_permission(): void
    {
        $stranger = $this->joinMember($this->organization);

        $this->expectException(AuthorizationException::class);

        try {
            app(ExportEnvFile::class)($this->prod, $stranger);
        } finally {
            $this->assertDatabaseHas('activity_log', ['event' => 'export.denied']);
        }
    }

    public function test_import_creates_variables_and_guesses_sensitivity(): void
    {
        $result = app(ImportEnvFile::class)($this->prod, $this->owner, <<<'ENV'
            # a comment that should be skipped
            DATABASE_PASSWORD=fake-password
            STRIPE_KEY=sk_test_fake
            LOG_LEVEL=debug

            EMPTY_VALUE=
            ENV);

        $this->assertSame(4, $result['created']);

        $variables = $this->project->variables()->get()->keyBy('key');

        $this->assertSame(Sensitivity::Critical, $variables['DATABASE_PASSWORD']->sensitivity);
        // A Stripe key IS a secret - key says _KEY and the value is an sk_test_
        // token, so the smart classifier lands it on Critical, not Sensitive.
        $this->assertSame(Sensitivity::Critical, $variables['STRIPE_KEY']->sensitivity);
        $this->assertSame(Sensitivity::Public, $variables['LOG_LEVEL']->sensitivity);
        $this->assertSame('debug', $variables['LOG_LEVEL']->valueIn($this->prod)->value);
        $this->assertSame('', $variables['EMPTY_VALUE']->valueIn($this->prod)->value);
    }

    public function test_import_files_new_variables_into_guessed_groups(): void
    {
        app(ImportEnvFile::class)($this->prod, $this->owner, <<<'ENV'
            DATABASE_URL=postgres://localhost/app
            DB_HOST=localhost
            STRIPE_SECRET=sk_test_fake
            LONELY=1
            ENV);

        $variables = $this->project->variables()->with('group')->get()->keyBy('key');

        // Provider-mapped: both database keys land in one created "Database".
        $this->assertSame('Database', $variables['DATABASE_URL']->group?->name);
        $this->assertSame('Database', $variables['DB_HOST']->group?->name);
        $this->assertSame($variables['DATABASE_URL']->group_id, $variables['DB_HOST']->group_id);
        $this->assertSame('Payments', $variables['STRIPE_SECRET']->group?->name);
        // No confident home, no shared prefix: left ungrouped, not forced.
        $this->assertNull($variables['LONELY']->group_id);
    }

    public function test_import_leaves_new_variables_ungrouped_without_groups_manage(): void
    {
        $importer = $this->joinMember($this->organization);
        // Import here, but NOT groups.manage - grouping degrades, import runs.
        $this->grant($importer, [Permission::ViewVariables, Permission::CreateVariables, Permission::UpdateVariables, Permission::ImportEnv], $this->prod);

        app(ImportEnvFile::class)($this->prod, $importer, 'DATABASE_URL=postgres://localhost/app');

        $this->assertNull($this->project->variables()->where('key', 'DATABASE_URL')->first()->group_id);
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_importing_a_known_key_updates_it_instead_of_duplicating(): void
    {
        $this->seedVariable('LOG_LEVEL', 'info');

        $result = app(ImportEnvFile::class)($this->prod, $this->owner, 'LOG_LEVEL=debug');

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseCount('variables', 1);

        $value = $this->project->variables()->first()->valueIn($this->prod);
        $this->assertSame('debug', $value->value);
        $this->assertSame(2, $value->version, 'the previous value is kept as history');
    }

    public function test_import_handles_quotes_and_duplicate_keys(): void
    {
        app(ImportEnvFile::class)($this->prod, $this->owner, <<<'ENV'
            QUOTED="a value with spaces"
            SINGLE='single quoted'
            DUPLICATE=first
            DUPLICATE=second
            ENV);

        $variables = $this->project->variables()->get()->keyBy('key');

        $this->assertSame('a value with spaces', $variables['QUOTED']->valueIn($this->prod)->value);
        $this->assertSame('single quoted', $variables['SINGLE']->valueIn($this->prod)->value);
        // Last one wins, exactly as dotenv itself resolves duplicates.
        $this->assertSame('second', $variables['DUPLICATE']->valueIn($this->prod)->value);
    }

    public function test_import_is_refused_without_permission(): void
    {
        // The read bundle carries view/reveal/export - never variables.import.
        $reader = $this->joinMember($this->organization);
        $this->grantReader($reader, $this->prod);

        $this->expectException(AuthorizationException::class);

        try {
            app(ImportEnvFile::class)($this->prod, $reader, 'LOG_LEVEL=debug');
        } finally {
            $this->assertDatabaseHas('activity_log', ['event' => 'import.denied']);
            $this->assertDatabaseCount('variables', 0);
        }
    }
}
