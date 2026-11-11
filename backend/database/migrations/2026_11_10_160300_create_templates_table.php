<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 64);
            $table->string('language', 8);
            $table->string('document_type', 32);
            $table->string('format', 16)->default('html');
            $table->jsonb('structure')->default('[]');
            $table->longText('content_html')->nullable();
            $table->longText('latex_source')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('draft');
            $table->jsonb('tags')->default('[]');
            $table->timestampsTz();
            $table->unique(['workspace_id', 'slug', 'version']);
            $table->index(['status', 'category', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
