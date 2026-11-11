<?php

namespace Tests\Feature\Api\V1;

use App\Models\Document;
use App\Models\DocumentAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_document_is_visible_to_its_owner(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        Sanctum::actingAs($owner);

        $this->getJson($this->documentUrl($workspace, $document))
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.visibility', 'private');
    }

    public function test_new_workspace_memberships_are_not_active_by_default(): void
    {
        [$workspace] = $this->createWorkspaceMember('owner');
        $user = User::factory()->create();
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);

        $this->assertSame('invited', $membership->fresh()->status);
    }

    public function test_database_rejects_a_document_owner_without_workspace_membership(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $nonMember = User::factory()->create();

        $this->expectException(QueryException::class);
        $this->createDocument($workspace, $nonMember);
    }

    public function test_workspace_admin_cannot_read_another_members_private_document_without_a_grant(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [$adminWorkspace, $admin] = $this->createWorkspaceMember('admin', $workspace);
        $this->assertSame($workspace->id, $adminWorkspace->id);
        $document = $this->createDocument($workspace, $owner);
        Sanctum::actingAs($admin);

        $this->getJson($this->documentUrl($workspace, $document))->assertNotFound();
        $this->getJson($this->documentsUrl($workspace))->assertJsonCount(0, 'data');
    }

    public function test_other_workspace_member_cannot_read_a_private_document_without_a_grant(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $member] = $this->createWorkspaceMember('staff', $workspace);
        $document = $this->createDocument($workspace, $owner);
        Sanctum::actingAs($member);

        $this->getJson($this->documentUrl($workspace, $document))->assertNotFound();
        $this->getJson($this->documentsUrl($workspace))->assertJsonCount(0, 'data');
    }

    public function test_explicit_read_grant_allows_a_member_to_read_a_private_document(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $reader] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createDocument($workspace, $owner);
        DocumentAccessGrant::query()->create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $reader->id,
            'granted_by_user_id' => $owner->id,
            'permission' => 'read',
        ]);
        Sanctum::actingAs($reader);

        $this->getJson($this->documentUrl($workspace, $document))
            ->assertOk()
            ->assertJsonPath('data.id', $document->id);
        $this->assertTrue(Gate::forUser($reader)->allows('view', $document));
        $this->assertFalse(Gate::forUser($reader)->allows('update', $document));
    }

    public function test_private_document_grants_only_allow_their_assigned_permissions(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        $document = $this->createDocument($workspace, $owner);
        $readers = [];

        foreach (['edit', 'share'] as $permission) {
            [, $reader] = $this->createWorkspaceMember('member', $workspace);
            DocumentAccessGrant::query()->create([
                'workspace_id' => $workspace->id,
                'document_id' => $document->id,
                'user_id' => $reader->id,
                'granted_by_user_id' => $owner->id,
                'permission' => $permission,
            ]);
            $readers[$permission] = $reader;
        }

        foreach ($readers as $permission => $reader) {
            $this->assertTrue(Gate::forUser($reader)->allows('view', $document));
            $this->assertSame(
                $permission === 'edit',
                Gate::forUser($reader)->allows('update', $document)
            );
            $this->assertFalse(Gate::forUser($reader)->allows('share', $document));
        }
    }

    public function test_active_workspace_member_can_read_a_workspace_shared_document(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $member] = $this->createWorkspaceMember('staff', $workspace);
        [, $admin] = $this->createWorkspaceMember('admin', $workspace);
        $document = $this->createDocument($workspace, $owner, 'workspace');
        Sanctum::actingAs($member);

        $this->getJson($this->documentUrl($workspace, $document))->assertOk();
        $this->assertTrue(Gate::forUser($member)->allows('view', $document));
        $this->assertFalse(Gate::forUser($member)->allows('update', $document));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $document));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $document));
    }

    public function test_workspace_shared_document_is_not_visible_to_a_non_member(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [$otherWorkspace, $outsider] = $this->createWorkspaceMember('member');
        $document = $this->createDocument($workspace, $owner, 'workspace');
        Sanctum::actingAs($outsider);

        $this->getJson($this->documentUrl($workspace, $document))->assertForbidden();
        $this->assertFalse(Gate::forUser($outsider)->allows('view', $document));
        $this->assertNotSame($workspace->id, $otherWorkspace->id);
    }

    public function test_suspended_workspace_member_cannot_read_a_workspace_shared_document(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $member] = $this->createWorkspaceMember('staff', $workspace);
        WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $member->id)
            ->update(['status' => 'suspended']);
        $document = $this->createDocument($workspace, $owner, 'workspace');
        Sanctum::actingAs($member);

        $this->getJson($this->documentUrl($workspace, $document))->assertForbidden();
        $this->assertFalse(Gate::forUser($member)->allows('view', $document));
    }

    public function test_user_cannot_access_another_workspace_document_or_list(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [$otherWorkspace, $otherUser] = $this->createWorkspaceMember('admin');
        $document = $this->createDocument($workspace, $owner);
        Sanctum::actingAs($otherUser);

        $this->getJson($this->documentsUrl($workspace))->assertForbidden();
        $this->getJson($this->documentUrl($otherWorkspace, $document))->assertNotFound();
    }

    private function createWorkspaceMember(
        string $role,
        ?Workspace $workspace = null
    ): array {
        $user = User::factory()->create();
        $workspace ??= Workspace::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Test Workspace',
            'slug' => 'workspace-'.fake()->unique()->slug(),
            'type' => 'institution',
        ]);

        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$workspace, $user];
    }

    private function createDocument(
        Workspace $workspace,
        User $owner,
        ?string $visibility = null
    ): Document {
        $attributes = [
            'workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'title' => 'Privacy test document',
            'type' => 'report',
            'language' => 'en',
            'status' => 'draft',
            'content_format' => 'html',
            'content_html' => '<p>Private test content</p>',
        ];

        if ($visibility !== null) {
            $attributes['visibility'] = $visibility;
        }

        return Document::query()->create($attributes);
    }

    private function documentsUrl(Workspace $workspace): string
    {
        return "/api/v1/workspaces/{$workspace->id}/documents";
    }

    private function documentUrl(Workspace $workspace, Document $document): string
    {
        return $this->documentsUrl($workspace)."/{$document->id}";
    }
}
