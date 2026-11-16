<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-battery';

    public function test_register_creates_user_and_returns_201_with_a_working_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'username' => 'gaspard',
            'email' => 'Gaspard@Example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $user = User::sole();

        $response->assertCreated()
            ->assertJson(fn (AssertableJson $json) => $json
                ->whereType('token', 'string')
                ->where('token_type', 'Bearer')
                ->where('user', [
                    'id' => $user->id,
                    'username' => 'gaspard',
                    'email' => 'gaspard@example.com',
                ])
            );
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/user')
            ->assertOk();
    }

    public function test_register_returns_422_when_required_fields_are_missing(): void
    {
        $this->postJson('/api/v1/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'username' => 'The username field is required.',
                'email' => 'The email field is required.',
                'password' => 'The password field is required.',
            ]);
    }

    public function test_register_returns_422_when_email_is_taken_in_any_case(): void
    {
        User::factory()->create(['email' => 'gaspard@example.com']);

        $this->postJson('/api/v1/register', [
            'username' => 'gaspard',
            'email' => 'GASPARD@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The email has already been taken.',
                'errors' => ['email' => ['The email has already been taken.']],
            ]);
    }

    public function test_register_returns_422_when_username_is_taken(): void
    {
        User::factory()->create(['username' => 'gaspard']);

        $this->postJson('/api/v1/register', [
            'username' => 'gaspard',
            'email' => 'gaspard@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username' => 'The username has already been taken.']);
    }

    public function test_register_returns_422_when_password_confirmation_does_not_match(): void
    {
        $this->postJson('/api/v1/register', [
            'username' => 'gaspard',
            'email' => 'gaspard@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => 'something-else',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'The password field confirmation does not match.']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_returns_429_after_five_attempts_per_minute(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/register', [])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_login_returns_200_with_a_new_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $response = $this->postJson('/api/v1/login', [
            'email' => strtoupper($user->email),
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->whereType('token', 'string')
                ->where('token_type', 'Bearer')
                ->where('user', [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                ])
            );
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_login_returns_401_when_password_is_wrong(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid credentials.']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_returns_401_when_email_is_unknown(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_returns_422_when_required_fields_are_missing(): void
    {
        $this->postJson('/api/v1/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email' => 'The email field is required.',
                'password' => 'The password field is required.',
            ]);
    }

    public function test_login_returns_429_after_five_failed_attempts_on_the_same_email(): void
    {
        $credentials = ['email' => 'target@example.com', 'password' => 'guess'];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/login', $credentials)->assertUnauthorized();
        }

        $this->postJson('/api/v1/login', $credentials)->assertTooManyRequests();
        $this->postJson('/api/v1/login', ['email' => 'other@example.com', 'password' => 'guess'])
            ->assertUnauthorized();
    }

    public function test_logout_returns_204_and_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('ios');
        $otherDeviceToken = $user->createToken('ios');

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/v1/logout')
            ->assertNoContent();

        $this->assertModelMissing($currentToken->accessToken);
        $this->assertModelExists($otherDeviceToken->accessToken);
    }

    public function test_logout_returns_401_without_a_token(): void
    {
        $this->postJson('/api/v1/logout')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_user_returns_the_authenticated_user_without_a_data_wrapper(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('ios')->plainTextToken)
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
            ]);
    }
}
