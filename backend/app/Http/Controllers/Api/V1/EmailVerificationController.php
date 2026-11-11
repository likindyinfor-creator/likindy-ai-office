<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): JsonResponse
    {
        if (! URL::hasValidSignature($request)) {
            return $this->invalidLinkResponse();
        }

        try {
            [$user, $verifiedNow] = DB::transaction(function () use ($id, $hash): array {
                $user = User::query()->lockForUpdate()->findOrFail($id);

                if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                    throw new ModelNotFoundException;
                }

                if ($user->hasVerifiedEmail()) {
                    return [$user, false];
                }

                if (! $user->markEmailAsVerified()) {
                    throw new \RuntimeException('Unable to persist email verification.');
                }

                return [$user, true];
            });
        } catch (ModelNotFoundException) {
            return $this->invalidLinkResponse();
        }

        if ($verifiedNow) {
            event(new Verified($user));
        }

        return response()->json([
            'message' => $verifiedNow
                ? 'Email address verified.'
                : 'Email address is already verified.',
            'data' => [
                'email_verified' => true,
            ],
        ]);
    }

    private function invalidLinkResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Verification link is invalid or expired.',
        ], 403);
    }
}
