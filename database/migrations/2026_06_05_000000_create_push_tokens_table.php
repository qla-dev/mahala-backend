<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('token');
            $table->string('provider', 32)->default('expo');
            $table->string('platform', 32)->nullable();
            $table->string('notification_channel_id')->nullable();
            $table->string('sound')->nullable();
            $table->json('preferences')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('token');
            $table->index(['user_id', 'provider', 'disabled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
