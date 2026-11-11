<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\WorkspaceInvitationDispatchException;
use App\Http\Requests\Api\V1\CreateWorkspaceInvitationRequest;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceAccessService;
use App\Services\WorkspaceInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WorkspaceInvitationController extends Controller
{
    public function index(
        Request $request,
        string $workspace,
        WorkspaceAccessService $access
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $workspaceModel = $access->managedWorkspace($user, $workspace);

        $invitations = $workspaceModel->invitations()->latest()
            ->get(['id', 'email', 'role', 'status', 'expires_at', 'accepted_at', 'revoked_at', 'created_at'])
            ->map(fn (WorkspaceInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => $invitation->status === 'pending' && $invitation->expires_at->isPast()
                    ? 'expired'
                    : $invitation->status,
                'expires_at' => $invitation->expires_at->toISOString(),
                'accepted_at' => $invitation->accepted_at?->toISOString(),
                'revoked_at' => $invitation->revoked_at?->toISOString(),
                'created_at' => $invitation->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $invitations->values()]);
    }

    public function store(
        CreateWorkspaceInvitationRequest $request,
        string $workspace,
        WorkspaceAccessService $access,
        WorkspaceInvitationService $invitations
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $workspaceModel = $access->managedWorkspace($user, $workspace);
        $attributes = $request->safe()->only(['email', 'role']);

        try {
            $created = $invitations->create(
                $user,
                $workspaceModel->getKey(),
                $attributes['email'],
                $attributes['role']
            );
        } catch (WorkspaceInvitationDispatchException) {
            return response()->json([
                'message' => 'The invitation could not be sent. Please try again.',
            ], 503);
        }

        return response()->json([
            'data' => [
                'id' => $created->id,
                'email' => $created->email,
                'role' => $created->role,
                'status' => $created->status,
                'expires_at' => $created->expires_at->toISOString(),
            ],
            'message' => 'If the address can receive the invitation, instructions have been sent.',
        ], 201);
    }

    public function revoke(
        Request $request,
        string $workspace,
        string $invitation,
        WorkspaceAccessService $access,
        WorkspaceInvitationService $invitations
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $workspaceModel = $access->managedWorkspace($user, $workspace);
        $invitations->revoke($user, $workspaceModel->getKey(), $invitation);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    public function accept(
        Request $request,
        WorkspaceInvitationService $invitations
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $token = $request->attributes->get('invitation_token');
        abort_unless(is_string($token), 404);
        $result = $invitations->accept($user, $token);

        if ($result['status'] !== 'accepted') {
            return response()->json([
                'message' => 'The invitation is invalid, expired, or unavailable.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'workspace' => [
                    'id' => $result['workspace']->id,
                    'name' => $result['workspace']->name,
                    'type' => $result['workspace']->type,
                ],
                'membership' => [
                    'role' => $result['membership']->role,
                    'status' => $result['membership']->status,
                ],
            ],
            'message' => 'Invitation accepted.',
        ]);
    }

    public function decline(
        Request $request,
        WorkspaceInvitationService $invitations
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $token = $request->attributes->get('invitation_token');
        abort_unless(is_string($token), 404);
        $status = $invitations->decline($user, $token);

        if ($status !== 'declined') {
            return response()->json([
                'message' => 'The invitation is invalid, expired, or unavailable.',
            ], 422);
        }

        return response()->json(['message' => 'Invitation declined.']);
    }
}
