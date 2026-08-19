<?php

namespace Tests\Feature\Reveal;

use App\Actions\Pins\IssuePin;
use App\Actions\Pins\SetPinStatus;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Permission;
use App\Enums\PinStatus;
use App\Enums\RevealRequirement;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use App\Services\Reveal\RevealGuard;
use App\Services\Reveal\RevealOutcome;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

class RevealGuardTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private const PIN = '4271';

    private const PASSWORD = 'the-account-password';

    private Organization $organization;

    private User $owner;

    private User $developer;

    private Project $project;

    private Environment $prod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->organization->members()->first();
        $this->project = Project::factory()->for($this->organization)->create();
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);

        $this->developer = User::factory()->onboarded()->create(['password' => self::PASSWORD]);
        $this->joinMember($this->organization, $this->developer);
        $this->grantReader($this->developer, $this->prod);

        app(IssuePin::class)($this->organization, $this->owner, $this->developer, self::PIN);
    }

    private function variable(Sensitivity $sensitivity, string $value = 'the-secret-value'): Variable
    {
        $variable = app(CreateVariable::class)(
            $this->project,
            $this->owner,
            'SOME_KEY_'.$sensitivity->value,
            sensitivity: $sensitivity,
        );

        app(SetVariableValue::class)($variable, $this->prod, $this->owner, $value);

        return $variable;
    }

    private function guard(): RevealGuard
    {
        return app(RevealGuard::class);
    }

    public function test_a_public_value_reveals_without_a_pin(): void
    {
        $variable = $this->variable(Sensitivity::Public, 'info');

        $outcome = $this->guard()->reveal($this->developer, $variable, $this->prod);

        $this->assertTrue($outcome->granted);
        $this->assertSame('info', $outcome->value);
        $this->assertDatabaseHas('activity_log', ['event' => 'variable.revealed']);
    }

    public function test_a_sensitive_value_demands_the_pin(): void
    {
        $variable = $this->variable(Sensitivity::Sensitive);

        $withoutPin = $this->guard()->reveal($this->developer, $variable, $this->prod);
        $this->assertFalse($withoutPin->granted);
        $this->assertSame(RevealOutcome::PIN_REQUIRED, $withoutPin->reason);

        $withPin = $this->guard()->reveal($this->developer, $variable, $this->prod, pin: self::PIN);
        $this->assertTrue($withPin->granted);
        $this->assertSame('the-secret-value', $withPin->value);
    }

    public function test_a_critical_value_demands_the_pin_and_the_account_password(): void
    {
        $variable = $this->variable(Sensitivity::Critical);

        $pinOnly = $this->guard()->reveal($this->developer, $variable, $this->prod, pin: self::PIN);
        $this->assertFalse($pinOnly->granted);
        $this->assertSame(RevealOutcome::PASSWORD_REQUIRED, $pinOnly->reason);

        $wrongPassword = $this->guard()->reveal($this->developer, $variable, $this->prod, self::PIN, 'not-the-password');
        $this->assertFalse($wrongPassword->granted);
        $this->assertSame(RevealOutcome::PASSWORD_INCORRECT, $wrongPassword->reason);

        $granted = $this->guard()->reveal($this->developer, $variable, $this->prod, self::PIN, self::PASSWORD);
        $this->assertTrue($granted->granted);
    }

    public function test_a_wrong_pin_is_refused_counted_and_audited(): void
    {
        $variable = $this->variable(Sensitivity::Sensitive);

        $outcome = $this->guard()->reveal($this->developer, $variable, $this->prod, pin: '0000');

        $this->assertFalse($outcome->granted);
        $this->assertSame(RevealOutcome::PIN_INCORRECT, $outcome->reason);
        $this->assertSame(4, $outcome->attemptsRemaining);
        $this->assertDatabaseHas('activity_log', ['event' => 'pin.failed']);
    }

    public function test_repeated_wrong_pins_lock_reveals_and_the_lockout_is_audited(): void
    {
        $variable = $this->variable(Sensitivity::Sensitive);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->guard()->reveal($this->developer, $variable, $this->prod, pin: '0000');
        }

        // Even the correct PIN is refused once locked.
        $outcome = $this->guard()->reveal($this->developer, $variable, $this->prod, pin: self::PIN);

        $this->assertFalse($outcome->granted);
        $this->assertSame(RevealOutcome::LOCKED, $outcome->reason);
        $this->assertNotNull($outcome->lockedUntil);
        $this->assertDatabaseHas('activity_log', ['event' => 'reveal.locked-out']);
    }

    public function test_a_blocked_pin_stops_reveals_immediately(): void
    {
        $variable = $this->variable(Sensitivity::Sensitive);
        $pin = $this->developer->pins()->first();

        app(SetPinStatus::class)($pin, $this->owner, PinStatus::Blocked);

        $outcome = $this->guard()->reveal($this->developer, $variable, $this->prod, pin: self::PIN);

        $this->assertFalse($outcome->granted);
        $this->assertSame(RevealOutcome::PIN_INCORRECT, $outcome->reason);
    }

    public function test_someone_without_environment_access_is_denied_before_any_pin_check(): void
    {
        $variable = $this->variable(Sensitivity::Public);
        $outsider = $this->joinMember($this->organization);

        $outcome = $this->guard()->reveal($outsider, $variable, $this->prod);

        $this->assertFalse($outcome->granted);
        $this->assertSame(RevealOutcome::DENIED, $outcome->reason);
        $this->assertDatabaseHas('activity_log', ['event' => 'reveal.denied']);
    }

    public function test_the_environment_policy_decides_not_the_sensitivity_alone(): void
    {
        $dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
        $this->grantReader($this->developer, $dev);

        // dev is configured wide open, even for critical values.
        $dev->revealPolicies()->update(['requirement' => RevealRequirement::None]);
        $dev->refresh()->load('revealPolicies');

        $variable = app(CreateVariable::class)(
            $this->project,
            $this->owner,
            'DEV_CRITICAL',
            sensitivity: Sensitivity::Critical,
        );
        app(SetVariableValue::class)($variable, $dev, $this->owner, 'open-in-dev');

        $outcome = $this->guard()->reveal($this->developer, $variable, $dev);

        $this->assertTrue($outcome->granted, 'dev policy says no PIN is needed');
        $this->assertSame('open-in-dev', $outcome->value);
    }

    public function test_issuing_a_pin_takes_the_pins_manage_grant(): void
    {
        // Even a member manager cannot issue PINs without pins.manage.
        $manager = $this->joinMember($this->organization);
        $this->grant($manager, Permission::ManageMembers, $this->organization);

        try {
            app(IssuePin::class)($this->organization, $manager, $this->developer, '1234');
            $this->fail('A member without pins.manage issued a PIN.');
        } catch (AuthorizationException) {
            // expected
        }

        // The grant, not the creator seat, is what unlocks it.
        $this->grant($manager, Permission::ManagePins, $this->organization);

        $pin = app(IssuePin::class)($this->organization, $manager, $this->developer, '1234');

        $this->assertSame($this->developer->id, $pin->user_id);
    }

    public function test_a_pin_must_be_four_digits(): void
    {
        $this->expectException(ValidationException::class);

        app(IssuePin::class)($this->organization, $this->owner, $this->developer, 'abcd');
    }

    public function test_a_holder_may_carry_several_pins_and_any_active_one_works(): void
    {
        app(IssuePin::class)($this->organization, $this->owner, $this->developer, '9999', 'second pin');

        $variable = $this->variable(Sensitivity::Sensitive);

        $this->assertTrue($this->guard()->reveal($this->developer, $variable, $this->prod, pin: '9999')->granted);
        $this->assertTrue($this->guard()->reveal($this->developer, $variable, $this->prod, pin: self::PIN)->granted);
    }
}
