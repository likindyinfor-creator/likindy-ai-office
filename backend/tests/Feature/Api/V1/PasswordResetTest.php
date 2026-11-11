<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_existing_account_gets_a_queued_reset_notification_with_frontend_url(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset.user@example.test']);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => ' RESET.USER@EXAMPLE.TEST ',
        ]);

        $response->assertOk()
            ->assertJsonPath(
                'message',
                'If an account exists for that email, password reset instructions will be sent.'
            );
        Notification::assertSentTo(
            $user,
            QueuedResetPassword::class,
            function (QueuedResetPassword $notification) use ($user): bool {
                $storedToken = DB::table('password_reset_tokens')
                    ->where('email', $user->email)
                    ->value('token');

                return str_starts_with(
                    $notification->toMail($user)->actionUrl,
                    rtrim(config('app.frontend_url'), '/').'/reset-password?'
                ) && Hash::check($notification->token, $storedToken);
            }
        );

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_unknown_email_receives_the_same_generic_forgot_password_response(): void
    {
        Notification::fake();
        $existing = User::factory()->create(['email' => 'known@example.test']);

        $knownResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $existing->email,
        ]);
        $unknownResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'unknown@example.test',
        ]);

        $knownResponse->assertOk();
        $unknownResponse->assertOk();
        $this->assertSame($knownResponse->json('message'), $unknownResponse->json('message'));
        Notification::assertSentTo($existing, QueuedResetPassword::class);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'unknown@example.test']);
    }

    public function test_forgot_password_validates_email_format(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/forgot-password', [
                'email' => 'limit@example.test',
            ])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'limit@example.test',
        ])->assertTooManyRequests();
    }

    public function test_forgot_password_is_rate_limited_per_normalized_email_across_ips(): void
    {
        Notification::fake();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])
                ->postJson('/api/v1/auth/forgot-password', [
                    'email' => ' LIMIT@EXAMPLE.TEST ',
                ])->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/v1/auth/forgot-password', [
                'email' => 'limit@example.test',
            ])->assertTooManyRequests();
    }

    public function test_forgot_password_is_rate_limited_per_ip_across_emails(): void
    {
        Notification::fake();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/forgot-password', [
                'email' => 'user'.$attempt.'@example.test',
            ])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'another@example.test',
        ])->assertTooManyRequests();
    }

    public function test_reset_password_is_rate_limited_per_normalized_email_across_ips(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])
                ->postJson('/api/v1/auth/reset-password', [
                    'email' => ' LIMIT@EXAMPLE.TEST ',
                    'token' => 'invalid-token',
                    'password' => 'New-Password-73',
                    'password_confirmation' => 'New-Password-73',
                ])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/v1/auth/reset-password', [
                'email' => 'limit@example.test',
                'token' => 'invalid-token',
                'password' => 'New-Password-73',
                'password_confirmation' => 'New-Password-73',
            ])->assertTooManyRequests();
    }

    public function test_reset_password_is_rate_limited_per_ip_across_emails(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/reset-password', [
                'email' => 'user'.$attempt.'@example.test',
                'token' => 'invalid-token',
                'password' => 'New-Password-73',
                'password_confirmation' => 'New-Password-73',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'another@example.test',
            'token' => 'invalid-token',
            'password' => 'New-Password-73',
            'password_confirmation' => 'New-Password-73',
        ])->assertTooManyRequests();
    }

    public function test_valid_token_resets_password_consumes_token_and_dispatches_event_without_logging_in(): void
    {
        $user = User::factory()->create([
            'email' => 'reset.user@example.test',
            'password' => 'Old-Password-42',
        ]);
        $token = Password::broker()->createToken($user);
        Event::fake([PasswordReset::class]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => ' RESET.USER@EXAMPLE.TEST ',
            'token' => $token,
            'password' => 'New-Password-73',
            'password_confirmation' => 'New-Password-73',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Password has been reset successfully.')
            ->assertJsonMissingPath('data.token');

        $this->assertTrue(Hash::check('New-Password-73', $user->fresh()->password));
        $this->assertFalse(Hash::check('Old-Password-42', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertGuest('web');
        Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event): bool => $event->user->is($user));
    }

    public function test_invalid_token_is_rejected_with_generic_response(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', $this->resetData($user, 'invalid-token'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The password reset link is invalid or expired.');

        $this->assertFalse(Hash::check('New-Password-73', $user->fresh()->password));
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        Carbon::setTestNow(now()->addMinutes(config('auth.passwords.users.expire') + 1));

        $this->postJson('/api/v1/auth/reset-password', $this->resetData($user, $token))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The password reset link is invalid or expired.');

        $this->assertFalse(Hash::check('New-Password-73', $user->fresh()->password));
    }

    public function test_reused_token_is_rejected_after_successful_reset(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', $this->resetData($user, $token))->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $this->resetData($user, $token))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The password reset link is invalid or expired.');
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'New-Password-73',
            'password_confirmation' => 'Different-Password-73',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_weak_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_successful_reset_revokes_existing_personal_access_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('existing-device');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', $this->resetData($user, $token))
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_successful_reset_invalidates_database_sessions(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            'id' => 'existing-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'serialized-session',
            'last_activity' => now()->timestamp,
        ]);

        $this->postJson('/api/v1/auth/reset-password', $this->resetData($user, $token))
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'existing-session']);
    }

    public function test_reset_link_uses_the_configured_frontend_url_and_never_appears_in_api_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $response->assertOk();

        Notification::assertSentTo($user, QueuedResetPassword::class, function (
            QueuedResetPassword $notification
        ) use ($user, $response): bool {
            $url = $notification->toMail($user)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_starts_with($url, rtrim(config('app.frontend_url'), '/').'/reset-password?')
                && isset($query['token'])
                && $query['email'] === $user->email
                && ! str_contains($response->getContent(), $query['token']);
        });
    }

    public function test_non_database_session_driver_is_not_claimed_or_attempted_to_be_globally_invalidated(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        config(['session.driver' => 'array']);

        $this->postJson('/api/v1/auth/reset-password', $this->resetData($user, $token))
            ->assertOk();

        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertGuest('web');
    }

    /**
     * @return array{email: string, token: string, password: string, password_confirmation: string}
     */
    private function resetData(User $user, string $token): array
    {
        return [
            'email' => $user->email,
            'token' => $token,
            'password' => 'New-Password-73',
            'password_confirmation' => 'New-Password-73',
        ];
    }
}
