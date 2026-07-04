<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_requires_authentication(): void
    {
        $response = $this->get('/account');

        $response->assertRedirect('/login');
    }

    public function test_account_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/account');

        $response->assertStatus(200);
    }

    public function test_account_settings_page_renders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/account/settings');

        $response->assertStatus(200);
    }

    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/account/settings', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame('Updated Name', $user->fresh()->name);
        $this->assertSame('updated@example.com', $user->fresh()->email);
    }

    public function test_profile_update_requires_a_lowercase_email(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/account/settings', [
            'name' => $user->name,
            'email' => 'Updated@Example.com',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertNotSame('Updated@Example.com', $user->fresh()->email);
    }

    public function test_profile_update_requires_a_unique_email(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs($user)->patch('/account/settings', [
            'name' => $user->name,
            'email' => 'taken@example.com',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_user_can_update_password_with_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($user)->put('/account/settings/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_password_update_fails_with_incorrect_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($user)->put('/account/settings/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }
}
