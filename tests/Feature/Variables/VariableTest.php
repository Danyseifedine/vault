<?php

namespace Tests\Feature\Variables;

use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\RollbackVariableValue;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use App\Services\Env\CompletenessService;
use App\Services\Env\DiffService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VariableTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Environment $dev;

    private Environment $prod;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
        $this->author = User::factory()->onboarded()->create();
    }

    private function createVariable(string $key, Sensitivity $sensitivity = Sensitivity::Sensitive): Variable
    {
        return app(CreateVariable::class)($this->project, $this->author, $key, sensitivity: $sensitivity);
    }

    private function setValue(Variable $variable, Environment $environment, string $value): void
    {
        app(SetVariableValue::class)($variable, $environment, $this->author, $value);
    }

    public function test_values_are_encrypted_at_rest(): void
    {
        $variable = $this->createVariable('DATABASE_URL');
        $this->setValue($variable, $this->prod, 'postgres://real-looking-but-fake');

        $stored = DB::table('variable_values')->latest('id')->value('value');

        // A database dump on its own must leak nothing.
        $this->assertStringNotContainsString('postgres://real-looking-but-fake', $stored);
        $this->assertSame('postgres://real-looking-but-fake', Crypt::decryptString($stored));
        $this->assertSame('postgres://real-looking-but-fake', $variable->valueIn($this->prod)->value);
    }

    public function test_a_value_never_appears_in_a_serialized_model(): void
    {
        $variable = $this->createVariable('STRIPE_SECRET_KEY', Sensitivity::Critical);
        $this->setValue($variable, $this->prod, 'sk_test_fake_secret');

        $serialized = json_encode($variable->valueIn($this->prod)->toArray());

        $this->assertStringNotContainsString('sk_test_fake_secret', $serialized);
        $this->assertStringNotContainsString('value', $serialized);
    }

    public function test_a_key_is_unique_within_a_project(): void
    {
        $this->createVariable('LOG_LEVEL');

        $this->expectException(QueryException::class);

        $this->createVariable('LOG_LEVEL');
    }

    public function test_the_same_key_may_exist_in_another_project(): void
    {
        $this->createVariable('LOG_LEVEL');

        $other = Project::factory()->create();
        $twin = app(CreateVariable::class)($other, $this->author, 'LOG_LEVEL');

        $this->assertSame('LOG_LEVEL', $twin->key);
        $this->assertDatabaseCount('variables', 2);
    }

    public function test_the_same_key_holds_different_values_per_environment(): void
    {
        $variable = $this->createVariable('API_URL');

        $this->setValue($variable, $this->dev, 'http://localhost:8000');
        $this->setValue($variable, $this->prod, 'https://api.example.test');

        $this->assertSame('http://localhost:8000', $variable->valueIn($this->dev)->value);
        $this->assertSame('https://api.example.test', $variable->valueIn($this->prod)->value);
    }

    public function test_updating_a_value_records_a_new_version(): void
    {
        $variable = $this->createVariable('REDIS_URL');

        $this->setValue($variable, $this->prod, 'rediss://first');
        $this->setValue($variable, $this->prod, 'rediss://second');
        $this->setValue($variable, $this->prod, 'rediss://third');

        $value = $variable->valueIn($this->prod);

        $this->assertSame(3, $value->version);
        $this->assertSame('rediss://third', $value->value);
        $this->assertCount(3, $value->versions);
        $this->assertSame([3, 2, 1], $value->versions->pluck('version')->all());
    }

    public function test_rolling_back_writes_a_new_version_rather_than_erasing_history(): void
    {
        $variable = $this->createVariable('JWT_SIGNING_KEY');

        $this->setValue($variable, $this->prod, 'first-key');
        $this->setValue($variable, $this->prod, 'second-key');

        $value = $variable->valueIn($this->prod);
        $firstVersion = $value->versions()->where('version', 1)->first();

        app(RollbackVariableValue::class)($value, $firstVersion, $this->author);

        $value->refresh();

        $this->assertSame('first-key', $value->value);
        $this->assertSame(3, $value->version, 'A rollback moves history forward, it does not rewind it.');
        $this->assertCount(3, $value->versions);
    }

    public function test_version_history_is_encrypted_too(): void
    {
        $variable = $this->createVariable('SMTP_PASSWORD');
        $this->setValue($variable, $this->prod, 'first-password');
        $this->setValue($variable, $this->prod, 'second-password');

        $storedVersions = DB::table('variable_value_versions')->pluck('value')->implode('|');

        $this->assertStringNotContainsString('first-password', $storedVersions);
        $this->assertStringNotContainsString('second-password', $storedVersions);
    }

    public function test_changes_are_audited_with_encrypted_old_and_new_values(): void
    {
        $variable = $this->createVariable('DATABASE_URL');
        $this->setValue($variable, $this->prod, 'postgres://before');
        $this->setValue($variable, $this->prod, 'postgres://after');

        $rawProperties = DB::table('activity_log')->latest('id')->value('properties');

        $this->assertStringNotContainsString('postgres://before', $rawProperties);
        $this->assertStringNotContainsString('postgres://after', $rawProperties);
        $this->assertDatabaseHas('activity_log', ['event' => 'variable.value-updated']);
    }

    public function test_unicode_and_whitespace_survive_a_round_trip(): void
    {
        $variable = $this->createVariable('GREETING');
        $awkward = "  مرحبا \n multi\tline  ";

        $this->setValue($variable, $this->dev, $awkward);

        $this->assertSame($awkward, $variable->valueIn($this->dev)->value);
    }

    public function test_completeness_reports_the_keys_an_environment_is_missing(): void
    {
        $configured = $this->createVariable('CONFIGURED');
        $missing = $this->createVariable('MISSING_IN_PROD');

        $this->setValue($configured, $this->dev, 'a');
        $this->setValue($configured, $this->prod, 'a');
        $this->setValue($missing, $this->dev, 'b');

        $service = app(CompletenessService::class);

        $this->assertTrue($service->isComplete($this->dev));
        $this->assertSame(['MISSING_IN_PROD'], $service->missingKeys($this->prod));
        $this->assertSame(1, $service->missingCount($this->prod));
    }

    public function test_the_diff_reports_status_without_ever_exposing_values(): void
    {
        $same = $this->createVariable('SAME');
        $different = $this->createVariable('DIFFERENT');
        $absent = $this->createVariable('ABSENT_IN_PROD');

        $this->setValue($same, $this->dev, 'identical');
        $this->setValue($same, $this->prod, 'identical');
        $this->setValue($different, $this->dev, 'dev-value');
        $this->setValue($different, $this->prod, 'prod-value');
        $this->setValue($absent, $this->dev, 'only-in-dev');

        $rows = app(DiffService::class)->compare($this->dev, $this->prod);
        $byKey = collect($rows)->keyBy('key');

        $this->assertSame(DiffService::IN_SYNC, $byKey['SAME']['status']);
        $this->assertSame(DiffService::DIFFERS, $byKey['DIFFERENT']['status']);
        $this->assertSame(DiffService::MISSING, $byKey['ABSENT_IN_PROD']['status']);

        // The comparison happened server-side; no value came back.
        $this->assertStringNotContainsString('dev-value', json_encode($rows));
        $this->assertStringNotContainsString('identical', json_encode($rows));
        $this->assertSame(1, app(DiffService::class)->missingInRight($this->dev, $this->prod));
    }
}
