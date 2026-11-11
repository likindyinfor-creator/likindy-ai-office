<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MobileAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_log_in_and_receives_a_finite_mobile_token(): void
    {
        $user = User::factory()->create([
            'email' => 'mobile.user@example.test',
            'password' => 'Correct-Horse-42',
        ]);
        $workspace = $this->createPersonalWorkspace($user);

        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'email' => ' MOBILE.USER@EXAMPLE.TEST ',
            'password' => 'Correct-Horse-42',
            'workspace_id' => 'client-workspace',
            'role' => 'admin',
            'permissions' => ['documents.delete'],
            'status' => 'verified',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonPath('data.personal_workspace.id', $workspace->id)
            ->assertJsonPath('data.personal_workspace.role', 'owner')
            ->assertJsonMissingPath('data.user.password');

        $token = $response->json('data.token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $storedToken = PersonalAccessToken::query()->sole();
        $this->assertSame('likindy-mobile', $storedToken->name);
        $this->assertSame(hash('sha256', explode('|', $token, 2)[1]), $storedToken->token);
        $this->assertSame(
            30 * 24 * 60 * 60,
            (int) $storedToken->created_at->diffInSeconds($storedToken->expires_at)
        );
        $this->assertSame(['*'], $storedToken->abilities);
        $this->assertGuest('web');
        $response->assertJsonMissingPath('data.user.remember_token')
            ->assertJsonMissingPath('data.user.email_verified_at');
    }

    public function test_invalid_credentials_return_a_generic_failure(): void
    {
        User::factory()->create([
            'email' => 'mobile.user@example.test',
            'password' => 'Correct-Horse-42',
        ]);

        $this->postJson('/api/v1/mobile/auth/login', [
            'email' => 'mobile.user@example.test',
            'password' => 'Wrong-Password-42',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are invalid or the account is not verified.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unverified_user_cannot_receive_a_token(): void
    {
        User::factory()->unverified()->create([
            'email' => 'unverified@example.test',
            'password' => 'Correct-Horse-42',
        ]);

        $this->postJson('/api/v1/mobile/auth/login', [
            'email' => 'unverified@example.test',
            'password' => 'Correct-Horse-42',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are invalid or the account is not verified.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_mobile_token_is_rejected_server_side(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('likindy-mobile', ['*'], now()->addDays(30));
        PersonalAccessToken::findToken($token->plainTextToken)
            ->forceFill(['expires_at' => now()->subSecond()])
            ->save();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/mobile/auth/me')
            ->assertUnauthorized();
    }

    public function test_mobile_me_returns_safe_profile_and_never_returns_the_token(): void
    {
        $user = User::factory()->create();
        $workspace = $this->createPersonalWorkspace($user);
        $token = $user->createToken('likindy-mobile', ['*'], now()->addDays(30));

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/mobile/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.personal_workspace.id', $workspace->id)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token')
            ->assertJsonMissingPath('data.user.email_verified_at');
    }

    public function test_unauthenticated_mobile_me_is_rejected(): void
    {
        $this->getJson('/api/v1/mobile/auth/me')->assertUnauthorized();
    }

    public function test_mobile_endpoints_require_a_personal_access_token_not_a_web_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/mobile/auth/me')
            ->assertUnauthorized();
    }

    public function test_mobile_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('likindy-mobile', ['*'], now()->addDays(30));
        $otherToken = $user->createToken('other-device', ['*'], now()->addDays(30));

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/v1/mobile/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
        Auth::forgetGuards();
        $this->withToken($currentToken->plainTextToken)
            ->getJson('/api/v1/mobile/auth/me')
            ->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($otherToken->plainTextToken)
            ->getJson('/api/v1/mobile/auth/me')
            ->assertOk();
    }

    public function test_mobile_login_is_rate_limited_per_ip_and_normalized_email(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])
                ->postJson('/api/v1/mobile/auth/login', [
                    'email' => ' RATE.LIMIT@EXAMPLE.TEST ',
                    'password' => 'Wrong-Password-42',
                ])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/v1/mobile/auth/login', [
                'email' => 'rate.limit@example.test',
                'password' => 'Wrong-Password-42',
            ])->assertTooManyRequests();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
                ->postJson('/api/v1/mobile/auth/login', [
                    'email' => 'other'.$attempt.'@example.test',
                    'password' => 'Wrong-Password-42',
                ])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson('/api/v1/mobile/auth/login', [
                'email' => 'last@example.test',
                'password' => 'Wrong-Password-42',
            ])->assertTooManyRequests();
    }

    public function test_password_reset_revokes_existing_mobile_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'reset.mobile@example.test',
            'password' => 'Old-Password-42',
        ]);
        $mobileToken = $user->createToken('likindy-mobile', ['*'], now()->addDays(30));
        $resetToken = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'New-Password-73',
            'password_confirmation' => 'New-Password-73',
        ])->assertOk();

        $this->withToken($mobileToken->plainTextToken)
            ->getJson('/api/v1/mobile/auth/me')
            ->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function createPersonalWorkspace(User $user): Workspace
    {
        $workspace = new Workspace;
        $workspace->created_by_user_id = $user->id;
        $workspace->personal_owner_user_id = $user->id;
        $workspace->name = 'Personal Workspace';
        $workspace->slug = 'personal-'.Str::lower(Str::random(12));
        $workspace->type = 'individual';
        $workspace->save();

        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $workspace;
    }
}
