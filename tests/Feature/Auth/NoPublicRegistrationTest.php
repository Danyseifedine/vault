<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Vault is invite-only: an account exists because someone with authority
 * created it, never because a stranger filled in a form.
 */
class NoPublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_screen_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_cannot_be_posted_to(): void
    {
        $this->post('/register', [
            'name' => 'Uninvited Person',
            'email' => 'stranger@example.com',
            'password' => 'password-that-should-not-work',
            'password_confirmation' => 'password-that-should-not-work',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    public function test_the_first_account_is_created_by_command(): void
    {
        $this->artisan('vault:create-first-user', [
            '--name' => 'Dany',
            '--email' => 'first@lebify.test',
            '--password' => 'a-strong-first-password',
        ])->assertExitCode(0);

        $user = User::query()->where('email', 'first@lebify.test')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->isActive());
        $this->assertNotNull($user->profile_completed_at);
        $this->assertNotNull($user->email_verified_at);
        // The command grants nothing: powers start when they create an org.
        $this->assertSame(0, $user->grants()->count());
    }

    public function test_the_bootstrap_command_refuses_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@lebify.test']);

        $this->artisan('vault:create-first-user', [
            '--name' => 'Someone',
            '--email' => 'taken@lebify.test',
            '--password' => 'another-strong-password',
        ])->assertExitCode(1);
    }
}
