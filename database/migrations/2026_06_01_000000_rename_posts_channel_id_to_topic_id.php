<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['channel_id']);
            $table->renameColumn('channel_id', 'topic_id');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->index('topic_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['topic_id']);
            $table->renameColumn('topic_id', 'channel_id');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->index('channel_id');
        });
    }
};
