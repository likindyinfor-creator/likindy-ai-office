<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pendingInvitations = DB::table('workspace_invitations')
            ->where('status', 'pending')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get(['id', 'workspace_id', 'email']);

        $keys = [];

        foreach ($pendingInvitations as $invitation) {
            $key = $invitation->workspace_id.'|'.mb_strtolower(trim($invitation->email));

            if (isset($keys[$key])) {
                throw new RuntimeException(
                    'Duplicate unexpired workspace invitations exist for the same workspace and email. '
                    .'Resolve those invitations before retrying this migration.'
                );
            }

            $keys[$key] = $invitation->id;
        }

        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->string('pending_email_key')->nullable();
        });

        foreach ($pendingInvitations as $invitation) {
            DB::table('workspace_invitations')
                ->where('id', $invitation->id)
                ->update(['pending_email_key' => mb_strtolower(trim($invitation->email))]);
        }

        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->unique(
                ['workspace_id', 'pending_email_key'],
                'workspace_invitations_pending_email_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->dropUnique('workspace_invitations_pending_email_unique');
            $table->dropColumn('pending_email_key');
        });
    }
};
