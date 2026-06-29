<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_user_and_returns_token(): void
    {
        DB::table('password_reset_tokens')->insert([
            'email' => 'mahalac@example.com',
            'token' => Hash::make('1234'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'username' => 'mahalac',
            'email' => 'mahalac@example.com',
            'password' => 'password123',
            'code' => '1234',
            'terms_accepted' => true,
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

    public function test_forgotten_password_code_requires_existing_email(): void
    {
        $this->postJson('/api/auth/forgot-password/code', [
            'identifier' => 'missing_user',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.identifier.0', 'Ne postoji račun sa ovim emailom ili korisničkim imenom.');
    }

    public function test_forgotten_password_code_is_sent_for_existing_email(): void
    {
        Mail::fake();
        User::query()->create([
            'name' => 'Mahala User',
            'username' => 'mahala_user',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/forgot-password/code', [
            'email' => 'user@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Kod za promjenu lozinke je poslan.');

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'user@example.com',
        ]);
    }

    public function test_forgotten_password_code_is_sent_for_existing_username(): void
    {
        Mail::fake();
        User::query()->create([
            'name' => 'Mahala User',
            'username' => 'mahala_user',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/forgot-password/code', [
            'identifier' => 'mahala_user',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Kod za promjenu lozinke je poslan.');

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'user@example.com',
        ]);
    }

    public function test_forgotten_password_can_verify_code_and_reset_password(): void
    {
        $user = User::query()->create([
            'name' => 'Mahala User',
            'username' => 'mahala_user',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => 'user@example.com',
            'token' => Hash::make('4321'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/forgot-password/verify', [
            'identifier' => 'mahala_user',
            'code' => '4321',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Kod je ispravan.');

        $this->postJson('/api/auth/forgot-password/reset', [
            'identifier' => 'mahala_user',
            'code' => '4321',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])
            ->assertOk()
            ->assertJsonStructure(['message', 'token', 'user' => ['id', 'name', 'username', 'email']])
            ->assertJsonPath('message', 'Lozinka je uspješno promijenjena.')
            ->assertJsonPath('user.email', 'user@example.com');

        $user->refresh();

        $this->assertTrue(Hash::check('new-password123', $user->password));
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'user@example.com',
        ]);
    }

    public function test_forgotten_password_rejects_invalid_code(): void
    {
        User::query()->create([
            'name' => 'Mahala User',
            'username' => 'mahala_user',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => 'user@example.com',
            'token' => Hash::make('4321'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/forgot-password/verify', [
            'email' => 'user@example.com',
            'code' => '1111',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Verifikacijski kod nije ispravan.');
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

    public function test_authenticated_user_can_delete_account(): void
    {
        $user = User::factory()->create();
        $user->settings()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->deleteJson('/api/auth/account')
            ->assertOk()
            ->assertJsonPath('message', 'Tvoj račun je trajno izbrisan.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('user_settings', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_guest_cannot_delete_an_account(): void
    {
        $this->deleteJson('/api/auth/account')->assertUnauthorized();
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
            'terms_accepted' => true,
        ])
            ->assertOk()
            ->assertJsonStructure(['message', 'token', 'user'])
            ->assertJsonPath('is_new_user', true)
            ->assertJsonPath('user.email', 'google@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'google@example.com',
            'google_id' => 'google-subject-123',
        ]);
    }

    public function test_google_auth_requires_terms_for_a_new_user(): void
    {
        Config::set('services.google.client_ids', ['google-client-id']);
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'google-client-id',
                'sub' => 'google-subject-without-terms',
                'email' => 'no-terms@example.com',
                'email_verified' => true,
                'name' => 'No Terms User',
            ]),
        ]);

        $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-id-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terms_accepted');

        $this->assertDatabaseMissing('users', ['email' => 'no-terms@example.com']);
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
            ->assertJsonPath('is_new_user', false)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.username', 'mahala_user');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_id' => 'google-subject-456',
        ]);
    }
}
