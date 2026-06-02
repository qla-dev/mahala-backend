<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->smallInteger('value');
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);
            $table->index(['post_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_votes');
    }
};
