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
            'tid_primary' => '4D4F434B000A0000000000FF', 'tid_secondary' => '4D4F434B000B0000000000FF',
            'sku' => 'MOCK-0255', 'item_type_id' => 1];
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

    public function test_the_office_has_no_scanner(): void
    {
        $this->loginAs('office');
        $this->assertError(403, 'forbidden', $this->api('POST', '/scan/lookup', $this->reads(1)));
        $this->assertError(403, 'forbidden', $this->api('GET', '/sim/items'));
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
        $this->loginAs('inventory');
        $item = $this->ok($this->api('POST', '/items', $this->newMat(['epc' => '4d4f434b00000000000000ff'])))['item'];
        $this->assertSame(['MOCK-0255', 'registered', 0], [$item['sku'], $item['status'], $item['wash_count']]);

        $row = row("SELECT * FROM items WHERE sku = 'MOCK-0255'");
        $this->assertSame(['4D4F434B00000000000000FF', '4D4F434B000A0000000000FF', '4D4F434B000B0000000000FF'],
            [$row['epc'], $row['tid_primary'], $row['tid_secondary']]);

        $event = row('SELECT * FROM item_events WHERE item_id = ?', [$row['id']]);
        $this->assertSame(['register', null, 'registered', 'simulator', $this->users['inventory']],
            [$event['event_type'], $event['from_status'], $event['to_status'], $event['source'], $event['user_id']]);
    }

    public function test_register_refuses_broken_input(): void
    {
        $this->loginAs('inventory');
        $this->assertError(422, 'same_tid', $this->api('POST', '/items',
            $this->newMat(['tid_secondary' => '4D4F434B000A0000000000FF'])));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/items', $this->newMat(['item_type_id' => 999])));
        $this->assertError(422, 'invalid_tag', $this->api('POST', '/items', $this->newMat(['epc' => 'XYZ'])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/items', $this->newMat(['sku' => ''])));
        $this->assertSame(40, (int) DB::scalar('SELECT COUNT(*) FROM items'));
    }

    public function test_register_refuses_a_tag_or_sku_that_is_in_use(): void
    {
        $this->loginAs('inventory');
        $this->assertError(409, 'duplicate', $this->api('POST', '/items', $this->newMat(['epc' => $this->epc(1)])));
        $this->assertError(409, 'duplicate', $this->api('POST', '/items', $this->newMat(['sku' => 'mock-0001'])));
        // Mat 1's secondary TID offered as a primary TID: two unique keys alone would not catch this.
        $this->assertError(409, 'duplicate', $this->api('POST', '/items',
            $this->newMat(['tid_primary' => '4D4F434B000B000000000001'])));
        $this->ok($this->api('POST', '/items', $this->newMat()));
        $this->assertError(409, 'duplicate', $this->api('POST', '/items', $this->newMat()));   // sent twice
    }

    public function test_only_the_inventory_manager_registers(): void
    {
        foreach (['office', 'driver', 'washing', 'outbound'] as $who) {
            $this->loginAs($who);
            $this->assertError(403, 'forbidden', $this->api('POST', '/items', $this->newMat()));
        }
    }
}
