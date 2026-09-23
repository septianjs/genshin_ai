<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('session_token', 100)->index();
            $table->string('title', 200)->default('Genshin Build Chat');
            $table->string('character_slug', 100)->nullable();
            $table->json('active_team')->nullable(); // 4 karakter + konstelasi masing-masing
            $table->string('target_content', 50)->default('abyss');
            $table->string('patch_version', 10)->default('7.0');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
