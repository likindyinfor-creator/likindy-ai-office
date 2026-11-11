<?php

namespace Tests\Feature\Api\V1;

use App\Models\Document;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_versions_newest_first_and_view_a_specific_version(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        $this->createVersion($workspace, $document, $owner, 1, 'First title', '<p>First contents</p>');
        $this->createVersion($workspace, $document, $owner, 2, 'Second title', '<p>Second contents</p>');
        Sanctum::actingAs($owner);

        $this->getJson($this->versionsUrl($workspace, $document))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.versionNumber', 2)
            ->assertJsonPath('data.1.versionNumber', 1);

        $versionResponse = $this->getJson($this->versionsUrl($workspace, $document).'/1');
        $versionResponse
            ->assertOk()
            ->assertJsonPath('data.versionNumber', 1)
            ->assertJsonPath('data.title', 'First title')
            ->assertJsonPath('data.contentHtml', '<p>First contents</p>')
            ->assertJsonPath('data.changedByUserId', $owner->id);
        $this->assertNotNull($versionResponse->json('data.createdAt'));

        $this->getJson($this->versionsUrl($workspace, $document).'/3')->assertNotFound();
    }

    public function test_restoring_a_version_creates_a_new_version_and_preserves_history(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        $this->createVersion($workspace, $document, $owner, 1, 'Original title', '<p>Original words</p>');
        $this->createVersion($workspace, $document, $owner, 2, 'New title', '<p>New words</p>');
        $document->update([
            'title' => 'Current title',
            'status' => 'completed',
            'content_html' => '<p>Current words</p>',
            'version' => 2,
        ]);
        Sanctum::actingAs($owner);

        $this->postJson($this->versionsUrl($workspace, $document).'/1/restore')
            ->assertOk()
            ->assertJsonPath('data.title', 'Original title')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.contentHtml', '<p>Original words</p>')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.wordCount', 2);

        $this->assertDatabaseCount('document_versions', 3);
        $this->assertDatabaseHas('document_versions', [
            'document_id' => $document->id,
            'version_number' => 1,
            'title' => 'Original title',
        ]);
        $this->assertDatabaseHas('document_versions', [
            'document_id' => $document->id,
            'version_number' => 2,
            'title' => 'New title',
        ]);
        $this->assertDatabaseHas('document_versions', [
            'document_id' => $document->id,
            'version_number' => 3,
            'title' => 'Original title',
            'changed_by_user_id' => $owner->id,
            'change_summary' => 'Restored from version 1.',
        ]);

        $restoredVersion = DocumentVersion::query()
            ->where('document_id', $document->id)
            ->where('version_number', 3)
            ->firstOrFail();
        $this->assertNotNull($restoredVersion->created_at);
        $this->assertSame('Original title', $document->fresh()->title);
    }

    public function test_read_grant_can_view_history_but_cannot_restore(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $reader] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createDocument($workspace, $owner);
        $this->createVersion($workspace, $document, $owner, 1, 'Private title', '<p>Private text</p>');
        $this->createGrant($workspace, $document, $owner, $reader, 'read');
        Sanctum::actingAs($reader);

        $this->getJson($this->versionsUrl($workspace, $document))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Private title');
        $this->getJson($this->versionsUrl($workspace, $document).'/1')
            ->assertOk()
            ->assertJsonPath('data.contentHtml', '<p>Private text</p>');
        $this->postJson($this->versionsUrl($workspace, $document).'/1/restore')
            ->assertForbidden();

        $this->assertSame('Current title', $document->fresh()->title);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_edit_grant_can_restore_an_earlier_version(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $editor] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createDocument($workspace, $owner);
        $this->createVersion($workspace, $document, $owner, 1, 'Prior title', '<p>Prior body</p>');
        $this->createGrant($workspace, $document, $owner, $editor, 'edit');
        Sanctum::actingAs($editor);

        $this->postJson($this->versionsUrl($workspace, $document).'/1/restore')
            ->assertOk()
            ->assertJsonPath('data.title', 'Prior title')
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('document_versions', [
            'document_id' => $document->id,
            'version_number' => 2,
            'changed_by_user_id' => $editor->id,
            'change_summary' => 'Restored from version 1.',
        ]);
    }

    public function test_ungranted_member_and_workspace_admin_cannot_view_private_document_history(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $member] = $this->createWorkspaceMember('member', $workspace);
        [, $admin] = $this->createWorkspaceMember('admin', $workspace);
        $document = $this->createDocument($workspace, $owner);
        $this->createVersion($workspace, $document, $owner, 1, 'Private title', '<p>Private body</p>');

        foreach ([$member, $admin] as $user) {
            Sanctum::actingAs($user);
            $this->getJson($this->versionsUrl($workspace, $document))->assertNotFound();
            $this->getJson($this->versionsUrl($workspace, $document).'/1')->assertNotFound();
            $this->postJson($this->versionsUrl($workspace, $document).'/1/restore')
                ->assertNotFound();
        }
    }

    public function test_user_cannot_view_or_restore_versions_from_another_workspace(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [$otherWorkspace, $otherUser] = $this->createWorkspaceMember('member');
        $document = $this->createDocument($workspace, $owner);
        $this->createVersion($workspace, $document, $owner, 1, 'Private title', '<p>Private body</p>');
        Sanctum::actingAs($otherUser);

        $this->getJson($this->versionsUrl($otherWorkspace, $document))->assertNotFound();
        $this->getJson($this->versionsUrl($otherWorkspace, $document).'/1')->assertNotFound();
        $this->postJson($this->versionsUrl($otherWorkspace, $document).'/1/restore')
            ->assertNotFound();
    }

    private function createWorkspaceMember(string $role, ?Workspace $workspace = null): array
    {
        $user = User::factory()->create();
        $workspace ??= Workspace::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Version Test Workspace',
            'slug' => 'version-'.fake()->unique()->slug(),
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

    private function createDocument(Workspace $workspace, User $owner): Document
    {
        return Document::query()->create([
            'workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'title' => 'Current title',
            'type' => 'report',
            'language' => 'en',
            'visibility' => 'private',
            'status' => 'draft',
            'content_format' => 'html',
            'content_html' => '<p>Current content</p>',
            'version' => 1,
        ]);
    }

    private function createVersion(
        Workspace $workspace,
        Document $document,
        User $actor,
        int $number,
        string $title,
        string $content
    ): DocumentVersion {
        return DocumentVersion::query()->create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'changed_by_user_id' => $actor->id,
            'version_number' => $number,
            'title' => $title,
            'status' => 'draft',
            'content_html' => $content,
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

    private function versionsUrl(Workspace $workspace, Document $document): string
    {
        return "/api/v1/workspaces/{$workspace->id}/documents/{$document->id}/versions";
    }
}
