<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class DocumentAccessService
{
    /**
     * Return only non-deleted documents the active workspace member may view.
     *
     * Private documents require ownership or an explicit access grant. Workspace
     * administrators receive no implicit access to private documents.
     *
     * @return Builder<Document>
     */
    public function visibleTo(User $user, Workspace $workspace): Builder
    {
        $isActiveMember = $workspace->memberships()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->exists();

        if (! $isActiveMember) {
            return Document::query()->whereRaw('1 = 0');
        }

        return Document::query()
            ->where('workspace_id', $workspace->getKey())
            ->where(function (Builder $query) use ($user) {
                $query->where('owner_user_id', $user->getKey())
                    ->orWhere('visibility', 'workspace')
                    ->orWhereHas('accessGrants', function (Builder $grants) use ($user) {
                        $grants->where('user_id', $user->getKey())
                            ->whereIn('permission', ['read', 'edit', 'share']);
                    });
            });
    }
}
