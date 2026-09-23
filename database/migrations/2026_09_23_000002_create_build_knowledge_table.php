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
        Schema::create('build_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained('characters')->cascadeOnDelete();
            $table->string('category', 50)->index(); // weapons_ranking, artifact_priorities, team_synergies, er_breakpoints
            $table->string('title', 200)->nullable();
            $table->longText('content');
            $table->string('target_content', 50)->default('universal')->index(); // abyss, theater, overworld, universal
            $table->json('embedding')->nullable(); // float array dari Nemotron 3 Embed
            $table->string('patch_version', 10)->default('7.0')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('build_knowledge');
    }
};
