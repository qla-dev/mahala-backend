<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('posts_archive') || DB::table('posts_archive')->count() === 0) {
            throw new RuntimeException('posts_archive is missing or empty — aborting to protect data.');
        }

        DB::table('comments')->delete();
        DB::table('post_views')->delete();
        DB::table('post_votes')->delete();
        DB::table('notifications')->delete();
        DB::table('posts')->delete();
    }

    public function down(): void
    {
        // Data restored from posts_archive manually if needed.
    }
};
