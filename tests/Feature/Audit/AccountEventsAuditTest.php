<?php

namespace Tests\Feature\Audit;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Organizations\CreateOrganization;
use App\Actions\Projects\CreateProject;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\RollbackVariableValue;
use App\Actions\Variables\SetVariableValue;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The account's own lifecycle belongs in the log too: signing in, changing a
 * password, deleting the account. "Log everything: ... login" was a ticked
 * feature that password sign-ins simply skipped.
 */
class AccountEventsAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_completed_login_is_audited(): void
    {
        $user = User::factory()->onboarded()->create();

        // With mandatory 2FA the Login event fires when the session is
        // actually established - which is what the log should say too.
        event(new Login('web', $user, false));

        $this->assertDatabaseHas('activity_log', [
            'event' => 'auth.login',
            'causer_id' => $user->id,
        ]);
    }

    public function test_a_failed_login_is_audited_as_a_failure(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        $this->assertDatabaseHas('activity_log', ['event' => 'auth.login-failed']);
    }

    public function test_changing_the_password_is_audited(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)
            ->from('/settings/security')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/security');

        $this->assertDatabaseHas('activity_log', [
            'event' => 'account.password-changed',
            'causer_id' => $user->id,
        ]);
    }

    public function test_a_password_reset_is_audited(): void
    {
        $user = User::factory()->onboarded()->create();

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'auth.password-reset',
            'causer_id' => $user->id,
        ]);
    }

    public function test_renaming_the_account_is_audited(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'New Name',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'account.renamed',
            'causer_id' => $user->id,
        ]);
    }

    public function test_deleting_the_account_is_audited_and_survives_the_deletion(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->delete('/settings/profile', [
            'password' => 'password',
        ]);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'account.deleted']);
    }

    public function test_a_rollback_is_its_own_event_in_the_log(): void
    {
        $owner = User::factory()->onboarded()->organizationCreator()->create();
        $organization = app(CreateOrganization::class)($owner, 'Acme');
        $project = app(CreateProject::class)($organization, $owner, 'API');
        $environment = $project->environments()->firstOrFail();

        $variable = app(CreateVariable::class)($project, $owner, 'API_KEY');
        app(SetVariableValue::class)($variable, $environment, $owner, 'first_fake');
        app(SetVariableValue::class)($variable, $environment, $owner, 'second_fake');

        $value = $variable->values()->firstOrFail();
        $version = $value->versions()->orderBy('version')->firstOrFail();

        app(RollbackVariableValue::class)($value, $version, $owner);

        // Distinct event, so "who reverted prod at 3am" is answerable.
        $this->assertDatabaseHas('activity_log', ['event' => 'variable.value-rolled-back']);
    }
}
