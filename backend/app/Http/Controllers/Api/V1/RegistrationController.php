<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\RegisterRequest;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class RegistrationController extends Controller
{
    public function __invoke(
        RegisterRequest $request,
        RegistrationService $registrationService
    ): JsonResponse {
        $registration = $registrationService->register(
            $request->safe()->only(['name', 'email', 'password'])
        );

        $user = $registration['user'];
        $workspace = $registration['personal_workspace'];

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified' => $user->hasVerifiedEmail(),
                ],
                'personal_workspace' => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'type' => $workspace->type,
                    'role' => 'owner',
                ],
            ],
            'meta' => [
                'verification_required' => true,
                'verification_notification' => $registration['verification_notification_status'],
            ],
        ], 201);
    }
}
