<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('posts_archive')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->archiveSqlitePosts();

            return;
        }

        // LIKE keeps the columns and indexes without foreign keys to live records.
        DB::statement('CREATE TABLE IF NOT EXISTS posts_archive_build LIKE posts');

        // A retry after a completed copy can publish the same snapshot.
        DB::statement('INSERT INTO posts_archive_build SELECT * FROM posts WHERE NOT EXISTS (SELECT 1 FROM posts_archive_build)');
        DB::statement('RENAME TABLE posts_archive_build TO posts_archive');
    }

    private function archiveSqlitePosts(): void
    {
        DB::transaction(function (): void {
            Schema::create('posts_archive', function (Blueprint $table): void {
                $table->id();
                $table->string('topic_id')->index();
                $table->unsignedBigInteger('author_user_id')->nullable();
                $table->string('mahala_id')->nullable();
                $table->text('content')->nullable();
                $table->text('image_uri')->nullable();
                $table->boolean('is_anonymous')->default(true);
                $table->integer('status')->default(0);
                $table->timestamps();
                $table->boolean('hidden')->nullable();
            });

            $columns = Schema::getColumnListing('posts_archive');
            DB::table('posts_archive')->insertUsing($columns, DB::table('posts')->select($columns));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts_archive');
    }
};
