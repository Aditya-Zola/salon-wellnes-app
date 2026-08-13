<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_does_not_ask_for_a_role(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Selamat datang')
            ->assertDontSee('Masuk sebagai')
            ->assertDontSee('name="role"', false);
    }

    public function test_user_can_login_with_username_and_password_only(): void
    {
        $user = User::factory()->create([
            'username' => 'admin.selesa',
            'password' => 'password',
        ]);

        $response = $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_when_password_is_incorrect(): void
    {
        User::factory()->create([
            'username' => 'admin.selesa',
            'password' => 'password',
        ]);

        $this->post(route('login.store'), [
            'username' => 'admin.selesa',
            'password' => 'wrong-password',
        ])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }
}
