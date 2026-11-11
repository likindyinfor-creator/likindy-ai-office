<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

class DocumentPolicy
{
    public function create(User $user, Workspace $workspace): bool
    {
        return $this->activeMembership($user, $workspace) !== null;
    }

    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $this->activeMembership($user, $workspace) !== null;
    }

    public function view(User $user, Document $document): bool
    {
        if (! $this->activeMembership($user, $document->workspace_id)) {
            return false;
        }

        if ($document->owner_user_id === $user->getKey()) {
            return true;
        }

        if ($document->visibility === 'workspace') {
            return true;
        }

        return $this->hasGrant($user, $document, ['read', 'edit', 'share']);
    }

    public function update(User $user, Document $document): bool
    {
        $membership = $this->activeMembership($user, $document->workspace_id);
        if (! $membership) {
            return false;
        }

        if ($document->owner_user_id === $user->getKey()) {
            return true;
        }

        if ($document->visibility === 'workspace'
            && in_array($membership->role, ['owner', 'admin'], true)) {
            return true;
        }

        return $this->hasGrant($user, $document, ['edit']);
    }

    public function delete(User $user, Document $document): bool
    {
        $membership = $this->activeMembership($user, $document->workspace_id);
        if (! $membership) {
            return false;
        }

        if ($document->owner_user_id === $user->getKey()) {
            return true;
        }

        return $document->visibility === 'workspace'
            && in_array($membership->role, ['owner', 'admin'], true);
    }

    public function restore(User $user, Document $document): bool
    {
        return $this->activeMembership($user, $document->workspace_id) !== null
            && $document->owner_user_id === $user->getKey()
            && $document->trashed()
            && $document->deleted_at->greaterThanOrEqualTo(
                now()->subDays(config('documents.deleted_retention_days', 30))
            );
    }

    public function forceDelete(User $user, Document $document): bool
    {
        return $this->activeMembership($user, $document->workspace_id) !== null
            && $document->owner_user_id === $user->getKey()
            && $document->trashed();
    }

    public function share(User $user, Document $document): bool
    {
        $membership = $this->activeMembership($user, $document->workspace_id);

        return $membership !== null
            && $document->owner_user_id === $user->getKey();
    }

    private function activeMembership(User $user, Workspace|string $workspace): ?WorkspaceMembership
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->getKey() : $workspace;

        return WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->first();
    }

    /**
     * Private documents are visible only to their owner or a specifically granted member.
     * Workspace roles never implicitly grant access to private documents.
     *
     * @param  list<string>  $permissions
     */
    private function hasGrant(User $user, Document $document, array $permissions): bool
    {
        return $document->accessGrants()
            ->where('workspace_id', $document->workspace_id)
            ->where('user_id', $user->getKey())
            ->whereIn('permission', $permissions)
            ->exists();
    }
}
