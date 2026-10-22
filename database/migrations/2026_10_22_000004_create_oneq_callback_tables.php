<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oneq_callback_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('nonce', 128)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('oneq_callback_requests', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('processing');
            $table->json('response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oneq_callback_requests');
        Schema::dropIfExists('oneq_callback_nonces');
    }
};
