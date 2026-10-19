<?php

/** Category ownership, SKU stock receipts and availability derived from individual mat movements. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class SkuTest extends ApiTestCase
{
    private function variant(array $over = []): array
    {
        return $over + ['item_type_id' => 1, 'width_cm' => 50, 'height_cm' => 60, 'color' => 'Red', 'quantity' => 10];
    }

    private function catalogRow(int $id): array
    {
        $rows = $this->ok($this->api('GET', '/sku-catalog'))['rows'];
        return array_values(array_filter($rows, fn (array $row) => $row['id'] === $id))[0];
    }

    public function test_category_has_mat_types_and_names_are_unique_within_that_category(): void
    {
        $this->loginAs('office');
        $indoor = $this->ok($this->api('POST', '/categories', ['name' => 'Indoor']))['row'];
        $outdoor = $this->ok($this->api('POST', '/categories', ['name' => 'Outdoor']))['row'];
        $type = $this->ok($this->api('POST', '/item-types', ['category_id' => $indoor['id'], 'name' => 'Rubber mat']))['row'];
        $this->assertSame($indoor['id'], $type['category_id']);
        $this->assertError(409, 'duplicate', $this->api('POST', '/categories', ['name' => 'Indoor']));
        $this->assertError(409, 'duplicate', $this->api('POST', '/item-types', ['category_id' => $indoor['id'], 'name' => 'Rubber mat']));
        $other = $this->ok($this->api('POST', '/item-types', ['category_id' => $outdoor['id'], 'name' => 'Rubber mat']))['row'];
        $this->assertNotSame($type['id'], $other['id']);
        $this->assertError(422, 'invalid_input', $this->api('POST', '/item-types', ['name' => 'No category']));
        $this->ok($this->api('PATCH', "/categories/{$outdoor['id']}", ['is_active' => false]));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/item-types', ['category_id' => $outdoor['id'], 'name' => 'Inactive category']));
    }

    public function test_sku_creation_generates_code_and_stock_does_not_create_ready_mats(): void
    {
        $this->loginAs('office');
        $sku = $this->ok($this->api('POST', '/sku-catalog', $this->variant(['color' => '  Dark   Red  '])))['row'];
        $this->assertMatchesRegularExpression('/^MAT-[0-9A-HJKMNP-TV-Z]{26}$/', $sku['code']);
        $this->assertSame('Dark Red', $sku['color']);
        $this->assertEquals([50, 60], [$sku['width_cm'], $sku['height_cm']]);
        $this->assertSame([10, 0, 0, 10], [$sku['untagged_quantity'], $sku['tagged_quantity'], $sku['ready_quantity'], $sku['total_quantity']]);
        $this->assertSame(40, DB::table('items')->count());
        $this->assertError(409, 'duplicate', $this->api('POST', '/sku-catalog', $this->variant(['color' => 'Dark Red'])));
        $stock = $this->ok($this->api('POST', "/sku-catalog/{$sku['id']}/stock", ['quantity' => 5]))['row'];
        $this->assertSame([15, 0, 15], [$stock['untagged_quantity'], $stock['ready_quantity'], $stock['total_quantity']]);
        $this->assertSame($sku['code'], $stock['code']);
    }

    public function test_stock_response_uses_saved_sku_and_ignores_extra_catalog_filter(): void
    {
        $this->loginAs('office');
        $sku = $this->ok($this->api('POST', '/sku-catalog', $this->variant()))['row'];
        $stock = $this->ok($this->api('POST', "/sku-catalog/{$sku['id']}/stock",
            ['quantity' => 5, 'item_type_id' => 'not-an-id']))['row'];
        $this->assertSame([$sku['id'], 15], [$stock['id'], $stock['untagged_quantity']]);
        $this->assertSame(15, (int) DB::table('sku_catalog')->where('id', $sku['id'])->value('untagged_quantity'));
    }

    public function test_invalid_variant_or_receipt_cannot_change_stock(): void
    {
        $this->loginAs('office');
        foreach ([['width_cm' => 0], ['height_cm' => 1.001], ['color' => ' '], ['quantity' => -1], ['quantity' => 1.5]] as $over) {
            $this->assertError(422, 'invalid_input', $this->api('POST', '/sku-catalog', $this->variant($over)));
        }
        $sku = $this->ok($this->api('POST', '/sku-catalog', $this->variant()))['row'];
        foreach ([0, -1, 1.5, 4294967296] as $quantity) {
            $this->assertError(422, 'invalid_input', $this->api('POST', "/sku-catalog/{$sku['id']}/stock", ['quantity' => $quantity]));
        }
        DB::table('sku_catalog')->where('id', $sku['id'])->update(['untagged_quantity' => 4294967295]);
        $this->assertError(422, 'stock_overflow', $this->api('POST', "/sku-catalog/{$sku['id']}/stock", ['quantity' => 1]));
        $this->assertSame(4294967295, (int) DB::table('sku_catalog')->where('id', $sku['id'])->value('untagged_quantity'));
    }

    public function test_catalog_counts_locations_and_washing_separately_from_untagged_stock(): void
    {
        $this->loginAs('office');
        $sku = $this->ok($this->api('POST', '/sku-catalog', $this->variant()))['row'];
        foreach ([1 => ['ready', null, null], 2 => ['qc_passed', null, null],
            3 => ['out_for_delivery', $this->users['driver'], null], 4 => ['at_customer', null, 1],
            5 => ['in_washing', null, null], 6 => ['rewash', null, null],
            7 => ['repair', null, null], 8 => ['scrapped', null, null]] as $mat => [$status, $holder, $zone]) {
            $this->setItems([$mat], $status, $holder, $zone);
            DB::table('items')->where('id', $this->item($mat)['id'])->update(['catalog_sku_id' => $sku['id']]);
        }
        $count = $this->catalogRow($sku['id']);
        $this->assertSame([10, 8, 17, 2, 1, 1, 2, 1, 1], [
            $count['untagged_quantity'], $count['tagged_quantity'], $count['total_quantity'], $count['ready_quantity'],
            $count['on_van_quantity'], $count['at_customer_quantity'], $count['washing_quantity'],
            $count['repair_quantity'], $count['scrapped_quantity'],
        ]);
        $this->setItems([1], 'out_for_delivery', $this->users['driver']);
        $count = $this->catalogRow($sku['id']);
        $this->assertSame([10, 17, 1, 2], [$count['untagged_quantity'], $count['total_quantity'], $count['ready_quantity'], $count['on_van_quantity']]);
        $this->setItems([1], 'at_customer', null, 1);
        $count = $this->catalogRow($sku['id']);
        $this->assertSame([10, 17, 1, 2], [$count['untagged_quantity'], $count['total_quantity'], $count['on_van_quantity'], $count['at_customer_quantity']]);
    }

    public function test_inactive_category_hides_sku_and_prevents_receipts_and_mapping(): void
    {
        $this->loginAs('office');
        $sku = $this->ok($this->api('POST', '/sku-catalog', $this->variant()))['row'];
        DB::table('categories')->where('id', $sku['category_id'])->update(['is_active' => false]);
        $this->assertSame([], $this->ok($this->api('GET', '/sku-catalog'))['rows']);
        $this->assertError(422, 'invalid_reference', $this->api('POST', "/sku-catalog/{$sku['id']}/stock", ['quantity' => 5]));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/items', ['catalog_sku_id' => $sku['id']]));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/sku-catalog', $this->variant(['color' => 'Blue'])));
        $this->assertSame(10, (int) DB::table('sku_catalog')->where('id', $sku['id'])->value('untagged_quantity'));
    }

    public function test_pending_handover_reserves_one_mat_without_double_counting_repeated_scans(): void
    {
        $this->loginAs('office');
        $sku = $this->ok($this->api('POST', '/sku-catalog', $this->variant()))['row'];
        $this->setItems([1], 'qc_passed');
        DB::table('items')->where('id', $this->item(1)['id'])->update(['catalog_sku_id' => $sku['id']]);
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1);
        $this->scanHandover('inventory', $handover, 1);
        $this->loginAs('office');
        $count = $this->catalogRow($sku['id']);
        $this->assertSame([10, 1, 11, 0, 1], [$count['untagged_quantity'], $count['tagged_quantity'],
            $count['total_quantity'], $count['ready_quantity'], $count['reserved_quantity']]);
    }

    public function test_only_office_can_read_or_change_categories_and_sku_stock(): void
    {
        $id = (int) DB::table('sku_catalog')->orderBy('id')->value('id');
        foreach (['inventory', 'driver', 'washing', 'outbound'] as $role) {
            $this->loginAs($role);
            $this->assertError(403, 'forbidden', $this->api('GET', '/categories'));
            $this->assertError(403, 'forbidden', $this->api('POST', '/categories', ['name' => 'Forbidden']));
            $this->assertError(403, 'forbidden', $this->api('GET', '/sku-catalog'));
            $this->assertError(403, 'forbidden', $this->api('POST', '/sku-catalog', $this->variant()));
            $this->assertError(403, 'forbidden', $this->api('POST', "/sku-catalog/$id/stock", ['quantity' => 5]));
        }
        $this->assertSame(10, (int) DB::table('sku_catalog')->where('id', $id)->value('untagged_quantity'));
    }
}
