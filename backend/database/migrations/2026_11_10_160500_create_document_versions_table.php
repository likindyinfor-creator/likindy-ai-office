<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('document_id');
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('title');
            $table->string('status', 16);
            $table->longText('content_html')->nullable();
            $table->longText('latex_source')->nullable();
            $table->text('change_summary')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['document_id', 'version_number']);
            $table->foreign(['workspace_id', 'document_id'], 'document_versions_document_fk')
                ->references(['workspace_id', 'id'])
                ->on('documents')
                ->cascadeOnDelete();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
