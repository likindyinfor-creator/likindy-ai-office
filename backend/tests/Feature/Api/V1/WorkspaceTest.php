<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_create_an_institution_workspace_with_one_active_owner_membership(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/workspaces', [
            'name' => 'Acme Research Institute',
            'type' => 'institution',
            'description' => 'Research workspace',
            'contact' => 'admin@example.test',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.workspace.name', 'Acme Research Institute')
            ->assertJsonPath('data.workspace.type', 'institution')
            ->assertJsonPath('data.membership.role', 'owner')
            ->assertJsonPath('data.membership.status', 'active')
            ->assertJsonMissingPath('data.workspace.personal_owner_user_id')
            ->assertJsonMissingPath('data.workspace.created_by_user_id');

        $workspaceId = $response->json('data.workspace.id');
        $this->assertDatabaseHas('workspaces', [
            'id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'personal_owner_user_id' => null,
            'type' => 'institution',
        ]);
        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspaceId,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertSame(
            1,
            WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $user->id)
                ->count()
        );
    }

    public function test_business_workspaces_can_also_be_created(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/workspaces', [
            'name' => 'Acme Business',
            'type' => 'business',
        ])->assertCreated()
            ->assertJsonPath('data.workspace.type', 'business');
    }

    public function test_workspace_and_owner_membership_are_rolled_back_if_membership_creation_fails(): void
    {
        $user = User::factory()->create();
        $this->createFailingInsertTrigger(
            'workspace_memberships',
            'fail_workspace_membership',
            'membership failure'
        );

        try {
            app(WorkspaceService::class)->createFor($user, [
                'name' => 'Atomic Workspace',
                'type' => 'institution',
            ]);
            $this->fail('Membership failure should abort workspace creation.');
        } catch (QueryException) {
            $this->assertDatabaseCount('workspaces', 0);
            $this->assertDatabaseCount('workspace_memberships', 0);
        }
    }

    public function test_workspace_creation_failure_does_not_create_membership(): void
    {
        $user = User::factory()->create();
        $this->createFailingInsertTrigger('workspaces', 'fail_workspace', 'workspace failure');

        try {
            app(WorkspaceService::class)->createFor($user, [
                'name' => 'Atomic Workspace',
                'type' => 'business',
            ]);
            $this->fail('Workspace failure should abort creation.');
        } catch (QueryException) {
            $this->assertDatabaseCount('workspaces', 0);
            $this->assertDatabaseCount('workspace_memberships', 0);
        }
    }

    private function createFailingInsertTrigger(string $table, string $triggerPrefix, string $message): void
    {
        $driver = DB::connection()->getDriverName();
        $suffix = bin2hex(random_bytes(8));
        $triggerName = $triggerPrefix.'_'.$suffix;
        $functionName = $triggerName.'_fn';
        $escapedMessage = str_replace("'", "''", $message);

        if ($driver === 'sqlite') {
            DB::statement(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON {$table} "
                ."BEGIN SELECT RAISE(FAIL, '{$escapedMessage}'); END"
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared(
                "CREATE FUNCTION {$functionName}() RETURNS trigger AS \$\$ "
                ."BEGIN RAISE EXCEPTION '{$escapedMessage}'; END; "
                .'$$ LANGUAGE plpgsql'
            );
            DB::unprepared(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON {$table} "
                ."FOR EACH ROW EXECUTE FUNCTION {$functionName}()"
            );

            return;
        }

        throw new RuntimeException("Unsupported test database driver: {$driver}");
    }

    public function test_unauthenticated_user_cannot_create_or_list_workspaces(): void
    {
        $this->postJson('/api/v1/workspaces', [
            'name' => 'Unauthorized',
            'type' => 'business',
        ])->assertUnauthorized();

        $this->getJson('/api/v1/workspaces')->assertUnauthorized();
    }

    public function test_unverified_user_cannot_create_or_list_workspaces(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/workspaces', [
            'name' => 'Unverified',
            'type' => 'business',
        ])->assertForbidden();

        $this->getJson('/api/v1/workspaces')->assertForbidden();
        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_personal_workspace_type_is_rejected_by_creation_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/workspaces', [
            'name' => 'Extra Personal Workspace',
            'type' => 'individual',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_client_cannot_assign_owner_creator_role_status_or_permissions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/workspaces', [
            'id' => (string) Str::uuid(),
            'name' => 'Spoofed Workspace',
            'type' => 'institution',
            'created_by_user_id' => $otherUser->id,
            'owner_user_id' => $otherUser->id,
            'personal_owner_user_id' => $otherUser->id,
            'role' => 'admin',
            'status' => 'suspended',
            'permissions' => ['documents.delete'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
                'created_by_user_id',
                'owner_user_id',
                'personal_owner_user_id',
                'role',
                'status',
                'permissions',
            ]);

        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('workspace_memberships', 0);
    }

    public function test_user_lists_only_their_active_workspaces_and_safe_membership_fields(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $active = $this->createMembership($user, 'active', 'admin');
        $this->createMembership($user, 'invited', 'member');
        $this->createMembership($user, 'suspended', 'staff');
        $this->createMembership($user, 'removed', 'member');
        $unrelated = $this->createMembership($otherUser, 'active', 'owner');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/workspaces');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.type', 'business')
            ->assertJsonMissing(['id' => $unrelated->id])
            ->assertJsonMissingPath('data.0.created_by_user_id')
            ->assertJsonMissingPath('data.0.personal_owner_user_id');
    }

    public function test_user_can_belong_to_multiple_workspaces(): void
    {
        $user = User::factory()->create();
        $first = $this->createMembership($user, 'active', 'owner');
        $second = $this->createMembership($user, 'active', 'member');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $first->id, 'role' => 'owner'])
            ->assertJsonFragment(['id' => $second->id, 'role' => 'member']);
    }

    public function test_database_prevents_duplicate_membership_for_same_user_and_workspace(): void
    {
        $user = User::factory()->create();
        $workspace = $this->createMembership($user, 'active', 'owner');

        $this->expectException(QueryException::class);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_workspace_creation_is_rate_limited(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/v1/workspaces', [
                'name' => 'Workspace '.$attempt,
                'type' => 'business',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/workspaces', [
            'name' => 'Too Many Workspaces',
            'type' => 'business',
        ])->assertTooManyRequests();
    }

    private function createMembership(User $user, string $status, string $role): Workspace
    {
        $workspace = new Workspace;
        $workspace->created_by_user_id = $user->getKey();
        $workspace->name = 'Workspace '.Str::random(8);
        $workspace->slug = 'workspace-'.Str::uuid();
        $workspace->type = 'business';
        $workspace->save();

        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'status' => $status,
            'joined_at' => $status === 'active' ? now() : null,
        ]);

        return $workspace;
    }
}
