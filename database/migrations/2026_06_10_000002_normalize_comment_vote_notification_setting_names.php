<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->moveColumn('notifications_app_comments', 'notifications_comments');
        $this->moveColumn('notifications_app_votes', 'notifications_votes');
    }

    public function down(): void
    {
        $this->moveColumn('notifications_comments', 'notifications_app_comments');
        $this->moveColumn('notifications_votes', 'notifications_app_votes');
    }

    private function moveColumn(string $from, string $to): void
    {
        if (!Schema::hasColumn('user_settings', $from)) {
            return;
        }

        if (Schema::hasColumn('user_settings', $to)) {
            Schema::table('user_settings', function (Blueprint $table) use ($from) {
                $table->dropColumn($from);
            });

            return;
        }

        Schema::table('user_settings', function (Blueprint $table) use ($from, $to) {
            $table->renameColumn($from, $to);
        });
    }
};
