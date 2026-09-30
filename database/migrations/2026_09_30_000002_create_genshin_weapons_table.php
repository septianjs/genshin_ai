<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genshin_weapons', function (Blueprint $table) {
            $table->id();

            /*
             * ID stabil berdasarkan nama weapon.
             *
             * Contoh:
             * freedom-sworn
             * favonius-sword
             */
            $table->string('slug', 150)->unique();

            $table->string('name', 200);

            $table->string('weapon_type', 50)
                ->nullable()
                ->index();

            $table->unsignedTinyInteger('rarity')
                ->nullable()
                ->index();

            $table->string('base_attack', 100)
                ->nullable();

            $table->string('secondary_stat', 200)
                ->nullable();

            $table->string('passive_name', 300)
                ->nullable();

            $table->text('passive_description')
                ->nullable();

            /*
             * Seluruh data asli dari weapons.json.
             */
            $table->json('raw_data')
                ->nullable();

            $table->string('source', 500)
                ->nullable();

            $table->string('patch_version', 20)
                ->default('7.0')
                ->index();

            $table->boolean('is_validated')
                ->default(false)
                ->index();

            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genshin_weapons');
    }
};