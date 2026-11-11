<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->string('status', 24)->default('invited')->change();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign(['workspace_id', 'owner_user_id'], 'documents_owner_membership_fk')
                ->references(['workspace_id', 'user_id'])
                ->on('workspace_memberships')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign('documents_owner_membership_fk');
        });

        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->string('status', 24)->default('active')->change();
        });
    }
};
