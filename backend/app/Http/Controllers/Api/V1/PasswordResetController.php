<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PasswordResetController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            Password::broker()->sendResetLink([
                'email' => $request->validated('email'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Password reset notification dispatch failed.', [
                'exception_class' => $exception::class,
            ]);
        }

        return response()->json([
            'message' => 'If an account exists for that email, password reset instructions will be sent.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $resetUser = null;
        $credentials = $request->safe()->only(['email', 'token', 'password', 'password_confirmation']);

        $status = DB::transaction(function () use ($credentials, &$resetUser): string {
            DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))
                ->where('email', $credentials['email'])
                ->lockForUpdate()
                ->first();

            return Password::broker()->reset($credentials, function (User $user, string $password) use (&$resetUser): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();
                $this->invalidateDatabaseSessions($user);
                $resetUser = $user;
            });
        });

        if ($status !== PasswordBroker::PASSWORD_RESET || ! $resetUser instanceof User) {
            return response()->json([
                'message' => 'The password reset link is invalid or expired.',
            ], 422);
        }

        event(new PasswordReset($resetUser));

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ]);
    }

    private function invalidateDatabaseSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $connectionName = config('session.connection') ?: config('database.default');
        $sessionTable = config('session.table', 'sessions');

        if (! Schema::connection($connectionName)->hasTable($sessionTable)) {
            throw new RuntimeException('Configured database session table is unavailable.');
        }

        DB::connection($connectionName)->table($sessionTable)
            ->where('user_id', $user->getKey())
            ->delete();
    }
}
