<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_notification_generates_a_signed_url_that_verifies_without_a_session(): void
    {
        $user = User::factory()->unverified()->create();
        $notification = new QueuedVerifyEmail;
        $verificationUrl = $notification->toMail($user)->actionUrl;
        $query = [];
        parse_str((string) parse_url($verificationUrl, PHP_URL_QUERY), $query);

        $this->assertSame(
            route('verification.verify', [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ], false),
            parse_url($verificationUrl, PHP_URL_PATH)
        );
        $this->assertArrayHasKey('expires', $query);
        $this->assertArrayHasKey('signature', $query);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        Event::fake([Verified::class]);

        $this->get($verificationUrl)
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));
    }

    public function test_invalid_signature_is_rejected_without_verifying_the_user(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = (new QueuedVerifyEmail)->toMail($user)->actionUrl;
        $modifiedUrl = preg_replace('/signature=[^&]+/', 'signature=invalid', $verificationUrl);

        $this->get((string) $modifiedUrl)
            ->assertForbidden()
            ->assertJsonPath('message', 'Verification link is invalid or expired.');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_modified_user_or_hash_path_is_rejected_by_the_signature_check(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = (new QueuedVerifyEmail)->toMail($user)->actionUrl;
        $modifiedUrl = str_replace(
            sha1($user->getEmailForVerification()),
            sha1('different@example.test'),
            $verificationUrl
        );

        $this->get($modifiedUrl)
            ->assertForbidden()
            ->assertJsonPath('message', 'Verification link is invalid or expired.');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_signed_url_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinute(),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $this->get($url)
            ->assertForbidden()
            ->assertJsonPath('message', 'Verification link is invalid or expired.');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_valid_signature_with_incorrect_email_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            [
                'id' => $user->id,
                'hash' => sha1('different@example.test'),
            ]
        );

        $this->get($url)
            ->assertForbidden()
            ->assertJsonPath('message', 'Verification link is invalid or expired.');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_signed_link_for_unknown_user_returns_a_generic_error(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            [
                'id' => (string) Str::uuid(),
                'hash' => sha1('unknown@example.test'),
            ]
        );

        $this->get($url)
            ->assertForbidden()
            ->assertJsonPath('message', 'Verification link is invalid or expired.');
    }

    public function test_repeated_verification_is_idempotent_and_dispatches_verified_once(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = (new QueuedVerifyEmail)->toMail($user)->actionUrl;
        Event::fake([Verified::class]);

        $this->get($verificationUrl)
            ->assertOk()
            ->assertJsonPath('message', 'Email address verified.');
        $this->get($verificationUrl)
            ->assertOk()
            ->assertJsonPath('message', 'Email address is already verified.');

        Event::assertDispatchedTimes(Verified::class, 1);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_registration_reports_notification_dispatch_failure_without_failing_committed_registration(): void
    {
        Notification::fake();
        Event::listen(Registered::class, function (): void {
            throw new \RuntimeException('Notification dispatch failed.');
        });

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'new.user@example.test',
            'password' => 'Correct-Horse-42',
            'password_confirmation' => 'Correct-Horse-42',
        ]);

        $response->assertCreated()
            ->assertJsonPath('meta.verification_required', true)
            ->assertJsonPath('meta.verification_notification', 'failed');

        $user = User::query()->where('email', 'new.user@example.test')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('workspace_memberships', 1);
    }
}
