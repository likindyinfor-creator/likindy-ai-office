<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\MobileLoginRequest;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class MobileAuthenticationController extends Controller
{
    private const TOKEN_LIFETIME_DAYS = 30;

    public function login(MobileLoginRequest $request): JsonResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);

        if (! Auth::guard('web')->validate($credentials)) {
            return $this->invalidCredentialsResponse();
        }

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! $user->hasVerifiedEmail()) {
            return $this->invalidCredentialsResponse();
        }

        $newToken = $user->createToken(
            'likindy-mobile',
            ['*'],
            now()->addDays(self::TOKEN_LIFETIME_DAYS)
        );

        return response()->json([
            'data' => [
                'token' => $newToken->plainTextToken,
                'user' => $this->userPayload($user),
                'personal_workspace' => $this->personalWorkspacePayload($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401);

        $token->delete();

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
                'personal_workspace' => $this->personalWorkspacePayload($user),
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
    private function personalWorkspacePayload(User $user): ?array
    {
        $workspace = $user->personalWorkspace()->first();

        if (! $workspace) {
            return null;
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->where('role', 'owner')
            ->where('status', 'active')
            ->exists();

        if (! $membership) {
            return null;
        }

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'type' => $workspace->type,
            'role' => 'owner',
        ];
    }
}
