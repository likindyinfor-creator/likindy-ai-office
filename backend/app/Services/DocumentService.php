<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAuditEvent;
use App\Models\DocumentVersion;
use App\Models\Template;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $owner, Workspace $workspace, array $attributes): Document
    {
        return DB::transaction(function () use ($owner, $workspace, $attributes): Document {
            $workspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($owner)->authorize('create', [Document::class, $workspace]);
            $templateId = $attributes['template_id'] ?? null;
            $this->ensureTemplateBelongsToWorkspace($templateId, $workspace);

            $document = new Document;
            $document->workspace_id = $workspace->getKey();
            $document->owner_user_id = $owner->getKey();
            $document->created_by_user_id = $owner->getKey();
            $document->template_id = $templateId;
            $document->title = $attributes['title'];
            $document->type = $attributes['type'];
            $document->language = $attributes['language'] ?? 'en';
            $document->visibility = $attributes['visibility'] ?? 'private';
            $document->status = $attributes['status'] ?? 'draft';
            $document->content_format = $attributes['content_format'] ?? 'html';
            $document->content_html = $attributes['content_html'] ?? null;
            $document->latex_source = $attributes['latex_source'] ?? null;
            $document->typeset = false;
            $document->word_count = $this->wordCount(
                $document->content_format,
                $document->content_html,
                $document->latex_source
            );
            $document->version = 1;
            $document->save();

            $this->recordVersion($document, $owner, null);

            return $document;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Document $document, User $actor, array $attributes): Document
    {
        return DB::transaction(function () use ($document, $actor, $attributes): Document {
            $workspace = Workspace::query()
                ->whereKey($document->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $document = Document::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('update', $document);
            $templateId = array_key_exists('template_id', $attributes)
                ? $attributes['template_id']
                : $document->template_id;
            $this->ensureTemplateBelongsToWorkspace($templateId, $workspace);

            if (array_key_exists('visibility', $attributes) && $document->owner_user_id !== $actor->getKey()) {
                abort(403);
            }

            $changeSummary = $attributes['change_summary'] ?? null;
            unset($attributes['change_summary']);

            foreach ([
                'title',
                'type',
                'language',
                'visibility',
                'status',
                'content_format',
                'content_html',
                'latex_source',
                'template_id',
            ] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $document->{$field} = $attributes[$field];
                }
            }

            $document->word_count = $this->wordCount(
                $document->content_format,
                $document->content_html,
                $document->latex_source
            );
            $document->version++;
            $document->save();

            $this->recordVersion($document, $actor, $changeSummary);

            return $document->refresh();
        });
    }

    public function delete(Document $document, User $actor): void
    {
        DB::transaction(function () use ($document, $actor): void {
            $workspace = Workspace::query()
                ->whereKey($document->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $record = Document::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $record);
            $record->delete();
            $this->recordLifecycleEvent($record, $actor, 'deleted');
        });
    }

    public function restore(Document $document, User $actor): Document
    {
        return DB::transaction(function () use ($document, $actor): Document {
            $workspace = Workspace::query()
                ->whereKey($document->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $record = Document::withTrashed()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('restore', $record);
            $record->restore();
            $this->recordLifecycleEvent($record, $actor, 'restored');

            return $record->refresh();
        });
    }

    public function restoreVersion(Document $document, User $actor, int $versionNumber): Document
    {
        return DB::transaction(function () use ($document, $actor, $versionNumber): Document {
            $workspace = Workspace::query()
                ->whereKey($document->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $document = Document::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('update', $document);

            $version = DocumentVersion::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('document_id', $document->getKey())
                ->where('version_number', $versionNumber)
                ->lockForUpdate()
                ->firstOrFail();

            $document->title = $version->title;
            $document->status = $version->status;
            $document->content_html = $version->content_html;
            $document->latex_source = $version->latex_source;
            $document->word_count = $this->wordCount(
                $document->content_format,
                $document->content_html,
                $document->latex_source
            );
            $document->version++;
            $document->save();

            $this->recordVersion(
                $document,
                $actor,
                "Restored from version {$version->version_number}."
            );

            return $document->refresh();
        });
    }

    private function ensureTemplateBelongsToWorkspace(?string $templateId, Workspace $workspace): void
    {
        if ($templateId === null) {
            return;
        }

        $allowed = Template::query()
            ->whereKey($templateId)
            ->where(function ($query) use ($workspace): void {
                $query->whereNull('workspace_id')
                    ->orWhere('workspace_id', $workspace->getKey());
            })
            ->exists();

        if (! $allowed) {
            throw ValidationException::withMessages([
                'template_id' => 'The selected template is unavailable in this workspace.',
            ]);
        }
    }

    private function wordCount(string $format, ?string $html, ?string $latex): int
    {
        $content = $format === 'latex' ? ($latex ?? '') : strip_tags($html ?? '');
        preg_match_all('/[\p{L}\p{N}]+/u', $content, $matches);

        return count($matches[0]);
    }

    private function recordVersion(Document $document, User $actor, ?string $changeSummary): void
    {
        DocumentVersion::query()->create([
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->getKey(),
            'changed_by_user_id' => $actor->getKey(),
            'version_number' => $document->version,
            'title' => $document->title,
            'status' => $document->status,
            'content_html' => $document->content_html,
            'latex_source' => $document->latex_source,
            'change_summary' => $changeSummary,
            'created_at' => now(),
        ]);
    }

    private function recordLifecycleEvent(Document $document, ?User $actor, string $event): void
    {
        DocumentAuditEvent::query()->create([
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'event' => $event,
            'created_at' => now(),
        ]);
    }
}
