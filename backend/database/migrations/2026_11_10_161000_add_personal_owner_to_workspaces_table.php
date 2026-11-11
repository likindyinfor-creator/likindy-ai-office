<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasAmbiguousIndividualWorkspaces = DB::table('workspaces')
            ->select('created_by_user_id')
            ->where('type', 'individual')
            ->groupBy('created_by_user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasAmbiguousIndividualWorkspaces) {
            throw new RuntimeException(
                'Cannot identify a unique personal workspace for every existing individual workspace owner. '
                .'Review and resolve duplicate individual workspaces before retrying this migration.'
            );
        }

        Schema::table('workspaces', function (Blueprint $table) {
            $table->uuid('personal_owner_user_id')->nullable();
        });

        DB::table('workspaces')
            ->where('type', 'individual')
            ->update(['personal_owner_user_id' => DB::raw('created_by_user_id')]);

        Schema::table('workspaces', function (Blueprint $table) {
            $table->unique('personal_owner_user_id');
            $table->foreign('personal_owner_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['personal_owner_user_id']);
            $table->dropUnique(['personal_owner_user_id']);
            $table->dropColumn('personal_owner_user_id');
        });
    }
};
