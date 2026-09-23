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
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 150);
            $table->string('title', 200)->nullable();
            $table->string('vision', 50)->index();
            $table->string('weapon_type', 50)->index();
            $table->unsignedTinyInteger('rarity')->default(5);
            $table->text('description')->nullable();
            $table->json('skill_data')->nullable();
            $table->json('constellation_data')->nullable();
            $table->json('ascension_materials')->nullable();
            $table->string('icon_url', 255)->nullable();
            $table->string('patch_version', 10)->default('7.0')->index();
            $table->boolean('is_validated')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
