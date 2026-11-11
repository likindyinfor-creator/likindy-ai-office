<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceService
{
    /**
     * @return array{workspace: Workspace, membership: WorkspaceMembership}
     */
    public function createFor(User $user, array $attributes): array
    {
        return DB::transaction(function () use ($user, $attributes): array {
            $workspace = new Workspace;
            $workspace->created_by_user_id = $user->getKey();
            $workspace->name = $attributes['name'];
            $workspace->slug = (Str::slug($attributes['name']) ?: 'workspace').'-'.Str::uuid();
            $workspace->type = $attributes['type'];
            $workspace->description = $attributes['description'] ?? null;
            $workspace->contact = $attributes['contact'] ?? null;
            $workspace->personal_owner_user_id = null;
            $workspace->save();

            $membership = new WorkspaceMembership;
            $membership->workspace_id = $workspace->getKey();
            $membership->user_id = $user->getKey();
            $membership->role = 'owner';
            $membership->status = 'active';
            $membership->joined_at = now();
            $membership->save();

            return [
                'workspace' => $workspace,
                'membership' => $membership,
            ];
        });
    }
}
