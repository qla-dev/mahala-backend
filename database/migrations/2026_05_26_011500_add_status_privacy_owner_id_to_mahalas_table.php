<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mahalas', 'status')) {
            Schema::table('mahalas', function (Blueprint $table) {
                $table->text('status')->nullable();
            });

            DB::table('mahalas')
                ->whereNull('status')
                ->update(['status' => '']);

            Schema::table('mahalas', function (Blueprint $table) {
                $table->text('status')->nullable(false)->change();
            });
        }

        if (! Schema::hasColumn('mahalas', 'privacy')) {
            Schema::table('mahalas', function (Blueprint $table) {
                $table->integer('privacy')->default(0);
            });
        }

        if (! Schema::hasColumn('mahalas', 'owner_id')) {
            Schema::table('mahalas', function (Blueprint $table) {
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mahalas', 'owner_id')) {
            Schema::table('mahalas', function (Blueprint $table) {
                $table->dropConstrainedForeignId('owner_id');
            });
        }

        if (Schema::hasColumn('mahalas', 'privacy')) {
            Schema::table('mahalas', function (Blueprint $table) {
                $table->dropColumn('privacy');
            });
        }

        if (Schema::hasColumn('mahalas', 'status')) {
            Schema::table('mahalas', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
