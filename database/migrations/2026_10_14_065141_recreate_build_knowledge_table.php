
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('build_knowledge')) {
            return;
        }

        Schema::create('build_knowledge', function (Blueprint $table) {
            $table->id();

            $table->foreignId('character_id')
                ->constrained('characters')
                ->cascadeOnDelete();

            $table->string('category', 50)->index();
            $table->string('title', 200)->nullable();
            $table->longText('content');

            $table->string('target_content', 50)
                ->default('universal')
                ->index();

            $table->json('embedding')->nullable();
            $table->string('patch_version', 10)
                ->default('7.0')
                ->index();

            $table->string('source')->nullable();

            $table->timestamps();

            $table->index(
                ['character_id', 'category', 'target_content'],
                'build_knowledge_retrieval_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_knowledge');
    }
};
