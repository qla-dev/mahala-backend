<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('notifications_app_location')->default(1)->after('notifications');
            $table->boolean('notifications_app_comments')->default(1)->after('notifications_app_location');
            $table->boolean('notifications_app_votes')->default(1)->after('notifications_app_comments');
            $table->boolean('notifications_location')->default(1)->after('notifications_app_votes');
            $table->boolean('notifications_comments')->default(1)->after('notifications_location');
            $table->boolean('notifications_votes')->default(1)->after('notifications_comments');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn([
                'notifications_app_location',
                'notifications_app_comments',
                'notifications_app_votes',
                'notifications_location',
                'notifications_comments',
                'notifications_votes',
            ]);
        });
    }
};
