<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CreateWorkspaceRequest;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $memberships = WorkspaceMembership::query()
            ->with('workspace')
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereHas('workspace')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $memberships->map(fn (WorkspaceMembership $membership): array => [
                'id' => $membership->workspace->id,
                'name' => $membership->workspace->name,
                'type' => $membership->workspace->type,
                'role' => $membership->role,
                'status' => $membership->status,
                'created_at' => $membership->workspace->created_at?->toISOString(),
            ])->values(),
        ]);
    }

    public function store(CreateWorkspaceRequest $request, WorkspaceService $workspaces): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $created = $workspaces->createFor($user, $request->safe()->only([
            'name',
            'type',
            'description',
            'contact',
        ]));

        return response()->json([
            'data' => [
                'workspace' => [
                    'id' => $created['workspace']->id,
                    'name' => $created['workspace']->name,
                    'type' => $created['workspace']->type,
                    'created_at' => $created['workspace']->created_at?->toISOString(),
                ],
                'membership' => [
                    'role' => $created['membership']->role,
                    'status' => $created['membership']->status,
                ],
            ],
        ], 201);
    }
}
