<?php

namespace Tests\Feature\Api\V1;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class WebAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_log_in_with_normalized_email_and_no_bearer_token(): void
    {
        $user = User::factory()->create([
            'email' => 'web.user@example.test',
            'password' => 'Correct-Horse-42',
        ]);

        $response = $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => '  WEB.USER@EXAMPLE.TEST ',
            'password' => 'Correct-Horse-42',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.token');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotEmpty($response->headers->getCookies());
    }

    public function test_invalid_credentials_return_a_generic_error(): void
    {
        User::factory()->create([
            'email' => 'web.user@example.test',
            'password' => 'Correct-Horse-42',
        ]);

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => 'web.user@example.test',
            'password' => 'Wrong-Password-42',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are invalid or the account is not verified.');

        $this->assertGuest('web');
    }

    public function test_unverified_user_is_rejected_without_leaving_an_authenticated_session(): void
    {
        User::factory()->unverified()->create([
            'email' => 'unverified@example.test',
            'password' => 'Correct-Horse-42',
        ]);

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => 'unverified@example.test',
            'password' => 'Correct-Horse-42',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are invalid or the account is not verified.');

        $this->assertGuest('web');
    }

    public function test_login_regenerates_the_session_identifier(): void
    {
        $user = User::factory()->create([
            'password' => 'Correct-Horse-42',
        ]);
        $session = $this->app['session']->driver();
        $session->setId('pre-auth-session-id');
        $session->start();
        $oldSessionId = $session->getId();

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Correct-Horse-42',
        ])->assertOk();

        $this->assertNotSame($oldSessionId, $this->app['session']->driver()->getId());
    }

    public function test_authenticated_verified_user_can_fetch_profile_and_validated_active_workspace_context(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Personal Workspace',
            'slug' => 'personal-'.fake()->unique()->slug(),
            'type' => 'individual',
            'personal_owner_user_id' => $user->id,
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->fromFrontend()
            ->actingAs($user, 'web')
            ->withSession(['active_workspace_id' => $workspace->id])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.active_workspace.id', $workspace->id)
            ->assertJsonPath('data.active_workspace.role', 'owner')
            ->assertJsonMissingPath('data.user.password');
    }

    public function test_invalid_or_inactive_workspace_context_is_not_returned(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Other Workspace',
            'slug' => 'other-'.fake()->unique()->slug(),
            'type' => 'institution',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'member',
            'status' => 'suspended',
        ]);

        $this->fromFrontend()
            ->actingAs($user, 'web')
            ->withSession(['active_workspace_id' => $workspace->id])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.active_workspace', null);
    }

    public function test_unauthenticated_user_cannot_fetch_profile(): void
    {
        $this->fromFrontend()
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_unverified_user_cannot_access_protected_application_apis(): void
    {
        $user = User::factory()->unverified()->create();
        $workspace = Workspace::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Unverified Workspace',
            'slug' => 'unverified-'.fake()->unique()->slug(),
            'type' => 'institution',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $document = Document::query()->create([
            'workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'title' => 'Protected document',
            'type' => 'report',
            'language' => 'en',
            'status' => 'draft',
            'content_format' => 'html',
        ]);

        $this->fromFrontend()
            ->actingAs($user, 'web')
            ->getJson('/api/v1/auth/me')
            ->assertForbidden();
        $this->fromFrontend()
            ->actingAs($user, 'web')
            ->getJson("/api/v1/workspaces/{$workspace->id}/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_logout_invalidates_session_and_protected_access_is_denied_afterward(): void
    {
        $user = User::factory()->create();
        $this->fromFrontend()->actingAs($user, 'web')
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->fromFrontend()
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_csrf_cookie_endpoint_and_stateful_origin_are_available(): void
    {
        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])
            ->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        $this->assertNotEmpty($response->headers->getCookies());
        $this->assertNotEmpty(collect($response->headers->getCookies())
            ->first(fn ($cookie): bool => $cookie->getName() === 'XSRF-TOKEN'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->fromFrontend()->postJson('/api/v1/auth/login', [
                'email' => 'missing@example.test',
                'password' => 'Wrong-Password-42',
            ])->assertUnprocessable();
        }

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'Wrong-Password-42',
        ])->assertTooManyRequests();
    }

    public function test_web_authentication_does_not_issue_personal_access_tokens(): void
    {
        $user = User::factory()->create([
            'password' => 'Correct-Horse-42',
        ]);

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Correct-Horse-42',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_web_authentication_endpoints_do_not_accept_bearer_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/logout')->assertUnauthorized();

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    private function fromFrontend(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Accept' => 'application/json',
        ]);
    }
}
