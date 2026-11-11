<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\UpdateWorkspaceMemberRequest;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceAccessService;
use App\Services\WorkspaceMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WorkspaceMemberController extends Controller
{
    public function index(
        Request $request,
        string $workspace,
        WorkspaceMembershipService $memberships
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = collect($memberships->membersFor($user, $workspace))
            ->map(fn (WorkspaceMembership $membership): array => [
                'id' => $membership->id,
                'user' => $membership->user ? [
                    'id' => $membership->user->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                ] : null,
                'role' => $membership->role,
                'status' => $membership->status,
                'joined_at' => $membership->joined_at?->toISOString(),
                'created_at' => $membership->created_at?->toISOString(),
            ])->values();

        return response()->json(['data' => $data]);
    }

    public function update(
        UpdateWorkspaceMemberRequest $request,
        string $workspace,
        string $member,
        WorkspaceAccessService $access,
        WorkspaceMembershipService $memberships
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $workspaceModel = $access->managedWorkspace($user, $workspace);
        $membership = $memberships->update(
            $user,
            $workspaceModel->getKey(),
            $member,
            $request->safe()->only(['role', 'status'])
        );

        return response()->json([
            'data' => [
                'id' => $membership->id,
                'user' => $membership->user ? [
                    'id' => $membership->user->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                ] : null,
                'role' => $membership->role,
                'status' => $membership->status,
                'joined_at' => $membership->joined_at?->toISOString(),
            ],
        ]);
    }

    public function destroy(
        Request $request,
        string $workspace,
        string $member,
        WorkspaceAccessService $access,
        WorkspaceMembershipService $memberships
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $workspaceModel = $access->managedWorkspace($user, $workspace);
        $memberships->remove($user, $workspaceModel->getKey(), $member);

        return response()->json(['message' => 'Membership removed.']);
    }
}
