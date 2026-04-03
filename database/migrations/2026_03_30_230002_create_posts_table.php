<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_username');
            $table->unsignedBigInteger('mahala_id')->nullable()->index();
            $table->unsignedBigInteger('channel_id')->nullable()->index();
            $table->string('content', 250);
            $table->integer('votes_count')->default(0);
            $table->integer('replies_count')->default(0);
            $table->string('color_class', 50)->default('purple');
            $table->boolean('is_anonymous')->default(true);
            $table->boolean('is_image')->default(false);
            $table->string('image_url')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
