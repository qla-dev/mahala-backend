<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('topics', 'color_hex')) {
            return;
        }

        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn('color_hex');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('topics', 'color_hex')) {
            return;
        }

        Schema::table('topics', function (Blueprint $table) {
            $table->char('color_hex', 7)->default('#7c3aed')->after('description');
        });
    }
};
