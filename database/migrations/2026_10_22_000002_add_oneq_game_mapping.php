<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('oneq_provider')->nullable()->after('distribution');
            $table->string('oneq_game')->nullable()->after('oneq_provider');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['oneq_provider', 'oneq_game']);
        });
    }
};
