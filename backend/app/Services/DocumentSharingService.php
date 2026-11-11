<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentSharingService
{
    /**
     * @return Collection<int, DocumentAccessGrant>
     */
    public function activeGrants(Document $document, User $actor): Collection
    {
        Gate::forUser($actor)->authorize('share', $document);

        return DocumentAccessGrant::query()
            ->with('user')
            ->where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->getKey())
            ->whereExists(function ($query) use ($document): void {
                $query->selectRaw('1')
                    ->from('workspace_memberships')
                    ->whereColumn('workspace_memberships.user_id', 'document_access_grants.user_id')
                    ->where('workspace_memberships.workspace_id', $document->workspace_id)
                    ->where('workspace_memberships.status', 'active');
            })
            ->orderBy('created_at')
            ->get();
    }

    public function grant(
        Document $document,
        User $actor,
        string $userId,
        string $permission
    ): DocumentAccessGrant {
        return DB::transaction(function () use ($document, $actor, $userId, $permission): DocumentAccessGrant {
            [$document, $workspace] = $this->lockDocumentAndWorkspace($document);
            Gate::forUser($actor)->authorize('share', $document);
            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($membership === null || $userId === $actor->getKey()) {
                throw ValidationException::withMessages([
                    'user_id' => 'The selected user cannot be granted access to this document.',
                ]);
            }

            $grant = DocumentAccessGrant::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('document_id', $document->getKey())
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($grant === null) {
                $grant = new DocumentAccessGrant;
                $grant->workspace_id = $workspace->getKey();
                $grant->document_id = $document->getKey();
                $grant->user_id = $userId;
                $grant->granted_by_user_id = $actor->getKey();
            }

            $grant->permission = $permission;
            $grant->save();

            return $grant->load('user');
        });
    }

    public function update(
        Document $document,
        User $actor,
        string $userId,
        string $permission
    ): DocumentAccessGrant {
        return DB::transaction(function () use ($document, $actor, $userId, $permission): DocumentAccessGrant {
            [$document, $workspace] = $this->lockDocumentAndWorkspace($document);
            Gate::forUser($actor)->authorize('share', $document);
            $this->lockActiveMembership($workspace, $userId);
            $grant = $this->findGrant($document, $workspace, $userId, true);
            $grant->permission = $permission;
            $grant->save();

            return $grant->load('user');
        });
    }

    public function revoke(Document $document, User $actor, string $userId): void
    {
        DB::transaction(function () use ($document, $actor, $userId): void {
            [$document, $workspace] = $this->lockDocumentAndWorkspace($document);
            Gate::forUser($actor)->authorize('share', $document);
            $this->findGrant($document, $workspace, $userId, true)->delete();
        });
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

    private function lockActiveMembership(Workspace $workspace, string $userId): void
    {
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'user_id' => 'The selected user cannot be granted access to this document.',
            ]);
        }
    }

    private function findGrant(
        Document $document,
        Workspace $workspace,
        string $userId,
        bool $lock
    ): DocumentAccessGrant {
        $query = DocumentAccessGrant::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('document_id', $document->getKey())
            ->where('user_id', $userId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
