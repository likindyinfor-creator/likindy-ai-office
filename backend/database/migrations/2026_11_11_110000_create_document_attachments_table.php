<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('document_id');
            $table->foreignUuid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename', 255);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size');
            $table->string('storage_path', 512)->unique();
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'document_id'], 'document_attachments_document_fk')
                ->references(['workspace_id', 'id'])
                ->on('documents')
                ->cascadeOnDelete();
            $table->index(['workspace_id', 'document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_attachments');
    }
};
