<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

class WorkspaceAccessService
{
    public function managedWorkspace(User $user, string $workspaceId): Workspace
    {
        return Workspace::query()
            ->whereKey($workspaceId)
            ->whereHas('memberships', function ($query) use ($user): void {
                $query->where('user_id', $user->getKey())
                    ->where('status', 'active')
                    ->whereIn('role', ['owner', 'admin']);
            })
            ->firstOrFail();
    }

    public function activeManagerMembership(User $user, string $workspaceId): WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereIn('role', ['owner', 'admin'])
            ->firstOrFail();
    }
}
