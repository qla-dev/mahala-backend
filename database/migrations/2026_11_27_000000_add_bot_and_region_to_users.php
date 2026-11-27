<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $addBot = ! Schema::hasColumn('users', 'bot');
        $addRegion = ! Schema::hasColumn('users', 'region');
        Schema::table('users', function (Blueprint $table) use ($addBot, $addRegion) {
            if ($addBot) {
                $table->boolean('bot')->default(false);
            }
            if ($addRegion) {
                $table->enum('region', ['ba', 'cro', 'srb', 'slo', 'any'])->default('any');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['bot', 'region']));
    }
};
