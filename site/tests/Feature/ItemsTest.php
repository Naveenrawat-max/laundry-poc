<?php
/** Releasing mats, the quality check, office corrections, the mat list per role and the mat history. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class ItemsTest extends ApiTestCase
{
    private function qc(int $mat, string $result, ?string $note = null): array
    {
        return $this->api('POST', '/items/qc',
            ['source' => 'simulator', 'epc' => $this->epc($mat), 'result' => $result, 'note' => $note]);
    }

    private function correct(int $mat, array $body): array
    {
        return $this->api('POST', "/items/{$this->item($mat)['id']}/correct", $body);
    }

    public function test_ready_releases_registered_and_repaired_mats(): void
    {
        $this->setItems([1], 'registered');
        $this->setItems([2], 'repair');
        $this->loginAs('office');
        $out = $this->ok($this->api('POST', '/items/ready', $this->reads(1, 2)));

        $this->assertSame(['found', 'found'], array_column($out['results'], 'result'));
        $this->assertSame('ready', $out['results'][0]['item']['status']);
        $this->assertSame(['ready', 'ready'], [$this->item(1)['status'], $this->item(2)['status']]);
        $event = $this->events(1)[0];
        $this->assertSame(['ready', 'registered', 'ready'], [$event['event_type'], $event['from_status'], $event['to_status']]);
    }

    public function test_ready_scanned_twice_changes_and_logs_once(): void
    {
        $this->setItems([1], 'registered');
        $this->loginAs('office');
        $this->ok($this->api('POST', '/items/ready', $this->reads(1)));
        $again = $this->ok($this->api('POST', '/items/ready', $this->reads(1)));
        $this->assertSame('found', $again['results'][0]['result']);
        $this->assertCount(1, $this->events(1));
    }

    public function test_ready_rejects_a_mat_in_another_status_and_an_unknown_tag(): void
    {
        $this->setItems([1], 'qc_passed');
        $this->loginAs('office');
        $out = $this->ok($this->api('POST', '/items/ready',
            ['source' => 'simulator', 'reads' => [['epc' => $this->epc(1)], ['epc' => 'AAAA0000']]]));
        $this->assertSame(['rejected', 'unknown'], array_column($out['results'], 'result'));
        $this->assertSame('MOCK-0001 is qc_passed, not registered or repair.', $out['results'][0]['reason']);
        $this->assertSame('qc_passed', $this->item(1)['status']);
        $this->assertCount(0, $this->events(1));
    }

    public function test_only_office_releases_registered_mats(): void
    {
        $this->setItems([1], 'registered');
        foreach (['inventory', 'driver', 'washing', 'outbound'] as $who) {
            $this->loginAs($who);
            $this->assertError(403, 'forbidden', $this->api('POST', '/items/ready', $this->reads(1)));
        }
        $this->assertSame('registered', $this->item(1)['status']);
        $this->assertCount(0, $this->events(1));
    }

    public function test_quality_check_sends_the_mat_to_one_of_four_statuses(): void
    {
        $this->loginAs('inventory');
        foreach ([1 => ['pass', 'qc_passed'], 2 => ['rewash', 'rewash'], 3 => ['repair', 'repair'], 4 => ['scrap', 'scrapped']] as $mat => [$result, $status]) {
            $out = $this->ok($this->qc($mat, $result, "note for $result"));
            $this->assertSame(['found', $status], [$out['results'][0]['result'], $out['results'][0]['item']['status']]);
            $this->assertSame($status, $this->item($mat)['status']);
        }
        $event = $this->events(4)[0];
        $this->assertSame(['qc', 'ready', 'scrapped', 'note for scrap'],
            [$event['event_type'], $event['from_status'], $event['to_status'], $event['note']]);
    }

    public function test_quality_check_needs_a_ready_mat_and_counts_once(): void
    {
        $this->loginAs('inventory');
        $this->ok($this->qc(1, 'pass'));
        $this->assertSame('found', $this->ok($this->qc(1, 'pass'))['results'][0]['result']);   // sent twice
        $this->assertCount(1, $this->events(1));

        $out = $this->ok($this->qc(1, 'scrap'));
        $this->assertSame(['rejected', 'MOCK-0001 is qc_passed, not ready.'], [$out['results'][0]['result'], $out['results'][0]['reason']]);
        $this->assertSame('qc_passed', $this->item(1)['status']);

        $this->assertSame('unknown', $this->ok($this->api('POST', '/items/qc',
            ['source' => 'simulator', 'epc' => 'AAAA0000', 'result' => 'pass']))['results'][0]['result']);
        $this->assertError(422, 'invalid_input', $this->qc(2, 'maybe'));
    }

    public function test_a_correction_needs_a_note_and_a_possible_state(): void
    {
        $this->loginAs('office');
        $driver = $this->users['driver'];
        $this->assertError(422, 'invalid_input', $this->correct(1, ['status' => 'in_washing']));
        $this->assertError(422, 'invalid_input', $this->correct(1, ['status' => 'bogus', 'note' => 'x']));
        $this->assertError(422, 'rule_violation', $this->correct(1, ['status' => 'at_customer', 'note' => 'x']));
        $this->assertError(422, 'rule_violation', $this->correct(1, ['status' => 'ready', 'zone_id' => 1, 'note' => 'x']));
        $this->assertError(422, 'rule_violation', $this->correct(1, ['status' => 'collected', 'note' => 'x']));
        $this->assertError(422, 'rule_violation', $this->correct(1, ['status' => 'ready', 'holder_user_id' => $driver, 'note' => 'x']));
        $this->assertError(422, 'invalid_reference', $this->correct(1, ['status' => 'at_customer', 'zone_id' => 999, 'note' => 'x']));
        $this->assertError(422, 'invalid_reference',
            $this->correct(1, ['status' => 'collected', 'holder_user_id' => $this->users['washing'], 'note' => 'x']));
        $this->assertError(404, 'not_found', $this->api('POST', '/items/999/correct', ['status' => 'ready', 'note' => 'x']));
        $this->assertSame('ready', $this->item(1)['status']);
        $this->assertCount(0, $this->events(1));
    }

    public function test_a_correction_moves_the_mat_and_leaves_a_trace(): void
    {
        $this->loginAs('office');
        $item = $this->ok($this->correct(1, ['status' => 'at_customer', 'zone_id' => 1, 'note' => 'Found at the bakery']))['item'];
        $this->assertSame('at_customer', $item['status']);
        $this->assertSame(['at_customer', 1, null], [$this->item(1)['status'], $this->item(1)['zone_id'], $this->item(1)['holder_user_id']]);

        $event = $this->events(1)[0];
        $this->assertSame(['correction', 'ready', 'at_customer', 'Found at the bakery', 'web', 1, $this->users['office']],
            [$event['event_type'], $event['from_status'], $event['to_status'], $event['note'], $event['source'], $event['zone_id'], $event['user_id']]);

        $this->ok($this->correct(1, ['status' => 'collected', 'holder_user_id' => $this->users['driver'], 'note' => 'On the van']));
        $this->assertSame(['collected', null, $this->users['driver']],
            [$this->item(1)['status'], $this->item(1)['zone_id'], $this->item(1)['holder_user_id']]);
    }

    public function test_only_the_office_corrects(): void
    {
        $this->loginAs('inventory');
        $this->assertError(403, 'forbidden', $this->correct(1, ['status' => 'repair', 'note' => 'x']));
    }

    public function test_the_office_lists_searches_and_pages_all_mats(): void
    {
        $this->setItems([5], 'at_customer', null, 1);
        $this->setItems([6], 'collected', $this->users['driver']);
        $this->loginAs('office');

        $all = $this->ok($this->api('GET', '/items'));
        $this->assertSame([40, 1, 50, 40], [$all['total'], $all['page'], $all['per_page'], count($all['items'])]);
        $this->assertSame('MOCK-0001', $all['items'][0]['sku']);
        $this->assertSame($this->epc(1), $all['items'][0]['epc']);

        $this->assertSame(10, $this->ok($this->api('GET', '/items?q=MOCK-001'))['total']);
        $this->assertSame(1, $this->ok($this->api('GET', '/items?q=' . substr($this->epc(7), -6)))['total']);
        $this->assertSame(8, $this->ok($this->api('GET', '/items?status=ready&type=3'))['total']);
        $this->assertSame([], $this->ok($this->api('GET', '/items?page=2'))['items']);
        $this->assertError(422, 'invalid_input', $this->api('GET', '/items?status=bogus'));

        $atCustomer = $this->ok($this->api('GET', '/items?status=at_customer'))['items'][0];
        $this->assertSame(['zone_id' => 1, 'zone' => 'Front door', 'building' => 'Mock Bakery shop', 'customer' => 'Mock Bakery BV'],
            $atCustomer['location']);
        $carried = $this->ok($this->api('GET', '/items?status=collected'))['items'][0];
        $this->assertSame(['id' => $this->users['driver'], 'name' => 'Driver'], $carried['holder']);
    }

    public function test_the_mat_list_filters_on_several_statuses_at_once(): void
    {
        $this->setItems([1, 2], 'qc_passed');
        $this->setItems([3], 'rewash');
        $this->setItems([4], 'in_washing');
        $this->loginAs('office');
        // A place is a group of statuses: "in inventory" is five of them.
        $this->assertSame(3, $this->ok($this->api('GET', '/items?status=qc_passed,rewash'))['total']);
        $this->assertSame(39, $this->ok($this->api('GET', '/items?status=registered,ready,qc_passed,rewash,repair'))['total']);
        $this->assertSame(['MOCK-0003'], array_column($this->ok($this->api('GET', '/items?status=rewash,in_washing&q=MOCK-0003'))['items'], 'sku'));
        $this->assertError(422, 'invalid_input', $this->api('GET', '/items?status=ready,bogus'));
        $this->assertError(422, 'invalid_input', $this->api('GET', '/items?status=ready,'));

        // A list never widens what a role may see: washing still gets only the mats in washing.
        $this->loginAs('washing');
        $this->assertSame(['MOCK-0004'], array_column($this->ok($this->api('GET', '/items?status=ready,in_washing'))['items'], 'sku'));
    }

    public function test_the_office_gets_the_number_of_mats_per_status(): void
    {
        $this->setItems([5, 6], 'at_customer', null, 1);
        $this->setItems([7], 'in_washing');
        $this->loginAs('office');
        // A status no mat has is left out.
        $this->assertEquals(['counts' => ['ready' => 37, 'at_customer' => 2, 'in_washing' => 1]], $this->ok($this->api('GET', '/items/counts')));
        $this->assertSame(37, $this->ok($this->api('GET', '/items/counts'))['counts']['ready']);   // a number, not a string

        $this->loginAs('inventory');
        $this->assertError(403, 'forbidden', $this->api('GET', '/items/counts'));
    }

    public function test_a_search_for_percent_or_underscore_matches_them_literally(): void
    {
        DB::statement("UPDATE items SET sku = '50%OFF' WHERE sku = 'MOCK-0001'");
        DB::statement("UPDATE items SET sku = 'A_B' WHERE sku = 'MOCK-0002'");
        $this->loginAs('office');
        $this->assertSame(['50%OFF'], array_column($this->ok($this->api('GET', '/items?q=%25'))['items'], 'sku'));
        $this->assertSame(['A_B'], array_column($this->ok($this->api('GET', '/items?q=_'))['items'], 'sku'));
    }

    public function test_each_role_sees_only_its_own_mats(): void
    {
        $this->setItems([1], 'out_for_delivery', $this->users['driver']);
        $this->setItems([2], 'collected', $this->users['driver2']);
        $this->setItems([3], 'in_washing');
        $this->setItems([4], 'at_customer', null, 1);

        $this->loginAs('inventory');
        $inventory = $this->ok($this->api('GET', '/items'));
        $this->assertSame(36, $inventory['total']);
        $this->assertArrayNotHasKey('epc', $inventory['items'][0]);
        $this->assertArrayNotHasKey('location', $inventory['items'][0]);

        $this->loginAs('driver');
        $this->assertSame(['MOCK-0001'], array_column($this->ok($this->api('GET', '/items'))['items'], 'sku'));
        $this->loginAs('washing');
        $this->assertSame(['MOCK-0003'], array_column($this->ok($this->api('GET', '/items'))['items'], 'sku'));
        $this->loginAs('outbound');
        $this->assertError(403, 'forbidden', $this->api('GET', '/items'));
    }

    public function test_the_mat_history_is_full_for_the_office_and_trimmed_for_washing(): void
    {
        $id = $this->item(1)['id'];
        $this->loginAs('office');
        $this->ok($this->correct(1, ['status' => 'at_customer', 'zone_id' => 1, 'note' => 'Found at the bakery']));

        $office = $this->ok($this->api('GET', "/items/$id"));
        $this->assertSame($this->epc(1), $office['item']['epc']);
        $this->assertSame(['Found at the bakery', 'Front door', 'Office'],
            [$office['events'][0]['note'], $office['events'][0]['zone_name'], $office['events'][0]['user_name']]);
        $this->assertError(404, 'not_found', $this->api('GET', '/items/999'));

        $this->loginAs('washing');
        $washing = $this->ok($this->api('GET', "/items/$id"));
        $this->assertArrayNotHasKey('epc', $washing['item']);
        $this->assertSame(['id', 'created_at', 'event_type', 'from_status', 'to_status', 'source', 'handover_id', 'user_name'],
            array_keys($washing['events'][0]));

        $this->loginAs('driver');
        $this->assertError(403, 'forbidden', $this->api('GET', "/items/$id"));
    }
}
