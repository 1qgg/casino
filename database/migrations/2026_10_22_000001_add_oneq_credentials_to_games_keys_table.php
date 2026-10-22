<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games_keys', function (Blueprint $table) {
            $table->string('oneq_operator_key')->nullable();
            $table->text('oneq_operator_secret')->nullable();
            $table->text('oneq_callback_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games_keys', function (Blueprint $table) {
            $table->dropColumn(['oneq_operator_key', 'oneq_operator_secret', 'oneq_callback_secret']);
        });
    }
};
