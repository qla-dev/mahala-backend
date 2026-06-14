<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MahalasSqlSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/assets/mahalas.sql');

        if (! is_file($path)) {
            throw new RuntimeException("Mahalas SQL seed file not found: {$path}");
        }

        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException("Unable to read mahalas SQL seed file: {$path}");
        }

        preg_match_all('/INSERT\s+INTO\s+`?mahalas`?\s*\([^;]+?;/is', $sql, $matches);

        foreach ($matches[0] as $statement) {
            DB::unprepared($this->makeInsertIdempotent($statement));
        }
    }

    private function makeInsertIdempotent(string $statement): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => preg_replace('/^INSERT\s+INTO/i', 'INSERT OR IGNORE INTO', $statement, 1),
            'pgsql' => preg_replace('/;\s*$/', ' ON CONFLICT DO NOTHING;', $statement),
            default => preg_replace('/^INSERT\s+INTO/i', 'INSERT IGNORE INTO', $statement, 1),
        };
    }
}
