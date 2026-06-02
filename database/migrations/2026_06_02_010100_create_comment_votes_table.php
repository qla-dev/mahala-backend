<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reply_id')->constrained('comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->smallInteger('value');
            $table->timestamps();

            $table->unique(['reply_id', 'user_id']);
            $table->index(['reply_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_votes');
    }
};
