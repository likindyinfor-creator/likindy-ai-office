<?php

namespace App\Services;

use App\Exceptions\WorkspaceInvitationDispatchException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkspaceInvitationService
{
    public function __construct(private readonly WorkspaceAccessService $access) {}

    public function create(User $actor, string $workspaceId, string $email, string $role): WorkspaceInvitation
    {
        $this->ensureMailTransportDoesNotLogMessageContent();

        if (! in_array($role, ['admin', 'staff', 'member'], true)) {
            throw ValidationException::withMessages([
                'role' => 'The selected invitation role is invalid.',
            ]);
        }

        $invitationData = DB::transaction(function () use ($actor, $workspaceId, $email, $role): array {
            $workspace = Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            $manager = $this->access->activeManagerMembership($actor, $workspaceId);

            if ($manager->role === 'admin' && ! in_array($role, ['staff', 'member'], true)) {
                abort(403);
            }

            $now = now();
            $workspace->invitations()
                ->where('status', 'pending')
                ->where('expires_at', '<=', $now)
                ->update([
                    'status' => 'expired',
                    'pending_email_key' => null,
                    'updated_at' => $now,
                ]);

            $normalizedEmail = mb_strtolower(trim($email));

            if ($workspace->invitations()->where('pending_email_key', $normalizedEmail)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'An invitation is already pending for this address.',
                ]);
            }

            $token = bin2hex(random_bytes(32));
            $invitation = new WorkspaceInvitation;
            $invitation->workspace_id = $workspace->getKey();
            $invitation->invited_by_user_id = $actor->getKey();
            $invitation->email = $normalizedEmail;
            $invitation->pending_email_key = $normalizedEmail;
            $invitation->role = $role;
            $invitation->status = 'pending';
            $invitation->token_hash = hash('sha256', $token);
            $invitation->expires_at = $now->addDays((int) config('workspaces.invitation_expiration_days', 7));
            $invitation->save();

            return [
                'invitation' => $invitation,
                'token' => $token,
                'workspace_name' => $workspace->name,
            ];
        });

        try {
            Notification::route('mail', $invitationData['invitation']->email)->notify(
                new WorkspaceInvitationNotification(
                    $invitationData['workspace_name'],
                    $actor->name,
                    $invitationData['invitation']->role,
                    $invitationData['token'],
                )
            );
        } catch (Throwable $exception) {
            WorkspaceInvitation::query()
                ->whereKey($invitationData['invitation']->getKey())
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'pending_email_key' => null,
                    'updated_at' => now(),
                ]);

            Log::error('Workspace invitation notification dispatch failed.', [
                'invitation_id' => $invitationData['invitation']->getKey(),
                'exception_class' => $exception::class,
            ]);

            throw new WorkspaceInvitationDispatchException('Workspace invitation notification dispatch failed.');
        }

        return $invitationData['invitation'];
    }

    /**
     * @return array{status: string, workspace?: Workspace, membership?: WorkspaceMembership}
     */
    public function accept(User $user, string $plainToken): array
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $plainToken)) {
            return ['status' => 'invalid'];
        }

        return DB::transaction(function () use ($user, $plainToken): array {
            $tokenHash = hash('sha256', $plainToken);
            $reference = WorkspaceInvitation::query()
                ->where('token_hash', $tokenHash)
                ->first(['id', 'workspace_id']);

            if (! $reference) {
                return ['status' => 'invalid'];
            }

            $workspace = Workspace::query()
                ->whereKey($reference->workspace_id)
                ->lockForUpdate()
                ->first();

            if (! $workspace) {
                return ['status' => 'invalid'];
            }

            $invitation = WorkspaceInvitation::query()
                ->whereKey($reference->getKey())
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! hash_equals($invitation->token_hash, $tokenHash)) {
                return ['status' => 'invalid'];
            }

            if (
                $invitation->status !== 'pending'
                || $invitation->accepted_at !== null
                || $invitation->revoked_at !== null
            ) {
                return ['status' => 'invalid'];
            }

            if ($invitation->expires_at->isPast()) {
                $invitation->status = 'expired';
                $invitation->pending_email_key = null;
                $invitation->save();

                return ['status' => 'invalid'];
            }

            if (! hash_equals(mb_strtolower(trim($invitation->email)), mb_strtolower(trim($user->email)))) {
                return ['status' => 'invalid'];
            }

            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($membership?->status === 'suspended') {
                return ['status' => 'invalid'];
            }

            if ($membership === null) {
                $membership = new WorkspaceMembership;
                $membership->workspace_id = $workspace->getKey();
                $membership->user_id = $user->getKey();
                $membership->role = $invitation->role;
                $membership->status = 'active';
                $membership->joined_at = now();
                $membership->save();
            } elseif ($membership->status === 'invited') {
                $membership->role = $invitation->role;
                $membership->status = 'active';
                $membership->joined_at = now();
                $membership->save();
            } elseif ($membership->status === 'removed') {
                $membership->role = $invitation->role;
                $membership->status = 'active';
                $membership->joined_at = now();
                $membership->save();
            }

            $invitation->status = 'accepted';
            $invitation->accepted_at = now();
            $invitation->pending_email_key = null;
            $invitation->save();

            return [
                'status' => 'accepted',
                'workspace' => $workspace,
                'membership' => $membership,
            ];
        });
    }

    public function decline(User $user, string $plainToken): string
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $plainToken)) {
            return 'invalid';
        }

        return DB::transaction(function () use ($user, $plainToken): string {
            $invitation = WorkspaceInvitation::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if (
                ! $invitation
                || ! hash_equals($invitation->token_hash, hash('sha256', $plainToken))
                || $invitation->status !== 'pending'
                || $invitation->accepted_at !== null
                || $invitation->revoked_at !== null
                || ! hash_equals(mb_strtolower(trim($invitation->email)), mb_strtolower(trim($user->email)))
            ) {
                return 'invalid';
            }

            if ($invitation->expires_at->isPast()) {
                $invitation->status = 'expired';
                $invitation->pending_email_key = null;
                $invitation->save();

                return 'invalid';
            }

            $invitation->status = 'declined';
            $invitation->pending_email_key = null;
            $invitation->save();

            return 'declined';
        });
    }

    public function revoke(User $actor, string $workspaceId, string $invitationId): void
    {
        DB::transaction(function () use ($actor, $workspaceId, $invitationId): void {
            Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            $manager = $this->access->activeManagerMembership($actor, $workspaceId);
            $invitation = WorkspaceInvitation::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($invitationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invitation->status !== 'pending' || $invitation->expires_at->isPast()) {
                abort(409, 'Only pending, unexpired invitations can be revoked.');
            }

            if ($manager->role === 'admin' && $invitation->role === 'admin') {
                abort(403);
            }

            $invitation->status = 'revoked';
            $invitation->revoked_at = now();
            $invitation->pending_email_key = null;
            $invitation->save();
        });
    }

    private function ensureMailTransportDoesNotLogMessageContent(): void
    {
        $mailerName = (string) config('mail.default');
        $mailers = (array) config('mail.mailers', []);
        $pending = [$mailerName];
        $visited = [];

        while ($pending !== []) {
            $name = array_pop($pending);
            if (isset($visited[$name])) {
                continue;
            }

            $visited[$name] = true;
            $mailer = $mailers[$name] ?? [];

            if (($mailer['transport'] ?? null) === 'log') {
                throw new WorkspaceInvitationDispatchException(
                    'Invitation delivery cannot use a mail transport that logs message content.'
                );
            }

            if (in_array($mailer['transport'] ?? null, ['failover', 'roundrobin'], true)) {
                array_push($pending, ...($mailer['mailers'] ?? []));
            }
        }
    }
}
