<?php

namespace Tests\Feature\Api\V1;

use App\Models\Document;
use App\Models\DocumentAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_grant_read_and_edit_access_to_active_workspace_members(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        [, $reader] = $this->createWorkspaceMember('member', $workspace);
        [, $editor] = $this->createWorkspaceMember('staff', $workspace);
        $document = $this->createPrivateDocument($workspace, $owner);
        Sanctum::actingAs($owner);

        $this->postJson($this->sharingUrl($workspace, $document), [
            'user_id' => $reader->id,
            'permission' => 'read',
        ])->assertCreated()
            ->assertJsonPath('data.userId', $reader->id)
            ->assertJsonPath('data.permission', 'read');

        $this->postJson($this->sharingUrl($workspace, $document), [
            'user_id' => $editor->id,
            'permission' => 'edit',
        ])->assertCreated()
            ->assertJsonPath('data.userId', $editor->id)
            ->assertJsonPath('data.permission', 'edit');

        $this->assertDatabaseCount('document_access_grants', 2);
        $this->assertTrue(Gate::forUser($owner)->allows('share', $document));
        $this->assertFalse(Gate::forUser($reader)->allows('share', $document));
        $this->assertFalse(Gate::forUser($editor)->allows('share', $document));
    }

    public function test_read_and_edit_grants_allow_only_their_document_actions(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $reader] = $this->createWorkspaceMember('member', $workspace);
        [, $editor] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createPrivateDocument($workspace, $owner);
        $this->createGrant($workspace, $document, $owner, $reader, 'read');
        $this->createGrant($workspace, $document, $owner, $editor, 'edit');

        Sanctum::actingAs($reader);
        $this->getJson($this->documentUrl($workspace, $document))->assertOk();
        $this->patchJson($this->documentUrl($workspace, $document), [
            'title' => 'Reader cannot edit',
        ])->assertForbidden();

        Sanctum::actingAs($editor);
        $this->getJson($this->documentUrl($workspace, $document))->assertOk();
        $this->patchJson($this->documentUrl($workspace, $document), [
            'title' => 'Editor can edit',
        ])->assertOk()->assertJsonPath('data.title', 'Editor can edit');

        $this->assertSame('Editor can edit', $document->fresh()->title);
    }

    public function test_owner_can_list_grants_and_suspended_users_are_not_listed(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        [, $reader] = $this->createWorkspaceMember('member', $workspace);
        [, $suspended] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createPrivateDocument($workspace, $owner);
        $this->createGrant($workspace, $document, $owner, $reader, 'read');
        $suspendedGrant = $this->createGrant($workspace, $document, $owner, $suspended, 'edit');
        WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $suspended->id)
            ->update(['status' => 'suspended']);
        Sanctum::actingAs($owner);

        $this->getJson($this->sharingUrl($workspace, $document))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.userId', $reader->id)
            ->assertJsonMissing(['userId' => $suspended->id]);

        $this->assertDatabaseHas('document_access_grants', ['id' => $suspendedGrant->id]);
    }

    public function test_owner_can_update_and_revoke_a_users_grant(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $member] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createPrivateDocument($workspace, $owner);
        $grant = $this->createGrant($workspace, $document, $owner, $member, 'read');
        Sanctum::actingAs($owner);

        $this->patchJson($this->sharingUrl($workspace, $document)."/{$member->id}", [
            'permission' => 'edit',
        ])->assertOk()->assertJsonPath('data.permission', 'edit');

        Sanctum::actingAs($member);
        $this->patchJson($this->documentUrl($workspace, $document), [
            'title' => 'Updated by editor',
        ])->assertOk();

        Sanctum::actingAs($owner);
        $this->deleteJson($this->sharingUrl($workspace, $document)."/{$member->id}")
            ->assertOk();
        $this->assertDatabaseMissing('document_access_grants', ['id' => $grant->id]);

        Sanctum::actingAs($member);
        $this->getJson($this->documentUrl($workspace, $document))->assertNotFound();
        $this->patchJson($this->documentUrl($workspace, $document), [
            'title' => 'Access is revoked',
        ])->assertNotFound();
    }

    public function test_non_owner_cannot_list_or_manage_document_grants(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $grantee] = $this->createWorkspaceMember('member', $workspace);
        [, $otherMember] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createPrivateDocument($workspace, $owner);
        $this->createGrant($workspace, $document, $owner, $grantee, 'edit');
        Sanctum::actingAs($grantee);

        $this->getJson($this->sharingUrl($workspace, $document))->assertForbidden();
        $this->postJson($this->sharingUrl($workspace, $document), [
            'user_id' => $otherMember->id,
            'permission' => 'read',
        ])->assertForbidden();
        $this->patchJson($this->sharingUrl($workspace, $document)."/{$grantee->id}", [
            'permission' => 'read',
        ])->assertForbidden();
        $this->deleteJson($this->sharingUrl($workspace, $document)."/{$grantee->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('document_access_grants', 1);
        $this->assertFalse(Gate::forUser($grantee)->allows('share', $document));
    }

    public function test_non_owner_cannot_manage_sharing_on_a_workspace_visible_document(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $admin] = $this->createWorkspaceMember('admin', $workspace);
        $document = $this->createPrivateDocument($workspace, $owner);
        $document->update(['visibility' => 'workspace']);
        Sanctum::actingAs($admin);

        $this->getJson($this->sharingUrl($workspace, $document))->assertForbidden();
        $this->postJson($this->sharingUrl($workspace, $document), [
            'user_id' => $owner->id,
            'permission' => 'read',
        ])->assertForbidden();
    }

    public function test_cross_workspace_and_suspended_member_grants_are_rejected(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        [, $outsider] = $this->createWorkspaceMember('member');
        [, $suspended] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createPrivateDocument($workspace, $owner);
        WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $suspended->id)
            ->update(['status' => 'suspended']);
        Sanctum::actingAs($owner);

        foreach ([$outsider->id, $suspended->id, (string) Str::uuid()] as $userId) {
            $this->postJson($this->sharingUrl($workspace, $document), [
                'user_id' => $userId,
                'permission' => 'read',
            ])->assertUnprocessable()
                ->assertJsonPath(
                    'errors.user_id.0',
                    'The selected user cannot be granted access to this document.'
                );
        }

        $this->assertDatabaseCount('document_access_grants', 0);
    }

    public function test_owner_cannot_grant_access_to_themselves(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $document = $this->createPrivateDocument($workspace, $owner);
        Sanctum::actingAs($owner);

        $this->postJson($this->sharingUrl($workspace, $document), [
            'user_id' => $owner->id,
            'permission' => 'edit',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseCount('document_access_grants', 0);
    }

    public function test_sharing_request_rejects_unsupported_permissions_and_server_fields(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        [, $member] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createPrivateDocument($workspace, $owner);
        Sanctum::actingAs($owner);

        $this->postJson($this->sharingUrl($workspace, $document), [
            'user_id' => $member->id,
            'permission' => 'share',
            'workspace_id' => $workspace->id,
            'granted_by_user_id' => $member->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['permission', 'workspace_id', 'granted_by_user_id']);

        $this->assertDatabaseCount('document_access_grants', 0);
    }

    public function test_workspace_admin_does_not_gain_access_to_another_members_private_document(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $admin] = $this->createWorkspaceMember('admin', $workspace);
        $document = $this->createPrivateDocument($workspace, $owner);
        Sanctum::actingAs($admin);

        $this->getJson($this->documentUrl($workspace, $document))->assertNotFound();
        $this->getJson($this->sharingUrl($workspace, $document))->assertNotFound();
        $this->postJson($this->sharingUrl($workspace, $document), [
            'user_id' => $owner->id,
            'permission' => 'read',
        ])->assertNotFound();
    }

    private function createWorkspaceMember(string $role, ?Workspace $workspace = null): array
    {
        $user = User::factory()->create();
        $workspace ??= Workspace::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Sharing Test Workspace',
            'slug' => 'sharing-'.fake()->unique()->slug(),
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

    private function createPrivateDocument(Workspace $workspace, User $owner): Document
    {
        return Document::query()->create([
            'workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'title' => 'Private sharing test',
            'type' => 'report',
            'language' => 'en',
            'visibility' => 'private',
            'status' => 'draft',
            'content_format' => 'html',
            'content_html' => '<p>Private content</p>',
        ]);
    }

    private function createGrant(
        Workspace $workspace,
        Document $document,
        User $owner,
        User $user,
        string $permission
    ): DocumentAccessGrant {
        return DocumentAccessGrant::query()->create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'granted_by_user_id' => $owner->id,
            'permission' => $permission,
        ]);
    }

    private function documentUrl(Workspace $workspace, Document $document): string
    {
        return "/api/v1/workspaces/{$workspace->id}/documents/{$document->id}";
    }

    private function sharingUrl(Workspace $workspace, Document $document): string
    {
        return $this->documentUrl($workspace, $document).'/sharing';
    }
}
