<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_knowledge', function (Blueprint $table) {
            $table->string('source')->nullable()->after('patch_version');
        });
    }

    public function down(): void
    {
        Schema::table('build_knowledge', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
