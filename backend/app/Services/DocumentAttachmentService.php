<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentAttachmentService
{
    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'txt' => 'text/plain',
    ];

    public static function supports(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        return isset(self::MIME_TYPES[$extension]) && self::MIME_TYPES[$extension] === $mimeType;
    }

    /**
     * @return Collection<int, DocumentAttachment>
     */
    public function list(Document $document): Collection
    {
        return DocumentAttachment::query()
            ->where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    public function upload(Document $document, User $actor, UploadedFile $file): DocumentAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        if (! self::supports($file)) {
            throw new RuntimeException('The uploaded file type is not supported.');
        }

        $filename = $this->safeOriginalFilename($file->getClientOriginalName(), $extension);
        $storagePath = sprintf(
            'workspaces/%s/documents/%s/%s.%s',
            $document->workspace_id,
            $document->getKey(),
            Str::uuid(),
            $extension
        );
        $stored = false;

        try {
            $attachment = DB::transaction(function () use (
                $document,
                $actor,
                $file,
                $filename,
                $mimeType,
                $storagePath
            ): DocumentAttachment {
                [$record, $workspace] = $this->lockDocumentAndWorkspace($document);
                Gate::forUser($actor)->authorize('update', $record);
                $storedPath = $this->attachmentDisk()->putFileAs(
                    dirname($storagePath),
                    $file,
                    basename($storagePath)
                );

                if ($storedPath === false) {
                    throw new RuntimeException('The attachment could not be stored.');
                }

                return DocumentAttachment::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'document_id' => $record->getKey(),
                    'uploaded_by_user_id' => $actor->getKey(),
                    'original_filename' => $filename,
                    'mime_type' => $mimeType,
                    'size' => $file->getSize(),
                    'storage_path' => $storedPath,
                ]);
            });
            $stored = true;

            return $attachment;
        } finally {
            if (! $stored && $this->attachmentDisk()->exists($storagePath)) {
                $this->deleteStoredFile($storagePath);
            }
        }
    }

    public function download(DocumentAttachment $attachment): StreamedResponse|BinaryFileResponse
    {
        $disk = $this->attachmentDisk();
        abort_unless($disk->exists($attachment->storage_path), 404);

        return $disk->download($attachment->storage_path, $attachment->original_filename, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function delete(
        Document $document,
        DocumentAttachment $attachment,
        User $actor
    ): void {
        $storagePath = DB::transaction(function () use ($document, $attachment, $actor): string {
            [$record, $workspace] = $this->lockDocumentAndWorkspace($document);
            Gate::forUser($actor)->authorize('update', $record);
            $storedAttachment = DocumentAttachment::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('document_id', $record->getKey())
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $storedPath = $storedAttachment->storage_path;
            $storedAttachment->delete();

            return $storedPath;
        });

        $this->deleteStoredFile($storagePath);
    }

    /**
     * @return array{Document, Workspace}
     */
    private function lockDocumentAndWorkspace(Document $document): array
    {
        $workspace = Workspace::query()
            ->whereKey($document->workspace_id)
            ->lockForUpdate()
            ->firstOrFail();
        $record = Document::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($document->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return [$record, $workspace];
    }

    private function safeOriginalFilename(string $filename, string $extension): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[^\pL\pN._ -]/u', '_', $filename) ?? '';
        $filename = trim(mb_substr($filename, 0, 255));

        return $filename !== '' ? $filename : "attachment.{$extension}";
    }

    private function deleteStoredFile(string $storagePath): void
    {
        if (! $this->attachmentDisk()->delete($storagePath)) {
            throw new RuntimeException('The attachment file could not be removed from private storage.');
        }
    }

    private function attachmentDisk(): FilesystemAdapter
    {
        return Storage::disk(config('filesystems.attachments_disk', 'attachments'));
    }
}
