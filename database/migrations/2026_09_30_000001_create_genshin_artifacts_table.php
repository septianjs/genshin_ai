<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genshin_artifacts', function (Blueprint $table) {
            $table->id();

            /*
             * ID internal yang stabil.
             * Contoh:
             * gladiators_finale
             * wanderers_troupe
             */
            $table->string('slug', 150)->unique();

            $table->string('name', 200);

            $table->string('rarity', 100)->nullable();

            $table->string('two_piece_bonus', 500)->nullable();

            $table->string('four_piece_bonus', 1000)->nullable();

            /*
             * Menyimpan seluruh data JSON asli.
             * Ini penting karena dataset dapat berubah
             * tanpa kita harus mengubah schema setiap kali.
             */
            $table->json('raw_data')->nullable();

            /*
             * Sumber data.
             */
            $table->string('source', 500)->nullable();

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
        Schema::dropIfExists('genshin_artifacts');
    }
};