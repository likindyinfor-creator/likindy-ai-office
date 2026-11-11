<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegistrationService
{
    /**
     * @return array{user: User, personal_workspace: Workspace, verification_notification_status: string}
     */
    public function register(array $attributes): array
    {
        $email = $attributes['email'];

        try {
            $registration = DB::transaction(function () use ($attributes): array {
                $user = User::query()->create([
                    'name' => $attributes['name'],
                    'email' => $attributes['email'],
                    'password' => $attributes['password'],
                ]);

                $workspace = new Workspace;
                $workspace->created_by_user_id = $user->id;
                $workspace->personal_owner_user_id = $user->id;
                $workspace->name = "{$user->name}'s Personal Workspace";
                $workspace->slug = (Str::slug($user->name) ?: 'personal').'-personal-'.$user->id;
                $workspace->type = 'individual';
                $workspace->save();

                WorkspaceMembership::query()->create([
                    'workspace_id' => $workspace->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);

                return [
                    'user' => $user,
                    'personal_workspace' => $workspace,
                ];
            });
        } catch (QueryException $exception) {
            if (
                in_array((string) $exception->getCode(), ['23000', '23505'], true)
                && User::query()->where('email', $email)->exists()
            ) {
                throw ValidationException::withMessages([
                    'email' => 'An account with this email address already exists.',
                ]);
            }

            throw $exception;
        }

        $notificationStatus = 'requested';

        try {
            event(new Registered($registration['user']));
        } catch (Throwable $exception) {
            $notificationStatus = 'failed';
            Log::error('Registration committed, but verification notification dispatch failed.', [
                'user_id' => $registration['user']->getKey(),
                'exception_class' => $exception::class,
            ]);
            report($exception);
        }

        $registration['verification_notification_status'] = $notificationStatus;

        return $registration;
    }
}
