<?php

/** SKU variants hold untagged stock; tagged availability comes from the existing mat movements. */
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SkuController extends Controller
{
    private const MAX_STOCK = 4294967295;

    public function index(Request $request): array
    {
        $in = $request->validate(['item_type_id' => 'nullable|integer|min:1']);
        return ['rows' => $this->catalogRows(isset($in['item_type_id']) ? (int) $in['item_type_id'] : null)];
    }

    private function catalogRows(?int $typeId = null, ?int $skuId = null): array
    {
        $rows = rows("SELECT s.id, s.item_type_id, s.code, s.label, s.width_cm, s.height_cm, s.color,
            s.untagged_quantity, t.name AS type_name, c.id AS category_id, c.name AS category_name,
            COALESCE(q.ready_quantity,0) AS ready_quantity, COALESCE(q.reserved_quantity,0) AS reserved_quantity,
            COALESCE(q.on_van_quantity,0) AS on_van_quantity, COALESCE(q.at_customer_quantity,0) AS at_customer_quantity,
            COALESCE(q.washing_quantity,0) AS washing_quantity, COALESCE(q.repair_quantity,0) AS repair_quantity,
            COALESCE(q.scrapped_quantity,0) AS scrapped_quantity, COALESCE(q.tagged_quantity,0) AS tagged_quantity,
            s.untagged_quantity + COALESCE(q.tagged_quantity,0) - COALESCE(q.scrapped_quantity,0) AS total_quantity
            FROM sku_catalog s JOIN item_types t ON t.id = s.item_type_id JOIN categories c ON c.id = t.category_id
            LEFT JOIN (
                SELECT i.catalog_sku_id, COUNT(*) AS tagged_quantity,
                    SUM(i.status IN ('ready','qc_passed') AND r.item_id IS NULL AND h.item_id IS NULL) AS ready_quantity,
                    SUM(r.item_id IS NOT NULL OR h.item_id IS NOT NULL) AS reserved_quantity,
                    SUM(i.status IN ('out_for_delivery','refused','collected')) AS on_van_quantity,
                    SUM(i.status = 'at_customer') AS at_customer_quantity,
                    SUM(i.status IN ('in_washing','rewash')) AS washing_quantity,
                    SUM(i.status = 'repair') AS repair_quantity, SUM(i.status = 'scrapped') AS scrapped_quantity
                FROM items i
                LEFT JOIN (SELECT DISTINCT reserved_item_id AS item_id FROM order_items WHERE reserved_item_id IS NOT NULL) r ON r.item_id = i.id
                LEFT JOIN (SELECT DISTINCT hi.item_id FROM handover_items hi JOIN handovers h ON h.id = hi.handover_id
                    WHERE h.status = 'open' AND (hi.given_at IS NOT NULL OR hi.received_at IS NOT NULL)) h ON h.item_id = i.id
                GROUP BY i.catalog_sku_id
            ) q ON q.catalog_sku_id = s.id
            WHERE s.is_active = 1 AND t.is_active = 1 AND c.is_active = 1"
            .($typeId !== null ? ' AND s.item_type_id = ?' : '')
            .($skuId !== null ? ' AND s.id = ?' : '').' ORDER BY c.name,t.name,s.code',
            [...($typeId !== null ? [$typeId] : []), ...($skuId !== null ? [$skuId] : [])]);
        foreach ($rows as &$row) {
            foreach ($row as $key => $value) {
                if (str_ends_with($key, '_quantity') || in_array($key, ['id', 'item_type_id', 'category_id'], true)) {
                    $row[$key] = (int) $value;
                } elseif (in_array($key, ['width_cm', 'height_cm'], true)) {
                    $row[$key] = $value === null ? null : (float) $value;
                }
            }
        }
        unset($row);

        return $rows;
    }

    public function store(Request $request): array
    {
        $in = $request->validate([
            'item_type_id' => 'required|integer|min:1',
            'width_cm' => 'required|numeric|decimal:0,2|min:0.01|max:999999.99',
            'height_cm' => 'required|numeric|decimal:0,2|min:0.01|max:999999.99',
            'color' => 'required|string|max:80', 'quantity' => 'required|integer|min:0|max:'.self::MAX_STOCK,
        ]);
        $color = preg_replace('/\s+/u', ' ', trim($in['color']));
        if ($color === '') {
            fail(422, 'invalid_input', 'A color is required.');
        }
        try {
            $sku = DB::transaction(function () use ($in, $color): array {
                $type = row('SELECT t.id,t.name FROM item_types t JOIN categories c ON c.id = t.category_id
                    WHERE t.id = ? AND t.is_active = 1 AND c.is_active = 1 FOR UPDATE', [$in['item_type_id']]);
                if ($type === null) {
                    fail(422, 'invalid_reference', 'Choose an active mat type in an active category.');
                }
                $id = DB::table('sku_catalog')->insertGetId([
                    'item_type_id' => $type['id'], 'code' => 'MAT-'.Str::ulid(),
                    'label' => mb_substr($type['name'].' · '.(float) $in['width_cm'].' × '.(float) $in['height_cm'].' cm · '.$color, 0, 160),
                    'width_cm' => $in['width_cm'], 'height_cm' => $in['height_cm'], 'color' => $color,
                    'untagged_quantity' => (int) $in['quantity'],
                ]);
                return $this->catalogRows(skuId: $id)[0];
            });
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                fail(409, 'duplicate', 'This SKU variant already exists. Add stock to the existing SKU.');
            }
            throw $error;
        }
        return ['row' => $sku];
    }

    public function stock(Request $request, int $id): array
    {
        $in = $request->validate(['quantity' => 'required|integer|min:1|max:'.self::MAX_STOCK]);
        $sku = DB::transaction(function () use ($id, $in): array {
            $sku = row('SELECT s.untagged_quantity FROM sku_catalog s JOIN item_types t ON t.id = s.item_type_id
                JOIN categories c ON c.id = t.category_id WHERE s.id = ? AND s.is_active = 1 AND t.is_active = 1 AND c.is_active = 1 FOR UPDATE', [$id]);
            if ($sku === null) {
                fail(422, 'invalid_reference', 'Choose an active SKU in an active category.');
            }
            if ((int) $sku['untagged_quantity'] > self::MAX_STOCK - (int) $in['quantity']) {
                fail(422, 'stock_overflow', 'This stock receipt exceeds the supported quantity.');
            }
            DB::table('sku_catalog')->where('id', $id)->increment('untagged_quantity', (int) $in['quantity']);
            return $this->catalogRows(skuId: $id)[0];
        });
        return ['row' => $sku];
    }

    /** Removes a SKU that has no mapped mats. Mapped mats keep their history, so that SKU stays. */
    public function destroy(int $id): array
    {
        DB::transaction(function () use ($id): void {
            $sku = DB::table('sku_catalog')->where('id', $id)->lockForUpdate()->first();
            if ($sku === null) {
                fail(404, 'not_found', 'No such SKU.');
            }
            if (DB::table('items')->where('catalog_sku_id', $id)->exists()) {
                fail(409, 'in_use', 'This SKU has mapped mats, so it stays on record.');
            }
            DB::table('sku_catalog')->where('id', $id)->delete();
        });
        return ['deleted' => true];
    }
}
