<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'username' => 'mahalac',
            'email' => 'mahalac@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonStructure(['message', 'token', 'user' => ['id', 'name', 'username', 'email']])
            ->assertJsonPath('user.username', 'mahalac')
            ->assertJsonPath('user.email', 'mahalac@example.com');

        $this->assertDatabaseHas('users', [
            'username' => 'mahalac',
            'email' => 'mahalac@example.com',
        ]);
    }

    public function test_it_logs_user_in_with_email_or_username(): void
    {
        User::query()->create([
            'name' => 'Mahala User',
            'username' => 'mahala_user',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonStructure(['message', 'token', 'user'])
            ->assertJsonPath('user.username', 'mahala_user');

        $this->postJson('/api/auth/login', [
            'username' => 'mahala_user',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonStructure(['message', 'token', 'user'])
            ->assertJsonPath('user.email', 'user@example.com');
    }

    public function test_invalid_login_credentials_return_email_and_password_errors(): void
    {
        User::query()->create([
            'name' => 'Mahala User',
            'username' => 'mahala_user',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email, korisničko ime ili lozinka nisu ispravni.')
            ->assertJsonPath('errors.password.0', 'Email, korisničko ime ili lozinka nisu ispravni.');
    }

    public function test_authenticated_user_can_fetch_profile_and_logout(): void
    {
        $user = User::query()->create([
            'name' => 'Mahala User',
            'username' => 'mahala_user',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.username', 'mahala_user');

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Uspješno si odjavljen.');
    }

    public function test_google_auth_registers_user_and_returns_token(): void
    {
        Config::set('services.google.client_ids', ['google-client-id']);
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'google-client-id',
                'sub' => 'google-subject-123',
                'email' => 'google@example.com',
                'email_verified' => 'true',
                'name' => 'Google User',
            ]),
        ]);

        $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-id-token',
        ])
            ->assertOk()
            ->assertJsonStructure(['message', 'token', 'user'])
            ->assertJsonPath('user.email', 'google@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'google@example.com',
            'google_id' => 'google-subject-123',
        ]);
    }

    public function test_google_auth_links_existing_email_user(): void
    {
        Config::set('services.google.client_ids', ['google-client-id']);
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'google-client-id',
                'sub' => 'google-subject-456',
                'email' => 'user@example.com',
                'email_verified' => true,
                'name' => 'Mahala User',
            ]),
        ]);
        $user = User::query()->create([
            'name' => 'Mahala User',
            'username' => 'mahala_user',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-id-token',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.username', 'mahala_user');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_id' => 'google-subject-456',
        ]);
    }
}
