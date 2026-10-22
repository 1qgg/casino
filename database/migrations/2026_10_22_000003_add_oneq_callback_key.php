<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games_keys', function (Blueprint $table) {
            $table->string('oneq_callback_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games_keys', function (Blueprint $table) {
            $table->dropColumn('oneq_callback_key');
        });
    }
};
