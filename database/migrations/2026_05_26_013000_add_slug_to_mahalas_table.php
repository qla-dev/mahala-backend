<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mahalas', 'slug')) {
            Schema::table('mahalas', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('name');
            });

            $usedSlugs = [];
            $mahalas = DB::table('mahalas')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'name']);

            foreach ($mahalas as $mahala) {
                DB::table('mahalas')
                    ->where('id', $mahala->id)
                    ->update([
                        'slug' => $this->buildUniqueSlug($mahala->name, $mahala->id, $usedSlugs),
                    ]);
            }

            Schema::table('mahalas', function (Blueprint $table) {
                $table->string('slug')->nullable(false)->change();
            });

            Schema::table('mahalas', function (Blueprint $table) {
                $table->unique('slug');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mahalas', 'slug')) {
            Schema::table('mahalas', function (Blueprint $table) {
                $table->dropUnique(['slug']);
            });

            Schema::table('mahalas', function (Blueprint $table) {
                $table->dropColumn('slug');
            });
        }
    }

    private function buildUniqueSlug(string $name, string $id, array &$usedSlugs): string
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = Str::slug($id);
        }

        if ($baseSlug === '') {
            $baseSlug = 'mahala';
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (isset($usedSlugs[$slug])) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        $usedSlugs[$slug] = true;

        return $slug;
    }
};
