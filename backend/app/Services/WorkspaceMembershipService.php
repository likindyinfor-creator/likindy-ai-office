<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;

class WorkspaceMembershipService
{
    public function __construct(private readonly WorkspaceAccessService $access) {}

    /**
     * @return list<WorkspaceMembership>
     */
    public function membersFor(User $actor, string $workspaceId): array
    {
        $this->access->managedWorkspace($actor, $workspaceId);

        return WorkspaceMembership::query()
            ->with('user:id,name,email')
            ->where('workspace_id', $workspaceId)
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    /**
     * @param  array{role?: string, status?: string}  $attributes
     */
    public function update(
        User $actor,
        string $workspaceId,
        string $membershipId,
        array $attributes
    ): WorkspaceMembership {
        return DB::transaction(function () use ($actor, $workspaceId, $membershipId, $attributes): WorkspaceMembership {
            Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            $manager = $this->access->activeManagerMembership($actor, $workspaceId);
            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($membershipId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeTarget($actor, $manager, $membership);

            if (
                $manager->role === 'admin'
                && isset($attributes['role'])
                && in_array($attributes['role'], ['owner', 'admin'], true)
            ) {
                abort(403);
            }

            if ($membership->status === 'removed') {
                abort(409, 'Removed memberships can only be restored by accepting a new invitation.');
            }

            if ($membership->status === 'invited' && ($attributes['status'] ?? null) === 'active') {
                abort(409, 'Invited memberships must be activated by accepting the invitation.');
            }

            if (isset($attributes['role'])) {
                if ($attributes['role'] === 'owner' && $manager->role !== 'owner') {
                    abort(403);
                }

                if ($membership->role === 'owner' && $attributes['role'] !== 'owner') {
                    $this->ensureAnotherActiveOwner($workspaceId, $membership);
                }

                $membership->role = $attributes['role'];
            }

            if (isset($attributes['status'])) {
                if ($membership->role === 'owner' && $attributes['status'] !== 'active') {
                    $this->ensureAnotherActiveOwner($workspaceId, $membership);
                }

                $membership->status = $attributes['status'];
                if ($attributes['status'] === 'active' && $membership->joined_at === null) {
                    $membership->joined_at = now();
                }
            }

            $membership->save();

            return $membership->fresh('user:id,name,email');
        });
    }

    public function remove(User $actor, string $workspaceId, string $membershipId): void
    {
        DB::transaction(function () use ($actor, $workspaceId, $membershipId): void {
            Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            $manager = $this->access->activeManagerMembership($actor, $workspaceId);
            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($membershipId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeTarget($actor, $manager, $membership);

            if ($membership->status === 'removed') {
                abort(409, 'Membership has already been removed.');
            }

            if ($membership->role === 'owner' && $membership->status === 'active') {
                $this->ensureAnotherActiveOwner($workspaceId, $membership);
            }

            $membership->status = 'removed';
            $membership->save();
        });
    }

    private function authorizeTarget(
        User $actor,
        WorkspaceMembership $manager,
        WorkspaceMembership $target
    ): void {
        if ($manager->role === 'owner') {
            return;
        }

        if (
            $manager->role !== 'admin'
            || $target->user_id === $actor->getKey()
            || in_array($target->role, ['owner', 'admin'], true)
        ) {
            abort(403);
        }

    }

    private function ensureAnotherActiveOwner(string $workspaceId, WorkspaceMembership $target): void
    {
        $otherActiveOwners = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->where('id', '<>', $target->getKey())
            ->count();

        if ($otherActiveOwners < 1) {
            abort(409, 'The last active owner cannot be demoted, suspended, or removed.');
        }
    }
}
