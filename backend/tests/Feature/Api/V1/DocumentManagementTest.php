<?php

namespace Tests\Feature\Api\V1;

use App\Models\Document;
use App\Models\DocumentAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_view_update_and_soft_delete_a_document(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        Sanctum::actingAs($owner);

        $response = $this->postJson($this->documentsUrl($workspace), [
            'title' => 'Quarterly report',
            'type' => 'report',
            'content_html' => '<p>Four thoughtful words.</p>',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.workspaceId', $workspace->id)
            ->assertJsonPath('data.ownerId', $owner->id)
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.wordCount', 3);

        $document = Document::query()->findOrFail($response->json('data.id'));
        $this->assertDatabaseHas('document_versions', [
            'document_id' => $document->id,
            'version_number' => 1,
            'changed_by_user_id' => $owner->id,
        ]);

        $this->getJson($this->documentUrl($workspace, $document))
            ->assertOk()
            ->assertJsonPath('data.id', $document->id);

        $this->patchJson($this->documentUrl($workspace, $document), [
            'title' => 'Quarterly report revised',
            'content_html' => '<p>Updated report content.</p>',
            'change_summary' => 'Updated the report.',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Quarterly report revised')
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('document_versions', [
            'document_id' => $document->id,
            'version_number' => 2,
            'change_summary' => 'Updated the report.',
        ]);

        $this->deleteJson($this->documentUrl($workspace, $document))->assertOk();
        $this->assertSoftDeleted('documents', ['id' => $document->id]);
        $this->getJson($this->documentUrl($workspace, $document))->assertNotFound();
    }

    public function test_document_lists_include_only_documents_accessible_to_the_member(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $reader] = $this->createWorkspaceMember('member', $workspace);
        $privateDocument = $this->createDocument($workspace, $owner, 'Owner private');
        $grantedDocument = $this->createDocument($workspace, $owner, 'Granted private');
        DocumentAccessGrant::query()->create([
            'workspace_id' => $workspace->id,
            'document_id' => $grantedDocument->id,
            'user_id' => $reader->id,
            'granted_by_user_id' => $owner->id,
            'permission' => 'read',
        ]);
        Sanctum::actingAs($reader);

        $this->getJson($this->documentsUrl($workspace))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $grantedDocument->id);

        $this->assertNotSame($privateDocument->id, $grantedDocument->id);
    }

    public function test_explicit_edit_grant_allows_update_but_read_grant_does_not(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $editor] = $this->createWorkspaceMember('member', $workspace);
        [, $reader] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createDocument($workspace, $owner, 'Private document');

        foreach ([[$editor, 'edit'], [$reader, 'read']] as [$user, $permission]) {
            DocumentAccessGrant::query()->create([
                'workspace_id' => $workspace->id,
                'document_id' => $document->id,
                'user_id' => $user->id,
                'granted_by_user_id' => $owner->id,
                'permission' => $permission,
            ]);
        }

        Sanctum::actingAs($editor);
        $this->patchJson($this->documentUrl($workspace, $document), [
            'title' => 'Edited through explicit grant',
        ])->assertOk()->assertJsonPath('data.title', 'Edited through explicit grant');

        Sanctum::actingAs($reader);
        $this->patchJson($this->documentUrl($workspace, $document), [
            'title' => 'Unauthorized edit',
        ])->assertForbidden();

        $this->assertSame('Edited through explicit grant', $document->fresh()->title);
    }

    public function test_another_member_and_workspace_admin_cannot_manage_a_private_document(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $member] = $this->createWorkspaceMember('member', $workspace);
        [, $admin] = $this->createWorkspaceMember('admin', $workspace);
        $document = $this->createDocument($workspace, $owner, 'Private document');

        foreach ([$member, $admin] as $user) {
            Sanctum::actingAs($user);
            $this->getJson($this->documentUrl($workspace, $document))->assertNotFound();
            $this->patchJson($this->documentUrl($workspace, $document), [
                'title' => 'Unauthorized update',
            ])->assertNotFound();
            $this->deleteJson($this->documentUrl($workspace, $document))->assertNotFound();
        }

        $this->assertSame('Private document', $document->fresh()->title);
        $this->assertNull($document->fresh()->deleted_at);
    }

    public function test_client_cannot_assign_document_owner_workspace_or_permissions(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $attacker] = $this->createWorkspaceMember('member');
        Sanctum::actingAs($attacker);

        $this->postJson($this->documentsUrl($workspace), [
            'title' => 'Forged document',
            'type' => 'report',
            'workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'permissions' => ['read'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['workspace_id', 'owner_user_id', 'permissions']);

        $document = $this->createDocument($workspace, $owner, 'Private document');
        Sanctum::actingAs($owner);
        $this->patchJson($this->documentUrl($workspace, $document), [
            'owner_user_id' => $attacker->id,
            'version' => 100,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_user_id', 'version']);

        $this->assertSame($owner->id, $document->fresh()->owner_user_id);
        $this->assertSame(1, $document->fresh()->version);
    }

    public function test_non_members_cannot_create_documents_or_access_cross_workspace_document_ids(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [$otherWorkspace, $otherUser] = $this->createWorkspaceMember('member');
        $document = $this->createDocument($workspace, $owner, 'Private document');
        Sanctum::actingAs($otherUser);

        $this->postJson($this->documentsUrl($workspace), [
            'title' => 'Unauthorized document',
            'type' => 'report',
        ])->assertForbidden();

        $this->getJson($this->documentUrl($otherWorkspace, $document))->assertNotFound();
        $this->patchJson($this->documentUrl($otherWorkspace, $document), [
            'title' => 'Cross-workspace update',
        ])->assertNotFound();
        $this->deleteJson($this->documentUrl($otherWorkspace, $document))->assertNotFound();

        $this->assertSame('Private document', $document->fresh()->title);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_suspended_member_cannot_update_a_document_that_was_shared_with_them(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $editor] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createDocument($workspace, $owner, 'Private document');
        DocumentAccessGrant::query()->create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $editor->id,
            'granted_by_user_id' => $owner->id,
            'permission' => 'edit',
        ]);
        WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $editor->id)
            ->update(['status' => 'suspended']);
        Sanctum::actingAs($editor);

        $this->patchJson($this->documentUrl($workspace, $document), [
            'title' => 'Suspended editor update',
        ])->assertNotFound();
        $this->assertSame('Private document', $document->fresh()->title);
    }

    public function test_unauthenticated_user_cannot_create_documents(): void
    {
        [$workspace] = $this->createWorkspaceMember('member');

        $this->postJson($this->documentsUrl($workspace), [
            'title' => 'Unauthenticated document',
            'type' => 'report',
        ])->assertUnauthorized();
    }

    private function createWorkspaceMember(string $role, ?Workspace $workspace = null): array
    {
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

    private function createDocument(Workspace $workspace, User $owner, string $title): Document
    {
        return Document::query()->create([
            'workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'title' => $title,
            'type' => 'report',
            'language' => 'en',
            'visibility' => 'private',
            'status' => 'draft',
            'content_format' => 'html',
            'content_html' => '<p>Document content</p>',
        ]);
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
