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
            $table->string('channel_id');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mahala_id')->nullable();
            $table->text('content')->nullable();
            $table->text('image_uri')->nullable();
            $table->boolean('is_anonymous')->default(true);
            $table->integer('status')->default(0);
            $table->timestamps();
            $table->boolean('hidden')->nullable();

            $table->foreign('mahala_id')->references('id')->on('mahalas')->nullOnDelete();
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
