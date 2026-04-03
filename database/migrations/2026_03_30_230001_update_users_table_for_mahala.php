<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->string('first_name')->after('id');
            $table->string('last_name')->after('first_name');
            $table->string('username')->unique()->after('last_name');
            $table->string('role')->default('user')->after('password');
            $table->integer('karma')->default(0)->after('role');
            $table->boolean('is_premium')->default(false)->after('karma');
            $table->string('avatar_url')->nullable()->after('is_premium');
            $table->unsignedBigInteger('mahala_id')->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'username',
                'role',
                'karma',
                'is_premium',
                'avatar_url',
                'mahala_id',
            ]);
            $table->string('name')->after('id');
        });
    }
};
