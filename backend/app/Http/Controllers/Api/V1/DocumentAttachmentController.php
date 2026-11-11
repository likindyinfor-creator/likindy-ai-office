<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\UploadDocumentAttachmentRequest;
use App\Http\Resources\Api\V1\DocumentAttachmentResource;
use App\Models\DocumentAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DocumentAccessService;
use App\Services\DocumentAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentAttachmentController extends Controller
{
    public function index(
        Request $request,
        Workspace $workspace,
        string $document,
        DocumentAccessService $documentAccess,
        DocumentAttachmentService $attachments
    ): AnonymousResourceCollection {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('view', $record);

        return DocumentAttachmentResource::collection($attachments->list($record));
    }

    public function store(
        UploadDocumentAttachmentRequest $request,
        Workspace $workspace,
        string $document,
        DocumentAccessService $documentAccess,
        DocumentAttachmentService $attachments
    ): JsonResponse {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('update', $record);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $attachment = $attachments->upload($record, $user, $request->file('file'));

        return (new DocumentAttachmentResource($attachment))
            ->response()
            ->setStatusCode(201);
    }

    public function download(
        Request $request,
        Workspace $workspace,
        string $document,
        string $attachment,
        DocumentAccessService $documentAccess,
        DocumentAttachmentService $attachments
    ): StreamedResponse|BinaryFileResponse {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('view', $record);
        $attachmentRecord = DocumentAttachment::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('document_id', $record->getKey())
            ->whereKey($attachment)
            ->firstOrFail();

        return $attachments->download($attachmentRecord);
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        string $document,
        string $attachment,
        DocumentAccessService $documentAccess,
        DocumentAttachmentService $attachments
    ): JsonResponse {
        $record = $documentAccess->visibleTo($request->user(), $workspace)
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('update', $record);
        $attachmentRecord = DocumentAttachment::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('document_id', $record->getKey())
            ->whereKey($attachment)
            ->firstOrFail();
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $attachments->delete($record, $attachmentRecord, $user);

        return response()->json(['message' => 'Attachment deleted.']);
    }
}
