<?php

/** Supplies ten fixed, clearly marked demo SKU choices for each existing active mat type. */
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class SkuCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DB::table('item_types')->where('is_active', 1)->get(['id', 'name']) as $type) {
            $choices = [];
            for ($n = 1; $n <= 10; $n++) {
                $choices[] = [
                    'item_type_id' => $type->id,
                    'code' => sprintf('DEMO-MAT-%03d-%02d', $type->id, $n),
                    'label' => mb_substr(sprintf('%s — Demo SKU %02d', $type->name, $n), 0, 160),
                    'is_active' => true,
                ];
            }
            DB::table('sku_catalog')->insertOrIgnore($choices);
        }
    }
}
