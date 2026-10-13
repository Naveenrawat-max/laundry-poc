<?php
/** Loads the mock data of db/seed.sql: two customers, 40 mock mats, two draft contracts. No users. */
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        run_sql_file(base_path('../db/seed.sql'));
    }
}
