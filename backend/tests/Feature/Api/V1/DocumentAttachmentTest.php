<?php

namespace Tests\Feature\Api\V1;

use App\Models\Document;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\DocumentAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class DocumentAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');
    }

    public function test_owner_can_upload_list_download_and_delete_an_attachment(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        Sanctum::actingAs($owner);

        $response = $this->post($this->attachmentsUrl($workspace, $document), [
            'file' => UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\nattachment content"),
        ], ['Accept' => 'application/json']);
        $response->assertCreated()
            ->assertJsonPath('data.documentId', $document->id)
            ->assertJsonPath('data.originalFilename', 'report.pdf')
            ->assertJsonPath('data.mimeType', 'application/pdf')
            ->assertJsonPath('data.uploadedByUserId', $owner->id)
            ->assertJsonMissingPath('data.storagePath');

        $attachment = DocumentAttachment::query()->findOrFail($response->json('data.id'));
        $this->assertSame(strlen("%PDF-1.4\nattachment content"), $attachment->size);
        Storage::disk('attachments')->assertExists($attachment->storage_path);

        $this->getJson($this->attachmentsUrl($workspace, $document))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $attachment->id);

        $download = $this->get($this->attachmentUrl($workspace, $document, $attachment).'/download');
        $download->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));

        $this->deleteJson($this->attachmentUrl($workspace, $document, $attachment))
            ->assertOk();
        $this->assertDatabaseMissing('document_attachments', ['id' => $attachment->id]);
        Storage::disk('attachments')->assertMissing($attachment->storage_path);
    }

    public function test_attachments_use_the_configured_private_storage_disk(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        config(['filesystems.attachments_disk' => 'r2']);
        Storage::fake('r2');
        Sanctum::actingAs($owner);

        $response = $this->post($this->attachmentsUrl($workspace, $document), [
            'file' => UploadedFile::fake()->createWithContent('private.pdf', "%PDF-1.4\nprivate"),
        ], ['Accept' => 'application/json'])->assertCreated();

        $attachment = DocumentAttachment::query()->findOrFail($response->json('data.id'));
        Storage::disk('r2')->assertExists($attachment->storage_path);
        Storage::disk('attachments')->assertMissing($attachment->storage_path);
        $this->assertSame('private', config('filesystems.disks.r2.visibility'));
    }

    public function test_editor_can_upload_and_delete_but_reader_can_only_list_and_download(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $editor] = $this->createWorkspaceMember('member', $workspace);
        [, $reader] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createDocument($workspace, $owner);
        $this->createGrant($workspace, $document, $owner, $editor, 'edit');
        $this->createGrant($workspace, $document, $owner, $reader, 'read');
        Sanctum::actingAs($editor);
        $upload = $this->post($this->attachmentsUrl($workspace, $document), [
            'file' => UploadedFile::fake()->createWithContent('editor.txt', 'editor file'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $attachment = DocumentAttachment::query()->findOrFail($upload->json('data.id'));

        Sanctum::actingAs($reader);
        $this->getJson($this->attachmentsUrl($workspace, $document))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->get($this->attachmentUrl($workspace, $document, $attachment).'/download')
            ->assertOk();
        $this->post($this->attachmentsUrl($workspace, $document), [
            'file' => UploadedFile::fake()->createWithContent('reader.txt', 'reader file'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson($this->attachmentUrl($workspace, $document, $attachment))
            ->assertForbidden();

        Sanctum::actingAs($editor);
        $this->deleteJson($this->attachmentUrl($workspace, $document, $attachment))->assertOk();
    }

    public function test_upload_rejects_unsupported_extensions_mime_mismatch_and_oversized_files(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        Sanctum::actingAs($owner);

        $this->post($this->attachmentsUrl($workspace, $document), [
            'file' => UploadedFile::fake()->createWithContent('malware.exe', 'not an allowed file'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $image = UploadedFile::fake()->image('real-image.png');
        $mismatchedExtension = new UploadedFile(
            $image->getPathname(),
            'image.txt',
            null,
            UPLOAD_ERR_OK,
            true
        );
        $this->post($this->attachmentsUrl($workspace, $document), [
            'file' => $mismatchedExtension,
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->post($this->attachmentsUrl($workspace, $document), [
            'file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('document_attachments', 0);
    }

    public function test_private_file_is_removed_when_attachment_record_creation_fails(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        $file = UploadedFile::fake()->createWithContent('failure.pdf', "%PDF-1.4\ncontent");
        DB::listen(function ($query): void {
            $sql = strtolower(ltrim($query->sql));

            if (str_starts_with($sql, 'insert') && str_contains($sql, 'document_attachments')) {
                throw new RuntimeException('Simulated attachment record failure.');
            }
        });

        try {
            app(DocumentAttachmentService::class)->upload($document, $owner, $file);
            $this->fail('The simulated database failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated attachment record failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertSame([], Storage::disk('attachments')->allFiles());
    }

    public function test_member_without_access_and_workspace_admin_cannot_upload_list_or_download_private_attachments(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $admin] = $this->createWorkspaceMember('admin', $workspace);
        [, $reader] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createDocument($workspace, $owner);
        $attachment = $this->createStoredAttachment($workspace, $document, $owner);

        foreach ([$admin, $reader] as $user) {
            Sanctum::actingAs($user);
            $this->getJson($this->attachmentsUrl($workspace, $document))->assertNotFound();
            $this->get($this->attachmentUrl($workspace, $document, $attachment).'/download')
                ->assertNotFound();
            $this->post($this->attachmentsUrl($workspace, $document), [
                'file' => UploadedFile::fake()->createWithContent('no.txt', 'no access'),
            ], ['Accept' => 'application/json'])->assertNotFound();
            $this->deleteJson($this->attachmentUrl($workspace, $document, $attachment))
                ->assertNotFound();
        }

        Storage::disk('attachments')->assertExists($attachment->storage_path);
    }

    public function test_attachment_ids_cannot_be_used_across_workspaces_or_documents(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        [$otherWorkspace, $otherOwner] = $this->createWorkspaceMember('owner');
        $document = $this->createDocument($workspace, $owner);
        $otherDocument = $this->createDocument($otherWorkspace, $otherOwner);
        $attachment = $this->createStoredAttachment($workspace, $document, $owner);
        Sanctum::actingAs($otherOwner);

        $this->getJson($this->attachmentsUrl($otherWorkspace, $otherDocument))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->get($this->attachmentUrl($otherWorkspace, $otherDocument, $attachment).'/download')
            ->assertNotFound();
        $this->deleteJson($this->attachmentUrl($otherWorkspace, $otherDocument, $attachment))
            ->assertNotFound();
        Storage::disk('attachments')->assertExists($attachment->storage_path);
    }

    public function test_revoked_access_and_suspended_members_cannot_use_attachments(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $reader] = $this->createWorkspaceMember('member', $workspace);
        $document = $this->createDocument($workspace, $owner);
        $grant = $this->createGrant($workspace, $document, $owner, $reader, 'read');
        $attachment = $this->createStoredAttachment($workspace, $document, $owner);
        Sanctum::actingAs($reader);
        $this->get($this->attachmentUrl($workspace, $document, $attachment).'/download')->assertOk();

        $grant->delete();
        $this->getJson($this->attachmentsUrl($workspace, $document))->assertNotFound();
        $this->get($this->attachmentUrl($workspace, $document, $attachment).'/download')
            ->assertNotFound();

        $this->createGrant($workspace, $document, $owner, $reader, 'read');
        WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $reader->id)
            ->update(['status' => 'suspended']);
        $this->get($this->attachmentUrl($workspace, $document, $attachment).'/download')
            ->assertNotFound();
    }

    private function createWorkspaceMember(string $role, ?Workspace $workspace = null): array
    {
        $user = User::factory()->create();
        $workspace ??= Workspace::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Attachment Test Workspace',
            'slug' => 'attachment-'.fake()->unique()->slug(),
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
            'title' => 'Private attachment document',
            'type' => 'report',
            'language' => 'en',
            'visibility' => 'private',
            'status' => 'draft',
            'content_format' => 'html',
        ]);
    }

    private function createGrant(
        Workspace $workspace,
        Document $document,
        User $owner,
        User $grantee,
        string $permission
    ): DocumentAccessGrant {
        return DocumentAccessGrant::query()->create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $grantee->id,
            'granted_by_user_id' => $owner->id,
            'permission' => $permission,
        ]);
    }

    private function createStoredAttachment(
        Workspace $workspace,
        Document $document,
        User $owner
    ): DocumentAttachment {
        $path = "workspaces/{$workspace->id}/documents/{$document->id}/".fake()->uuid().'.pdf';
        Storage::disk('attachments')->put($path, '%PDF-1.4 private');

        return DocumentAttachment::query()->create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'uploaded_by_user_id' => $owner->id,
            'original_filename' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'size' => 16,
            'storage_path' => $path,
        ]);
    }

    private function attachmentsUrl(Workspace $workspace, Document $document): string
    {
        return "/api/v1/workspaces/{$workspace->id}/documents/{$document->id}/attachments";
    }

    private function attachmentUrl(
        Workspace $workspace,
        Document $document,
        DocumentAttachment $attachment
    ): string {
        return $this->attachmentsUrl($workspace, $document)."/{$attachment->id}";
    }
}
