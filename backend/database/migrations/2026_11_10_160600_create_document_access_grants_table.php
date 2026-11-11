<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('document_id');
            $table->uuid('user_id');
            $table->foreignUuid('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('permission', 16);
            $table->timestampsTz();
            $table->unique(['document_id', 'user_id']);
            $table->foreign(['workspace_id', 'document_id'], 'document_grants_document_fk')
                ->references(['workspace_id', 'id'])
                ->on('documents')
                ->cascadeOnDelete();
            $table->foreign(['workspace_id', 'user_id'], 'document_grants_membership_fk')
                ->references(['workspace_id', 'user_id'])
                ->on('workspace_memberships')
                ->cascadeOnDelete();
            $table->index(['user_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_grants');
    }
};
