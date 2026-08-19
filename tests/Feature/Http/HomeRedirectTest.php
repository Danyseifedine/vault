<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The root URL is a doorway, not a page. Nothing about this product is public,
 * so there is nothing to show a visitor except the way in.
 */
class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_the_sign_in_screen()
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_is_sent_to_the_dashboard()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_there_is_no_marketing_page_left_behind()
    {
        $this->assertFileDoesNotExist(resource_path('js/pages/welcome.tsx'));
    }
}
