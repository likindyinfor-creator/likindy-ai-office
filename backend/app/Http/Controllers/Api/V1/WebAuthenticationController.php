<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class WebAuthenticationController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt($request->safe()->only(['email', 'password']))) {
            return $this->invalidCredentialsResponse();
        }

        $user = Auth::guard('web')->user();

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->invalidCredentialsResponse();
        }

        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'data' => [
                'user' => $this->userPayload($user),
                'active_workspace' => $this->activeWorkspacePayload($request, $user),
            ],
        ]);
    }

    private function invalidCredentialsResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'The provided credentials are invalid or the account is not verified.',
        ], 422);
    }

    /**
     * @return array{id: string, name: string, email: string, email_verified: bool}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }

    /**
     * @return array{id: string, name: string, type: string, role: string}|null
     */
    private function activeWorkspacePayload(Request $request, User $user): ?array
    {
        $workspaceId = $request->session()->get('active_workspace_id');

        if (! is_string($workspaceId)) {
            return null;
        }

        $membership = WorkspaceMembership::query()
            ->with('workspace')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->first();

        if (! $membership || ! $membership->workspace) {
            return null;
        }

        return [
            'id' => $membership->workspace->id,
            'name' => $membership->workspace->name,
            'type' => $membership->workspace->type,
            'role' => $membership->role,
        ];
    }
}
