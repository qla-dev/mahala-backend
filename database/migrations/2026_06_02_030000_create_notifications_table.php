<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('type');
            $table->smallInteger('vote_value')->nullable();
            $table->text('title')->nullable();
            $table->text('body')->nullable();
            $table->foreignId('related_post_id')->nullable()->constrained('posts')->cascadeOnDelete();
            $table->foreignId('related_comment_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['related_post_id', 'created_at']);
            $table->index(['related_comment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
