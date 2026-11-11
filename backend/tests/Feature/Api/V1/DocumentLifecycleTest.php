<?php

namespace Tests\Feature\Api\V1;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentAuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');
        config(['documents.deleted_retention_days' => 30]);
    }

    public function test_owner_can_soft_delete_and_restore_document_and_audit_events_are_recorded(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        $attachment = $this->createAttachment($document, $workspace, $owner);
        Sanctum::actingAs($owner);

        $this->deleteJson($this->documentUrl($workspace, $document))->assertOk();
        $this->assertSoftDeleted('documents', ['id' => $document->id]);
        $this->getJson($this->documentsUrl($workspace))->assertJsonCount(0, 'data');
        $this->getJson($this->documentUrl($workspace, $document))->assertNotFound();
        $this->getJson($this->documentsUrl($workspace).'?search=Lifecycle')
            ->assertJsonCount(0, 'data');
        $this->getJson($this->attachmentsUrl($workspace, $document))->assertNotFound();
        $this->get($this->attachmentUrl($workspace, $document, $attachment).'/download')
            ->assertNotFound();
        Storage::disk('attachments')->assertExists($attachment->storage_path);

        $this->postJson($this->documentUrl($workspace, $document).'/restore')
            ->assertOk()
            ->assertJsonPath('data.id', $document->id);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('document_audit_events', [
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'actor_user_id' => $owner->id,
            'event' => 'deleted',
        ]);
        $this->assertDatabaseHas('document_audit_events', [
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'actor_user_id' => $owner->id,
            'event' => 'restored',
        ]);
        $this->assertDatabaseCount('document_audit_events', 2);
        Storage::disk('attachments')->assertExists($attachment->storage_path);
    }

    public function test_non_owner_and_unrelated_user_cannot_restore_a_soft_deleted_document(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $member] = $this->createWorkspaceMember('member', $workspace);
        [, $outsider] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        $document->delete();

        Sanctum::actingAs($member);
        $this->postJson($this->documentUrl($workspace, $document).'/restore')->assertForbidden();
        Sanctum::actingAs($outsider);
        $this->postJson($this->documentUrl($workspace, $document).'/restore')->assertForbidden();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_expired_documents_are_purged_but_recent_soft_deleted_documents_remain(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $expired = $this->createDocument($workspace, $owner, 'Expired');
        $recent = $this->createDocument($workspace, $owner, 'Recent');
        $active = $this->createDocument($workspace, $owner, 'Still active');
        $expired->delete();
        $recent->delete();
        Document::withTrashed()->whereKey($expired->id)->update(['deleted_at' => now()->subDays(31)]);
        Document::withTrashed()->whereKey($recent->id)->update(['deleted_at' => now()->subDays(2)]);

        $this->artisan('documents:purge-expired')
            ->expectsOutput('Purged 1 expired document(s).')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('documents', ['id' => $expired->id]);
        $this->assertSoftDeleted('documents', ['id' => $recent->id]);
        $this->assertDatabaseHas('documents', ['id' => $active->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('document_audit_events', [
            'document_id' => $expired->id,
            'event' => 'purged',
            'actor_user_id' => null,
        ]);
    }

    public function test_dry_run_does_not_delete_and_repeated_purge_is_safe(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $expired = $this->createDocument($workspace, $owner, 'Expired');
        $expired->delete();
        Document::withTrashed()->whereKey($expired->id)->update(['deleted_at' => now()->subDays(31)]);

        $this->artisan('documents:purge-expired', ['--dry-run' => true])
            ->expectsOutput('1 document(s) eligible for purge; no data was deleted.')
            ->assertExitCode(0);
        $this->assertSoftDeleted('documents', ['id' => $expired->id]);
        $this->assertDatabaseMissing('document_audit_events', [
            'document_id' => $expired->id,
            'event' => 'purged',
        ]);

        $this->artisan('documents:purge-expired')->assertExitCode(0);
        $this->artisan('documents:purge-expired')
            ->expectsOutput('Purged 0 expired document(s).')
            ->assertExitCode(0);
        $this->assertDatabaseMissing('documents', ['id' => $expired->id]);
        $this->assertSame(
            1,
            DocumentAuditEvent::query()->where('document_id', $expired->id)->where('event', 'purged')->count()
        );
    }

    public function test_purge_deletes_only_attachment_files_owned_by_the_expired_document(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $expired = $this->createDocument($workspace, $owner, 'Expired');
        $active = $this->createDocument($workspace, $owner, 'Active');
        $expired->delete();
        Document::withTrashed()->whereKey($expired->id)->update(['deleted_at' => now()->subDays(31)]);
        $expiredAttachment = $this->createAttachment($expired, $workspace, $owner);
        $activeAttachment = $this->createAttachment($active, $workspace, $owner);

        $this->artisan('documents:purge-expired')->assertExitCode(0);

        $this->assertDatabaseMissing('document_attachments', ['id' => $expiredAttachment->id]);
        $this->assertDatabaseHas('document_attachments', ['id' => $activeAttachment->id]);
        Storage::disk('attachments')->assertMissing($expiredAttachment->storage_path);
        Storage::disk('attachments')->assertExists($activeAttachment->storage_path);
    }

    public function test_expired_document_cannot_be_restored_after_retention_period(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        $document->delete();
        Document::withTrashed()->whereKey($document->id)->update(['deleted_at' => now()->subDays(31)]);
        Sanctum::actingAs($owner);

        $this->postJson($this->documentUrl($workspace, $document).'/restore')->assertForbidden();
        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    private function createWorkspaceMember(string $role, ?Workspace $workspace = null): array
    {
        $user = User::factory()->create();
        $workspace ??= Workspace::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Lifecycle Test Workspace',
            'slug' => 'lifecycle-'.fake()->unique()->slug(),
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

    private function createDocument(Workspace $workspace, User $owner, string $title = 'Lifecycle document'): Document
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
        ]);
    }

    private function createAttachment(
        Document $document,
        Workspace $workspace,
        User $owner
    ): DocumentAttachment {
        $path = "workspaces/{$workspace->id}/documents/{$document->id}/".fake()->uuid().'.pdf';
        Storage::disk('attachments')->put($path, '%PDF-1.4 lifecycle test');

        return DocumentAttachment::query()->create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'uploaded_by_user_id' => $owner->id,
            'original_filename' => 'lifecycle.pdf',
            'mime_type' => 'application/pdf',
            'size' => 22,
            'storage_path' => $path,
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

    private function attachmentsUrl(Workspace $workspace, Document $document): string
    {
        return $this->documentUrl($workspace, $document).'/attachments';
    }

    private function attachmentUrl(
        Workspace $workspace,
        Document $document,
        DocumentAttachment $attachment
    ): string {
        return $this->attachmentsUrl($workspace, $document)."/{$attachment->id}";
    }
}
