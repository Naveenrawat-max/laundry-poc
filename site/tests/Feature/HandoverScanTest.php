<?php
/** Opening a handover and the two scans: a mat moves only when both sides scanned it, and only once. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class HandoverScanTest extends ApiTestCase
{
    public function test_inventory_and_outbound_open_a_handover_to_a_driver(): void
    {
        $this->loginAs('inventory');
        $handover = $this->ok($this->api('POST', '/handovers',
            ['type' => 'inventory_to_driver', 'receiver_id' => $this->users['driver']]))['handover'];
        $this->assertSame(['inventory_to_driver', 'open', []], [$handover['type'], $handover['status'], $handover['items']]);
        $this->assertSame([$this->users['inventory'], $this->users['driver']], [$handover['giver']['id'], $handover['receiver']['id']]);

        // Sent twice on a bad connection: the open handover comes back, no second one is made.
        $this->assertSame($handover['id'], $this->openHandover('inventory', 'inventory_to_driver', 'driver'));
        $this->assertNotSame($handover['id'], $this->openHandover('outbound', 'inventory_to_driver', 'driver'));
        $this->assertSame(2, (int) DB::scalar('SELECT COUNT(*) FROM handovers'));
    }

    public function test_a_handover_needs_the_right_giver_and_receiver(): void
    {
        $this->loginAs('driver');
        $this->assertError(403, 'forbidden', $this->api('POST', '/handovers',
            ['type' => 'inventory_to_driver', 'receiver_id' => $this->users['driver2']]));

        $this->loginAs('inventory');
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/handovers',
            ['type' => 'inventory_to_driver', 'receiver_id' => $this->users['washing']]));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/handovers',
            ['type' => 'nonsense', 'receiver_id' => $this->users['driver']]));
        DB::statement('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver2']]);
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/handovers',
            ['type' => 'inventory_to_driver', 'receiver_id' => $this->users['driver2']]));
    }

    public function test_a_mat_moves_only_when_both_sides_have_scanned_it(): void
    {
        $this->setItems([1, 2], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');

        $give = $this->scanHandover('inventory', $handover, 1, 2);
        $this->assertSame(['found', 'found'], array_column($give['results'], 'result'));
        $this->assertSame([], $give['missing']);
        $this->assertSame('qc_passed', $this->item(1)['status']);   // one scan is not enough

        $receive = $this->scanHandover('driver', $handover, 1);
        $this->assertSame(['found', 'out_for_delivery'], [$receive['results'][0]['result'], $receive['results'][0]['item']['status']]);
        $this->assertSame(['MOCK-0002'], array_column($receive['missing'], 'sku'));

        $this->assertSame(['out_for_delivery', $this->users['driver']], [$this->item(1)['status'], $this->item(1)['holder_user_id']]);
        $this->assertSame(['qc_passed', null], [$this->item(2)['status'], $this->item(2)['holder_user_id']]);   // stays with the giver

        $events = $this->events(1);
        $this->assertSame(['handover_give', 'handover_receive'], array_column($events, 'event_type'));
        $this->assertSame(['qc_passed', 'qc_passed', $handover], [$events[0]['from_status'], $events[0]['to_status'], $events[0]['handover_id']]);
        $this->assertSame(['qc_passed', 'out_for_delivery'], [$events[1]['from_status'], $events[1]['to_status']]);
    }

    public function test_the_giver_cannot_hand_over_a_mat_in_the_wrong_status(): void
    {
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');   // mat 1 is ready, not qc_passed
        $this->loginAs('inventory');
        $out = $this->ok($this->api('POST', "/handovers/$handover/scan",
            ['source' => 'simulator', 'reads' => [['epc' => $this->epc(1)], ['epc' => 'AAAA0000']]]));
        $this->assertSame(['rejected', 'unknown'], array_column($out['results'], 'result'));
        $this->assertSame('MOCK-0001 is ready, not qc_passed.', $out['results'][0]['reason']);
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM handover_items'));
    }

    public function test_a_mat_only_the_receiver_scanned_is_not_on_the_list_and_stays_put(): void
    {
        $this->setItems([1], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1);

        $out = $this->scanHandover('driver', $handover, 1, 2);
        $this->assertSame(['found', 'not_on_list'], array_column($out['results'], 'result'));
        $this->assertSame(['ready', null], [$this->item(2)['status'], $this->item(2)['holder_user_id']]);

        $row = row('SELECT given_at, received_at FROM handover_items WHERE handover_id = ? AND item_id = ?',
            [$handover, $this->item(2)['id']]);
        $this->assertNull($row['given_at']);
        $this->assertNotNull($row['received_at']);
    }

    public function test_the_order_of_the_two_scans_does_not_matter(): void
    {
        $this->setItems([1], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->assertSame('not_on_list', $this->scanHandover('driver', $handover, 1)['results'][0]['result']);
        $this->assertSame('qc_passed', $this->item(1)['status']);

        $give = $this->scanHandover('inventory', $handover, 1);
        $this->assertSame(['found', 'out_for_delivery'], [$give['results'][0]['result'], $give['results'][0]['item']['status']]);
        $this->assertSame($this->users['driver'], $this->item(1)['holder_user_id']);
    }

    public function test_handover_b_puts_the_mat_in_washing_without_counting_a_wash(): void
    {
        $this->setItems([1], 'collected', $this->users['driver']);
        DB::statement("UPDATE items SET current_wash_date = '2026-09-01', previous_wash_date = '2026-08-01', wash_count = 3 WHERE sku = 'MOCK-0001'");
        $handover = $this->openHandover('driver', 'driver_to_washing', 'washing');

        $this->scanHandover('driver', $handover, 1);
        $this->scanHandover('washing', $handover, 1);
        $this->scanHandover('washing', $handover, 1);
        $this->scanHandover('driver', $handover, 1);

        $mat = $this->item(1);
        $this->assertSame(['in_washing', null, 3, '2026-09-01', '2026-08-01'],
            [$mat['status'], $mat['holder_user_id'], $mat['wash_count'], $mat['current_wash_date'], $mat['previous_wash_date']]);
        $this->assertCount(2, $this->events(1));
    }

    public function test_the_scan_after_washing_makes_the_mat_ready_and_counts_one_wash(): void
    {
        $this->setItems([1, 2], 'in_washing');
        DB::statement("UPDATE items SET current_wash_date = '2026-09-01', previous_wash_date = '2026-08-01', wash_count = 3 WHERE sku = 'MOCK-0001'");
        $handover = $this->openHandover('washing', 'washing_to_inventory', 'inventory');

        $give = $this->scanHandover('washing', $handover, 1, 2);
        $seen = $give['results'][0]['item'];
        $this->assertSame(['ready', 4, today_nl(), '2026-09-01'],
            [$seen['status'], $seen['wash_count'], $seen['current_wash_date'], $seen['previous_wash_date']]);
        $this->scanHandover('washing', $handover, 1, 2);   // the same pile scanned again: no second wash

        $mat = $this->item(1);
        $this->assertSame(['ready', 4, today_nl(), '2026-09-01'],
            [$mat['status'], $mat['wash_count'], $mat['current_wash_date'], $mat['previous_wash_date']]);
        $this->assertSame([1, today_nl(), null],
            [$this->item(2)['wash_count'], $this->item(2)['current_wash_date'], $this->item(2)['previous_wash_date']]);

        // The inventory scan is optional and changes nothing.
        $receive = $this->scanHandover('inventory', $handover, 1);
        $this->assertSame('found', $receive['results'][0]['result']);
        $this->assertSame(['MOCK-0002'], array_column($receive['missing'], 'sku'));
        $this->assertSame(['ready', 4], [$this->item(1)['status'], $this->item(1)['wash_count']]);
        $this->assertSame([['in_washing', 'ready'], ['ready', 'ready']],
            array_map(fn (array $e) => [$e['from_status'], $e['to_status']], $this->events(1)));
    }

    public function test_refused_mats_return_to_inventory_without_a_wash(): void
    {
        $this->setItems([1], 'refused', $this->users['driver']);
        $handover = $this->openHandover('driver', 'driver_to_inventory', 'inventory');
        $this->scanHandover('driver', $handover, 1);
        $this->scanHandover('inventory', $handover, 1);

        $mat = $this->item(1);
        $this->assertSame(['registered', null, 0, null], [$mat['status'], $mat['holder_user_id'], $mat['wash_count'], $mat['current_wash_date']]);
    }

    public function test_a_rewash_goes_to_washing_and_is_counted_when_it_comes_back(): void
    {
        $this->setItems([1], 'rewash');
        $rewash = $this->openHandover('inventory', 'inventory_to_washing', 'washing');
        $this->scanHandover('inventory', $rewash, 1);
        $this->scanHandover('washing', $rewash, 1);
        $this->assertSame(['in_washing', 0, null],
            [$this->item(1)['status'], $this->item(1)['wash_count'], $this->item(1)['current_wash_date']]);

        // Both scans are in, so the mat is free for the next handover even though nobody tapped Done yet.
        $back = $this->openHandover('washing', 'washing_to_inventory', 'inventory');
        $this->scanHandover('washing', $back, 1);
        $this->assertSame(['ready', 1, today_nl()],
            [$this->item(1)['status'], $this->item(1)['wash_count'], $this->item(1)['current_wash_date']]);
    }

    public function test_a_driver_can_only_give_mats_from_his_own_van(): void
    {
        $this->setItems([1], 'collected', $this->users['driver2']);
        $handover = $this->openHandover('driver', 'driver_to_washing', 'washing');
        $out = $this->scanHandover('driver', $handover, 1);
        $this->assertSame(['rejected', 'MOCK-0001 is not on your van.'], [$out['results'][0]['result'], $out['results'][0]['reason']]);
    }

    public function test_a_mat_can_be_in_only_one_open_handover(): void
    {
        $this->setItems([1], 'qc_passed');
        $first = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $first, 1);

        $second = $this->openHandover('inventory', 'inventory_to_driver', 'driver2');
        $out = $this->scanHandover('inventory', $second, 1);
        $this->assertSame(['rejected', 'MOCK-0001 is already in another open handover.'],
            [$out['results'][0]['result'], $out['results'][0]['reason']]);
    }

    public function test_only_the_two_people_of_a_handover_may_scan_into_it(): void
    {
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        foreach (['driver2', 'washing', 'outbound', 'office'] as $who) {
            $this->loginAs($who);
            $this->assertError(403, 'forbidden', $this->api('POST', "/handovers/$handover/scan", $this->reads(1)));
        }
        $this->loginAs('driver');
        $this->assertError(404, 'not_found', $this->api('POST', '/handovers/999/scan', $this->reads(1)));
    }

    public function test_an_office_correction_during_a_handover_is_not_undone_by_the_receiver_scan(): void
    {
        $this->setItems([1], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1);

        $this->loginAs('office');
        $this->ok($this->api('POST', "/items/{$this->item(1)['id']}/correct", ['status' => 'repair', 'note' => 'Torn edge']));

        $out = $this->scanHandover('driver', $handover, 1);
        $this->assertSame(['found', 'repair'], [$out['results'][0]['result'], $out['results'][0]['item']['status']]);
        $this->assertSame(['repair', null], [$this->item(1)['status'], $this->item(1)['holder_user_id']]);
    }
}
