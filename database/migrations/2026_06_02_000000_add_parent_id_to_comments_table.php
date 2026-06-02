<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('comments', 'parent_id')) {
            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            $table
                ->foreignId('parent_id')
                ->nullable()
                ->after('post_id')
                ->constrained('comments')
                ->cascadeOnDelete();
            $table->index(['parent_id', 'status']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('comments', 'parent_id')) {
            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'status']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
