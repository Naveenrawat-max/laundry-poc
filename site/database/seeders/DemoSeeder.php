<?php
/** Loads the bigger demo data of db/demo.sql on top of the mock data: 200 more mats and 10 more buildings. No users. */
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        run_sql_file(base_path('../db/demo.sql'));
        $this->call(SkuCatalogSeeder::class);
    }
}
