<?php
/** Tag reads, the lookup that changes nothing, the simulator's mat list, and registering a new mat. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class ScanTest extends ApiTestCase
{
    private function newMat(array $over = []): array
    {
        return $over + ['source' => 'simulator', 'epc' => '4D4F434B00000000000000FF',
            'epc_primary' => '4D4F434B00000000000000FF', 'epc_secondary' => '4D4F434B00000000000000FE',
            'tid_primary' => '4D4F434B000A0000000000FF', 'tid_secondary' => '4D4F434B000B0000000000FF',
            'catalog_sku_id' => (int) DB::scalar('SELECT id FROM sku_catalog WHERE item_type_id = 1 ORDER BY id LIMIT 1'), 'item_type_id' => 1];
    }

    public function test_lookup_tells_what_each_mat_is_and_changes_nothing(): void
    {
        $this->loginAs('outbound');
        $out = $this->ok($this->api('POST', '/scan/lookup', ['source' => 'simulator', 'reads' => [
            ['epc' => $this->epc(1)],
            ['epc' => strtolower($this->epc(1))],          // the same mat heard again, in lowercase
            ['epc' => $this->epc(2)],
            ['epc' => 'AAAA00000000000000000000'],
        ]]));

        $this->assertCount(3, $out['results']);
        $this->assertSame(['found', 'found', 'unknown'], array_column($out['results'], 'result'));
        // Exactly these fields: a lookup never says at which customer a mat lies.
        $this->assertSame(['id', 'sku', 'type', 'status', 'wash_count', 'current_wash_date', 'previous_wash_date'],
            array_keys($out['results'][0]['item']));
        $this->assertSame(['MOCK-0001', 'Mock mat 85x150 grey', 'ready'],
            [$out['results'][0]['item']['sku'], $out['results'][0]['item']['type'], $out['results'][0]['item']['status']]);
        $this->assertNull($out['results'][2]['item']);
        $this->assertSame([], $out['missing']);
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM item_events'));
    }

    public function test_a_tag_id_made_of_digits_only_is_found(): void
    {
        DB::statement("INSERT INTO items (item_type_id, sku, epc, tid_primary, tid_secondary)
           VALUES (1, 'DIGITS-8', '12345678', 'A0000001', 'A0000002'),
                  (1, 'DIGITS-24', '123456789012345678901234', 'A0000003', 'A0000004')");
        $this->loginAs('inventory');
        $out = $this->ok($this->api('POST', '/scan/lookup',
            ['source' => 'simulator', 'reads' => [['epc' => '12345678'], ['epc' => '123456789012345678901234']]]));
        $this->assertSame(['12345678', '123456789012345678901234'], array_column($out['results'], 'epc'));
        $this->assertSame(['DIGITS-8', 'DIGITS-24'], array_column(array_column($out['results'], 'item'), 'sku'));
    }

    public function test_a_broken_scan_body_is_refused(): void
    {
        $this->loginAs('driver');
        $this->assertError(422, 'invalid_input', $this->api('POST', '/scan/lookup', ['source' => 'simulator', 'reads' => []]));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/scan/lookup', ['source' => 'simulator']));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/scan/lookup', ['reads' => [['epc' => $this->epc(1)]]]));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/scan/lookup', ['source' => 'web'] + $this->reads(1)));
        $this->assertError(422, 'invalid_tag', $this->api('POST', '/scan/lookup', ['source' => 'simulator', 'reads' => [['epc' => 'NOT-HEX!']]]));
        $this->assertError(422, 'invalid_tag', $this->api('POST', '/scan/lookup', ['source' => 'simulator', 'reads' => ['just a string']]));
    }

    public function test_the_office_can_use_demo_scanning_for_registration(): void
    {
        $this->loginAs('office');
        $this->assertSame('found', $this->ok($this->api('POST', '/scan/lookup', $this->reads(1)))['results'][0]['result']);
        $this->assertCount(40, $this->ok($this->api('GET', '/sim/items'))['items']);
    }

    public function test_the_simulator_can_hear_every_mat(): void
    {
        $this->loginAs('washing');
        $items = $this->ok($this->api('GET', '/sim/items'))['items'];
        $this->assertCount(40, $items);
        $this->assertSame(['sku' => 'MOCK-0001', 'epc' => $this->epc(1)], $items[0]);
    }

    public function test_switching_the_simulator_off_closes_its_list_and_refuses_its_scans(): void
    {
        $this->loginAs('driver');
        config(['laundry.simulator' => false]);   // what SCANNER_SIMULATOR=false in .env does
        $this->assertError(404, 'not_found', $this->api('GET', '/sim/items'));
        $this->assertError(403, 'simulator_off', $this->api('POST', '/scan/lookup', $this->reads(1)));
        $this->ok($this->api('POST', '/scan/lookup', ['source' => 'c72'] + $this->reads(1)));
    }

    public function test_register_stores_a_new_mat_and_writes_history(): void
    {
        $this->loginAs('office');
        $item = $this->ok($this->api('POST', '/items', $this->newMat(['epc' => '4d4f434b00000000000000ff'])))['item'];
        $this->assertMatchesRegularExpression('/^MAT-[0-9A-F]{16}$/', $item['sku']);
        $this->assertSame(['ready', 0], [$item['status'], $item['wash_count']]);
        $this->assertSame(9, (int) DB::table('sku_catalog')->where('id', $item['catalog_sku_id'])->value('untagged_quantity'));
        $catalog = $this->ok($this->api('GET', '/sku-catalog?item_type_id=1'))['rows'];
        $sku = array_values(array_filter($catalog, fn (array $sku) => $sku['id'] === $item['catalog_sku_id']))[0];
        $this->assertSame([9, 1, 1, 10], [$sku['untagged_quantity'], $sku['tagged_quantity'], $sku['ready_quantity'], $sku['total_quantity']]);

        $row = row('SELECT * FROM items WHERE id = ?', [$item['id']]);
        $this->assertSame(['4D4F434B00000000000000FF', '4D4F434B000A0000000000FF', '4D4F434B000B0000000000FF'],
            [$row['epc'], $row['tid_primary'], $row['tid_secondary']]);

        $event = row('SELECT * FROM item_events WHERE item_id = ?', [$row['id']]);
        $this->assertSame(['register', null, 'ready', 'simulator', $this->users['office']],
            [$event['event_type'], $event['from_status'], $event['to_status'], $event['source'], $event['user_id']]);
    }

    public function test_register_refuses_broken_input(): void
    {
        $this->loginAs('office');
        $this->assertError(422, 'same_tid', $this->api('POST', '/items',
            $this->newMat(['tid_secondary' => '4D4F434B000A0000000000FF'])));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/items', $this->newMat(['item_type_id' => 999])));
        $this->assertError(422, 'invalid_tag', $this->api('POST', '/items', $this->newMat(['epc_primary' => 'XYZ'])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/items', $this->newMat(['catalog_sku_id' => null])));
        $this->assertSame(40, (int) DB::scalar('SELECT COUNT(*) FROM items'));
    }

    public function test_register_refuses_a_tag_or_sku_that_is_in_use(): void
    {
        $this->loginAs('office');
        $this->assertError(409, 'duplicate', $this->api('POST', '/items', $this->newMat(['epc' => $this->epc(1), 'epc_primary' => $this->epc(1)])));
        // Mat 1's secondary TID offered as a primary TID: two unique keys alone would not catch this.
        $this->assertError(409, 'duplicate', $this->api('POST', '/items',
            $this->newMat(['tid_primary' => '4D4F434B000B000000000001'])));
        $this->ok($this->api('POST', '/items', $this->newMat()));
        $this->assertError(409, 'duplicate', $this->api('POST', '/items', $this->newMat()));   // sent twice
        $this->assertSame(9, (int) DB::table('sku_catalog')->where('id', $this->newMat()['catalog_sku_id'])->value('untagged_quantity'));
    }

    public function test_only_the_office_registers(): void
    {
        foreach (['inventory', 'driver', 'washing', 'outbound'] as $who) {
            $this->loginAs($who);
            $this->assertError(403, 'forbidden', $this->api('POST', '/items', $this->newMat()));
        }
    }

    public function test_catalog_has_ten_fixed_choices_per_type_and_rejects_wrong_or_inactive_choice(): void
    {
        $this->loginAs('office');
        $catalog = $this->ok($this->api('GET', '/sku-catalog?item_type_id=1'))['rows'];
        $this->assertGreaterThanOrEqual(10, count($catalog));
        $this->assertSame([1], array_values(array_unique(array_column($catalog, 'item_type_id'))));
        $wrong = (int) DB::scalar('SELECT id FROM sku_catalog WHERE item_type_id = 2 LIMIT 1');
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/items', $this->newMat(['catalog_sku_id' => $wrong])));
        DB::table('sku_catalog')->where('id', $catalog[0]['id'])->update(['is_active' => 0]);
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/items', $this->newMat(['catalog_sku_id' => $catalog[0]['id']])));
    }

    public function test_common_epc_always_uses_first_tag_and_ignores_manual_override(): void
    {
        $this->loginAs('office');
        $first = $this->ok($this->api('POST', '/items', $this->newMat(['epc' => '4D4F434B00000000000000FE'])))['item'];
        $this->assertSame('4D4F434B00000000000000FF', $first['epc']);
        $second = $this->ok($this->api('POST', '/items', $this->newMat([
            'epc' => 'XYZ', 'epc_primary' => 'AAAA0001', 'epc_secondary' => 'AAAA0002',
            'tid_primary' => 'BBBB0001', 'tid_secondary' => 'BBBB0002',
        ])))['item'];
        $this->assertSame('AAAA0001', $second['epc']);
        $this->assertSame($first['catalog_sku_id'], $second['catalog_sku_id']);
        $this->assertNotSame($first['sku'], $second['sku']);
    }

    public function test_mapping_without_stock_creates_nothing_and_does_not_go_negative(): void
    {
        $body = $this->newMat();
        DB::table('sku_catalog')->where('id', $body['catalog_sku_id'])->update(['untagged_quantity' => 0]);
        $this->loginAs('office');
        $this->assertError(409, 'out_of_stock', $this->api('POST', '/items', $body));
        $this->assertSame(40, DB::table('items')->count());
        $this->assertSame(0, DB::table('item_events')->count());
        $this->assertSame(0, (int) DB::table('sku_catalog')->where('id', $body['catalog_sku_id'])->value('untagged_quantity'));
    }
}
