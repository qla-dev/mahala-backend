<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = [
        'notifications_app',
        'notifications',
        'notifications_app_location',
        'notifications_app_comments',
        'notifications_app_votes',
        'notifications_new_mahala',
        'notifications_startup',
    ];

    public function up(): void
    {
        $existingColumns = array_values(array_filter(
            $this->columns,
            fn (string $column) => Schema::hasColumn('user_settings', $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table('user_settings', function (Blueprint $table) use ($existingColumns) {
            $table->dropColumn($existingColumns);
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (!Schema::hasColumn('user_settings', $column)) {
                    $table->boolean($column)->default(1);
                }
            }
        });
    }
};
