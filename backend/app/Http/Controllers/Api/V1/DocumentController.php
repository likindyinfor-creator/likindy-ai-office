<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CreateDocumentRequest;
use App\Http\Requests\Api\V1\DocumentIndexRequest;
use App\Http\Requests\Api\V1\GrantDocumentAccessRequest;
use App\Http\Requests\Api\V1\UpdateDocumentAccessRequest;
use App\Http\Requests\Api\V1\UpdateDocumentRequest;
use App\Http\Resources\Api\V1\DocumentAccessGrantResource;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Http\Resources\Api\V1\DocumentVersionResource;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DocumentAccessService;
use App\Services\DocumentService;
use App\Services\DocumentSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    public function index(
        DocumentIndexRequest $request,
        Workspace $workspace,
        DocumentAccessService $documentAccess
    ): AnonymousResourceCollection {
        Gate::authorize('viewAny', [Document::class, $workspace]);

        $filters = $request->validated();
        $query = $documentAccess->visibleTo($request->user(), $workspace);

        if (isset($filters['workspace_id'])) {
            $query->where('workspace_id', $filters['workspace_id']);
        }

        foreach (['type', 'language', 'status', 'visibility'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (isset($filters['search'])) {
            $search = '%'.mb_strtolower($filters['search']).'%';
            $query->where(function ($documents) use ($search): void {
                $documents->whereRaw('LOWER(title) LIKE ?', [$search])
                    ->orWhereRaw("LOWER(COALESCE(content_html, '')) LIKE ?", [$search])
                    ->orWhereRaw("LOWER(COALESCE(latex_source, '')) LIKE ?", [$search]);
            });
        }

        if (isset($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (isset($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        $sort = $filters['sort'] ?? 'updated_at';
        $documents = $query
            ->orderBy($sort, $sort === 'title' ? 'asc' : 'desc')
            ->paginate($filters['per_page'] ?? 20);

        return DocumentResource::collection($documents);
    }

    public function store(
        CreateDocumentRequest $request,
        Workspace $workspace,
        DocumentService $documents
    ): JsonResponse {
        Gate::authorize('create', [Document::class, $workspace]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $document = $documents->create($user, $workspace, $request->validated());

        return (new DocumentResource($document))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        Workspace $workspace,
        string $document,
        DocumentAccessService $documentAccess
    ): DocumentResource {
        Gate::authorize('viewAny', [Document::class, $workspace]);

        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();

        return new DocumentResource($record);
    }

    public function update(
        UpdateDocumentRequest $request,
        Workspace $workspace,
        string $document,
        DocumentAccessService $documentAccess,
        DocumentService $documents
    ): DocumentResource {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('update', $record);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return new DocumentResource($documents->update($record, $user, $request->validated()));
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        string $document,
        DocumentAccessService $documentAccess,
        DocumentService $documents
    ): JsonResponse {
        Gate::authorize('viewAny', [Document::class, $workspace]);
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('delete', $record);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $documents->delete($record, $user);

        return response()->json(['message' => 'Document deleted.']);
    }

    public function restore(
        Request $request,
        Workspace $workspace,
        string $document,
        DocumentService $documents
    ): DocumentResource {
        Gate::authorize('viewAny', [Document::class, $workspace]);
        $record = Document::withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('restore', $record);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return new DocumentResource($documents->restore($record, $user));
    }

    public function accessIndex(
        Request $request,
        Workspace $workspace,
        string $document,
        DocumentAccessService $documentAccess,
        DocumentSharingService $sharing
    ): AnonymousResourceCollection {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('share', $record);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return DocumentAccessGrantResource::collection($sharing->activeGrants($record, $user));
    }

    public function grantAccess(
        GrantDocumentAccessRequest $request,
        Workspace $workspace,
        string $document,
        DocumentAccessService $documentAccess,
        DocumentSharingService $sharing
    ): JsonResponse {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('share', $record);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $grant = $sharing->grant(
            $record,
            $user,
            $request->validated('user_id'),
            $request->validated('permission')
        );

        return (new DocumentAccessGrantResource($grant))
            ->response()
            ->setStatusCode(201);
    }

    public function updateAccess(
        UpdateDocumentAccessRequest $request,
        Workspace $workspace,
        string $document,
        string $member,
        DocumentAccessService $documentAccess,
        DocumentSharingService $sharing
    ): DocumentAccessGrantResource {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('share', $record);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return new DocumentAccessGrantResource($sharing->update(
            $record,
            $user,
            $member,
            $request->validated('permission')
        ));
    }

    public function revokeAccess(
        Request $request,
        Workspace $workspace,
        string $document,
        string $member,
        DocumentAccessService $documentAccess,
        DocumentSharingService $sharing
    ): JsonResponse {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('share', $record);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $sharing->revoke($record, $user, $member);

        return response()->json(['message' => 'Document access revoked.']);
    }

    public function versions(
        Request $request,
        Workspace $workspace,
        string $document,
        DocumentAccessService $documentAccess
    ): AnonymousResourceCollection {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('view', $record);

        return DocumentVersionResource::collection(
            $record->versions()->orderByDesc('version_number')->get()
        );
    }

    public function showVersion(
        Request $request,
        Workspace $workspace,
        string $document,
        int $version,
        DocumentAccessService $documentAccess
    ): DocumentVersionResource {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('view', $record);

        $versionRecord = DocumentVersion::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('document_id', $record->getKey())
            ->where('version_number', $version)
            ->firstOrFail();

        return new DocumentVersionResource($versionRecord);
    }

    public function restoreVersion(
        Request $request,
        Workspace $workspace,
        string $document,
        int $version,
        DocumentAccessService $documentAccess,
        DocumentService $documents
    ): DocumentResource {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('update', $record);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return new DocumentResource($documents->restoreVersion($record, $user, $version));
    }
}
