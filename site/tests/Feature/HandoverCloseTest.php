<?php
/** Closing a handover, the handover lists per role, the office's issue list, and the list of possible receivers. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class HandoverCloseTest extends ApiTestCase
{
    /** A closed Handover A where mat 2 was only given and mat 3 was only received. Returns the handover id. */
    private function handoverWithIssues(): int
    {
        $this->setItems([1, 2], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1, 2);
        $this->scanHandover('driver', $handover, 1, 3);
        $this->ok($this->api('POST', "/handovers/$handover/close"));
        return $handover;
    }

    private function issues(): array
    {
        $this->loginAs('office');
        return $this->ok($this->api('GET', '/handovers?issues=1'))['issues'];
    }

    public function test_the_receiver_closes_and_a_closed_handover_takes_no_more_scans(): void
    {
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->assertError(403, 'forbidden', $this->api('POST', "/handovers/$handover/close"));   // the giver may not

        $this->loginAs('driver');
        $closed = $this->ok($this->api('POST', "/handovers/$handover/close"))['handover'];
        $this->assertSame('closed', $closed['status']);
        $this->assertNotNull($closed['closed_at']);

        $this->assertError(409, 'closed', $this->api('POST', "/handovers/$handover/close"));
        $this->assertError(409, 'closed', $this->api('POST', "/handovers/$handover/scan", $this->reads(1)));
    }

    public function test_handover_c_is_closed_by_the_giver(): void
    {
        $handover = $this->openHandover('washing', 'washing_to_inventory', 'inventory');
        $this->assertSame('closed', $this->ok($this->api('POST', "/handovers/$handover/close"))['handover']['status']);
    }

    public function test_the_office_can_close_any_handover_and_strangers_cannot(): void
    {
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->loginAs('driver2');
        $this->assertError(403, 'forbidden', $this->api('POST', "/handovers/$handover/close"));
        $this->loginAs('office');
        $this->assertSame('closed', $this->ok($this->api('POST', "/handovers/$handover/close"))['handover']['status']);
        $this->assertError(404, 'not_found', $this->api('POST', '/handovers/999/close'));
    }

    public function test_a_closed_handover_frees_its_mats_for_a_new_one(): void
    {
        $this->handoverWithIssues();   // mat 2 was given but never received, so it is still qc_passed
        $second = $this->openHandover('inventory', 'inventory_to_driver', 'driver2');
        $this->assertSame('found', $this->scanHandover('inventory', $second, 2)['results'][0]['result']);
    }

    public function test_one_sided_mats_become_issues_when_the_handover_closes(): void
    {
        $this->setItems([1, 2], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1, 2);
        $this->scanHandover('driver', $handover, 1, 3);
        $this->assertSame([], $this->issues());   // still open: not an issue yet

        $this->loginAs('driver');
        $this->ok($this->api('POST', "/handovers/$handover/close"));
        $issues = $this->issues();
        $this->assertSame(['MOCK-0002', 'MOCK-0003'], array_column($issues, 'sku'));
        $this->assertSame([$handover, 'inventory_to_driver', 'Inventory', 'Driver', 'qc_passed'],
            [$issues[0]['handover_id'], $issues[0]['type'], $issues[0]['giver_name'], $issues[0]['receiver_name'], $issues[0]['status']]);
        $this->assertNull($issues[0]['received_at']);   // given, never received
        $this->assertNull($issues[1]['given_at']);      // received, never given
    }

    public function test_handover_c_without_the_inventory_scan_is_not_an_issue(): void
    {
        $this->setItems([4], 'in_washing');
        $handover = $this->openHandover('washing', 'washing_to_inventory', 'inventory');
        $this->scanHandover('washing', $handover, 4);
        $this->ok($this->api('POST', "/handovers/$handover/close"));
        $this->assertSame([], $this->issues());
    }

    public function test_the_office_resolves_an_issue_with_a_note(): void
    {
        $handover = $this->handoverWithIssues();
        $given = $this->item(2)['id'];
        $moved = $this->item(1)['id'];

        $this->assertError(403, 'forbidden', $this->api('POST', "/handovers/$handover/items/$given/resolve", ['note' => 'x']));
        $this->loginAs('office');
        $this->assertError(422, 'invalid_input', $this->api('POST', "/handovers/$handover/items/$given/resolve", []));
        $this->assertError(409, 'not_an_issue', $this->api('POST', "/handovers/$handover/items/$moved/resolve", ['note' => 'x']));

        $this->ok($this->api('POST', "/handovers/$handover/items/$given/resolve", ['note' => 'Was left on the shelf']));
        $this->assertSame(['MOCK-0003'], array_column($this->issues(), 'sku'));
        $row = row('SELECT resolved_by, resolution_note, resolved_at FROM handover_items WHERE handover_id = ? AND item_id = ?',
            [$handover, $given]);
        $this->assertSame([$this->users['office'], 'Was left on the shelf'], [$row['resolved_by'], $row['resolution_note']]);
        $this->assertNotNull($row['resolved_at']);

        $this->assertError(409, 'not_an_issue', $this->api('POST', "/handovers/$handover/items/$given/resolve", ['note' => 'again']));
    }

    public function test_everyone_sees_his_own_handovers_and_the_office_sees_all(): void
    {
        $this->setItems([1], 'qc_passed');
        $first = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $first, 1);
        $second = $this->openHandover('outbound', 'inventory_to_driver', 'driver2');

        $this->loginAs('driver');
        $mine = $this->ok($this->api('GET', '/handovers'))['handovers'];
        $this->assertSame([$first], array_column($mine, 'id'));
        $this->assertSame(['Inventory', 'Driver', 1, 0], [$mine[0]['giver_name'], $mine[0]['receiver_name'], $mine[0]['given'], $mine[0]['received']]);

        $this->loginAs('washing');
        $this->assertSame([], $this->ok($this->api('GET', '/handovers'))['handovers']);
        $this->loginAs('office');
        $this->assertSame([$second, $first], array_column($this->ok($this->api('GET', '/handovers'))['handovers'], 'id'));
        $this->loginAs('driver');
        $this->assertArrayNotHasKey('issues', $this->ok($this->api('GET', '/handovers?issues=1')));   // only the office gets issues
    }

    public function test_a_handover_shows_its_pile_to_its_two_people_and_the_office(): void
    {
        $this->setItems([1], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1);

        foreach (['inventory', 'driver', 'office'] as $who) {
            $this->loginAs($who);
            $view = $this->ok($this->api('GET', "/handovers/$handover"))['handover'];
            $this->assertSame('MOCK-0001', $view['items'][0]['sku']);
            $this->assertNotNull($view['items'][0]['given_at']);
            $this->assertNull($view['items'][0]['received_at']);
        }
        $this->loginAs('driver2');
        $this->assertError(403, 'forbidden', $this->api('GET', "/handovers/$handover"));
        $this->assertError(404, 'not_found', $this->api('GET', '/handovers/999'));
    }

    public function test_a_giver_gets_the_list_of_people_he_can_hand_over_to(): void
    {
        $this->loginAs('inventory');
        $this->assertSame(['Driver', 'Driver2'],
            array_column($this->ok($this->api('GET', '/handover-receivers?type=inventory_to_driver'))['rows'], 'name'));
        $this->assertError(403, 'forbidden', $this->api('GET', '/handover-receivers?type=driver_to_washing'));
        $this->assertError(422, 'invalid_input', $this->api('GET', '/handover-receivers'));

        DB::statement('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver2']]);
        $rows = $this->ok($this->api('GET', '/handover-receivers?type=inventory_to_driver'))['rows'];
        $this->assertSame([['id' => $this->users['driver'], 'name' => 'Driver']], $rows);
    }
}
