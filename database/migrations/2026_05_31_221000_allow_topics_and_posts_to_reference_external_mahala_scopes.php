<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['mahala_id']);
        });

        Schema::table('topics', function (Blueprint $table) {
            $table->dropForeign(['mahala_id']);
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->foreign('mahala_id')->references('id')->on('mahalas')->cascadeOnDelete();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreign('mahala_id')->references('id')->on('mahalas')->nullOnDelete();
        });
    }
};
