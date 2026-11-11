<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentAuditEvent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DocumentRetentionService
{
    public function cutoff(): Carbon
    {
        return now()->subDays(config('documents.deleted_retention_days', 30));
    }

    public function expiredCount(): int
    {
        return $this->expiredDocuments()->count();
    }

    public function purge(string $documentId): bool
    {
        return DB::transaction(function () use ($documentId): bool {
            $workspaceId = Document::withTrashed()->whereKey($documentId)->value('workspace_id');
            if ($workspaceId === null) {
                return false;
            }

            Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            $document = Document::withTrashed()
                ->where('workspace_id', $workspaceId)
                ->whereKey($documentId)
                ->lockForUpdate()
                ->first();

            if ($document === null || ! $this->isExpired($document)) {
                return false;
            }

            $attachments = DocumentAttachment::query()
                ->where('workspace_id', $document->workspace_id)
                ->where('document_id', $document->getKey())
                ->lockForUpdate()
                ->get();

            foreach ($attachments as $attachment) {
                if (! $this->isSafeAttachmentPath($attachment->storage_path, $document)) {
                    throw new RuntimeException('An attachment has an invalid private storage path.');
                }
            }

            $disk = Storage::disk(config('filesystems.attachments_disk', 'attachments'));
            foreach ($attachments as $attachment) {
                if ($disk->exists($attachment->storage_path) && ! $disk->delete($attachment->storage_path)) {
                    throw new RuntimeException('A document attachment could not be removed from private storage.');
                }
            }

            $workspaceId = $document->workspace_id;
            $id = $document->getKey();
            $attachments->each->delete();
            $document->forceDelete();

            DocumentAuditEvent::query()->create([
                'workspace_id' => $workspaceId,
                'document_id' => $id,
                'actor_user_id' => null,
                'event' => 'purged',
                'created_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * @return Builder<Document>
     */
    private function expiredDocuments(): Builder
    {
        return Document::onlyTrashed()->where('deleted_at', '<=', $this->cutoff());
    }

    private function isExpired(Document $document): bool
    {
        return $document->trashed()
            && $document->deleted_at !== null
            && $document->deleted_at->lessThanOrEqualTo($this->cutoff());
    }

    private function isSafeAttachmentPath(string $path, Document $document): bool
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
        $pattern = '#^workspaces/'.$uuid.'/documents/'.$uuid.'/'.$uuid.'\.(pdf|png|jpg|jpeg|txt)$#i';

        return preg_match($pattern, $path) === 1
            && str_contains($path, '/documents/'.$document->getKey().'/');
    }
}
