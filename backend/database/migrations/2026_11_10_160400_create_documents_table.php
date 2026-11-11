<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('templates')->nullOnDelete();
            $table->string('title');
            $table->string('type', 32);
            $table->string('language', 8)->default('en');
            $table->string('visibility', 16)->default('private');
            $table->string('status', 16)->default('draft');
            $table->string('content_format', 16)->default('html');
            $table->longText('content_html')->nullable();
            $table->longText('latex_source')->nullable();
            $table->boolean('typeset')->default(false);
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['workspace_id', 'id']);
            $table->index(['workspace_id', 'owner_user_id', 'updated_at']);
            $table->index(['workspace_id', 'visibility', 'updated_at']);
            $table->index(['workspace_id', 'status', 'type', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
