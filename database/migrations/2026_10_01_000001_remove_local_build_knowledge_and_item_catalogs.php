<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('build_knowledge');
        Schema::dropIfExists('genshin_artifacts');
        Schema::dropIfExists('genshin_weapons');
    }

    public function down(): void
    {
        Schema::create('build_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained('characters')->cascadeOnDelete();
            $table->string('category', 50)->index();
            $table->string('title', 200)->nullable();
            $table->longText('content');
            $table->string('target_content', 50)->default('universal')->index();
            $table->json('embedding')->nullable();
            $table->string('patch_version', 10)->default('7.0')->index();
            $table->string('source')->nullable();
            $table->timestamps();
        });

        Schema::create('genshin_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 150)->unique();
            $table->string('name', 200);
            $table->string('rarity', 100)->nullable();
            $table->string('two_piece_bonus', 500)->nullable();
            $table->string('four_piece_bonus', 1000)->nullable();
            $table->json('raw_data')->nullable();
            $table->string('source', 500)->nullable();
            $table->string('patch_version', 20)->default('7.0')->index();
            $table->boolean('is_validated')->default(false)->index();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('genshin_weapons', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 150)->unique();
            $table->string('name', 200);
            $table->string('weapon_type', 50)->nullable()->index();
            $table->unsignedTinyInteger('rarity')->nullable()->index();
            $table->string('base_attack', 100)->nullable();
            $table->string('secondary_stat', 200)->nullable();
            $table->string('passive_name', 300)->nullable();
            $table->text('passive_description')->nullable();
            $table->json('raw_data')->nullable();
            $table->string('source', 500)->nullable();
            $table->string('patch_version', 20)->default('7.0')->index();
            $table->boolean('is_validated')->default(false)->index();
            $table->timestamps();
            $table->index('name');
        });
    }
};
