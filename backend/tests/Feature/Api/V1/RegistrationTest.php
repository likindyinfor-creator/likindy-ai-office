<?php

namespace Tests\Feature\Api\V1;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_user_personal_workspace_and_active_owner_membership(): void
    {
        Notification::fake();
        $transactionLevelBefore = DB::transactionLevel();
        $registeredOutsideTransaction = false;
        Event::listen(Registered::class, function () use (
            &$registeredOutsideTransaction,
            $transactionLevelBefore
        ): void {
            $registeredOutsideTransaction = DB::transactionLevel() === $transactionLevelBefore;
        });

        $response = $this->postJson('/api/v1/auth/register', $this->registrationData());

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'new.user@example.test')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.personal_workspace.type', 'individual')
            ->assertJsonPath('data.personal_workspace.role', 'owner')
            ->assertJsonMissingPath('data.user.password');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('workspace_memberships', 1);

        $user = User::query()->where('email', 'new.user@example.test')->firstOrFail();
        $workspace = Workspace::query()->firstOrFail();

        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertSame($user->id, $workspace->created_by_user_id);
        $this->assertSame($user->id, $workspace->personal_owner_user_id);
        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertTrue($registeredOutsideTransaction);
        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_workspace_creation_failure_rolls_back_user_creation(): void
    {
        Workspace::creating(function (): void {
            throw new RuntimeException('Workspace creation failed.');
        });

        try {
            $this->withoutExceptionHandling();
            $this->postJson('/api/v1/auth/register', $this->registrationData());
            $this->fail('The expected workspace creation exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Workspace creation failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('workspace_memberships', 0);
    }

    public function test_membership_creation_failure_rolls_back_all_registration_records(): void
    {
        WorkspaceMembership::creating(function (): void {
            throw new RuntimeException('Membership creation failed.');
        });

        try {
            $this->withoutExceptionHandling();
            $this->postJson('/api/v1/auth/register', $this->registrationData());
            $this->fail('The expected membership creation exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Membership creation failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('workspace_memberships', 0);
    }

    public function test_duplicate_email_is_rejected_without_creating_more_records(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', $this->registrationData())->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->registrationData())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('workspace_memberships', 1);
    }

    public function test_registration_does_not_trust_client_supplied_workspace_or_privilege_fields(): void
    {
        Notification::fake();
        $data = $this->registrationData() + [
            'workspace_id' => (string) Str::uuid(),
            'created_by_user_id' => (string) Str::uuid(),
            'owner_user_id' => (string) Str::uuid(),
            'personal_owner_user_id' => (string) Str::uuid(),
            'role' => 'admin',
            'status' => 'active',
            'type' => 'institution',
            'email_verified_at' => now()->toISOString(),
        ];

        $this->postJson('/api/v1/auth/register', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'workspace_id',
                'created_by_user_id',
                'owner_user_id',
                'personal_owner_user_id',
                'role',
                'status',
                'type',
                'email_verified_at',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('workspace_memberships', 0);
    }

    public function test_database_rejects_two_personal_workspaces_for_one_user(): void
    {
        $user = User::factory()->create();
        $this->createWorkspace($user, 'individual', $user->id);

        $this->expectException(QueryException::class);
        $this->createWorkspace($user, 'individual', $user->id);
    }

    public function test_migration_marks_unambiguous_existing_individual_workspaces_as_personal(): void
    {
        $migration = require database_path(
            'migrations/2026_11_10_161000_add_personal_owner_to_workspaces_table.php'
        );
        $migration->down();

        $individualOwner = User::factory()->create();
        $individual = $this->createWorkspace($individualOwner, 'individual');
        $institutionOwner = User::factory()->create();
        $institution = $this->createWorkspace($institutionOwner, 'institution');

        $migration->up();

        $this->assertDatabaseHas('workspaces', [
            'id' => $individual->id,
            'personal_owner_user_id' => $individualOwner->id,
        ]);
        $this->assertDatabaseHas('workspaces', [
            'id' => $institution->id,
            'personal_owner_user_id' => null,
        ]);
    }

    public function test_migration_stops_without_schema_changes_for_ambiguous_existing_individual_workspaces(): void
    {
        $migration = require database_path(
            'migrations/2026_11_10_161000_add_personal_owner_to_workspaces_table.php'
        );
        $migration->down();

        $owner = User::factory()->create();
        $this->createWorkspace($owner, 'individual');
        $this->createWorkspace($owner, 'individual');

        try {
            $migration->up();
            $this->fail('The migration should reject ambiguous existing personal workspaces.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('resolve duplicate individual workspaces', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('workspaces', 'personal_owner_user_id'));
        $this->assertDatabaseCount('workspaces', 2);
    }

    public function test_registration_does_not_modify_existing_institution_workspaces(): void
    {
        Notification::fake();
        $institutionOwner = User::factory()->create();
        $institution = $this->createWorkspace($institutionOwner, 'institution');
        WorkspaceMembership::query()->create([
            'workspace_id' => $institution->id,
            'user_id' => $institutionOwner->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/register', $this->registrationData())->assertCreated();

        $this->assertDatabaseHas('workspaces', [
            'id' => $institution->id,
            'type' => 'institution',
            'personal_owner_user_id' => null,
        ]);
        $this->assertDatabaseCount('workspaces', 2);
        $this->assertDatabaseCount('workspace_memberships', 2);
    }

    public function test_registration_does_not_grant_workspace_administrators_access_to_private_documents(): void
    {
        Notification::fake();
        $ownerResponse = $this->postJson('/api/v1/auth/register', $this->registrationData());
        $ownerResponse->assertCreated();

        $owner = User::query()->where('email', 'new.user@example.test')->firstOrFail();
        $workspace = $owner->personalWorkspace()->firstOrFail();
        $admin = User::factory()->create();

        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $document = Document::query()->create([
            'workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'title' => 'Private registration test',
            'type' => 'report',
            'language' => 'en',
            'status' => 'draft',
            'content_format' => 'html',
            'content_html' => '<p>Private</p>',
        ]);

        $this->assertFalse(Gate::forUser($admin)->allows('view', $document));
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/workspaces/{$workspace->id}/documents/{$document->id}")
            ->assertNotFound();
    }

    /**
     * @return array{name: string, email: string, password: string, password_confirmation: string}
     */
    private function registrationData(): array
    {
        return [
            'name' => 'New User',
            'email' => 'new.user@example.test',
            'password' => 'Correct-Horse-42',
            'password_confirmation' => 'Correct-Horse-42',
        ];
    }

    private function createWorkspace(
        User $creator,
        string $type,
        ?string $personalOwnerId = null
    ): Workspace {
        $workspace = new Workspace;
        $workspace->created_by_user_id = $creator->id;
        if (Schema::hasColumn('workspaces', 'personal_owner_user_id')) {
            $workspace->personal_owner_user_id = $personalOwnerId;
        }
        $workspace->name = 'Test Workspace';
        $workspace->slug = 'workspace-'.fake()->unique()->slug();
        $workspace->type = $type;
        $workspace->save();

        return $workspace;
    }
}
