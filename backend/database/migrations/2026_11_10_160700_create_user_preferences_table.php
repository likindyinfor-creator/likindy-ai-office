<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('locale', 8)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('date_format', 24)->default('DD MMM YYYY');
            $table->string('appearance', 16)->default('comfortable');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
