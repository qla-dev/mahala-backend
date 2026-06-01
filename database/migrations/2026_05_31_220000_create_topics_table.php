<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->string('mahala_id');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('name');
            $table->string('slug');
            $table->text('description');
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_system')->default(false);
            $table->integer('status')->default(0);
            $table->timestamps();

            $table->unique('slug');
            $table->foreign('mahala_id')->references('id')->on('mahalas')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};
