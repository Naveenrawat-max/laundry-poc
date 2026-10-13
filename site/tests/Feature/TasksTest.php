<?php
/** Task lists per role, assigning a driver, how many mats each driver still needs, and collection tasks. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class TasksTest extends ApiTestCase
{
    public function test_the_office_sees_and_filters_all_tasks(): void
    {
        [$bakery] = $this->activateContract(1, 'driver');
        $this->activateContract(2);

        $tasks = $this->ok($this->api('GET', '/tasks'))['tasks'];
        $this->assertCount(3, $tasks);
        $row = array_values(array_filter($tasks, fn (array $t) => $t['id'] === $bakery))[0];
        $this->assertSame(['delivery', 'open', 'Mock Bakery BV', 'Mock Bakery shop', 'Mock North', 'Driver', 0],
            [$row['type'], $row['status'], $row['customer_name'], $row['building_name'], $row['area_name'], $row['assigned_name'], $row['has_proof']]);

        $this->assertCount(3, $this->ok($this->api('GET', '/tasks?type=delivery&status=open'))['tasks']);
        $this->assertCount(0, $this->ok($this->api('GET', '/tasks?status=done'))['tasks']);
        $this->assertSame([$bakery], array_column($this->ok($this->api('GET', "/tasks?assigned_to={$this->users['driver']}"))['tasks'], 'id'));
        $this->assertError(422, 'invalid_input', $this->api('GET', '/tasks?status=bogus'));
    }

    public function test_a_task_can_only_be_assigned_to_an_active_driver(): void
    {
        [$task] = $this->activateContract(1);
        $assign = fn (?int $userId) => $this->api('PATCH', "/tasks/$task", ['assigned_to' => $userId]);

        $this->assertSame($this->users['driver'], $this->ok($assign($this->users['driver']))['task']['assigned_to']);
        $this->assertNull($this->ok($assign(null))['task']['assigned_to']);
        $this->assertError(422, 'invalid_reference', $assign($this->users['washing']));
        DB::statement('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver2']]);
        $this->assertError(422, 'invalid_reference', $assign($this->users['driver2']));
    }

    public function test_a_driver_sees_his_open_tasks_with_everything_the_stop_needs(): void
    {
        $this->activateContract(1, 'driver');
        $this->activateContract(2, 'driver2');
        $this->setItems([9], 'at_customer', null, 1);

        $this->loginAs('driver');
        $tasks = $this->ok($this->api('GET', '/tasks'))['tasks'];
        $this->assertCount(1, $tasks);
        $task = $tasks[0];
        $this->assertSame(['delivery', 'Mock Bakery BV', 'Mock Contact One', '000-0000001', 'Mockstraat 1', 'Mockstad'],
            [$task['type'], $task['customer_name'], $task['contact_name'], $task['contact_phone'], $task['street'], $task['city']]);
        $this->assertSame(['Front door', 'Staff entrance'], array_column($task['zones'], 'name'));
        $this->assertSame([['Front door', 'Mock mat 85x150 grey', 2], ['Staff entrance', 'Mock mat 85x150 grey', 1]],
            array_map(fn (array $e) => [$e['zone_name'], $e['type_name'], $e['quantity']], $task['expected']));
        $this->assertSame([['id' => $this->item(9)['id'], 'sku' => 'MOCK-0009', 'epc' => $this->epc(9), 'zone_id' => 1, 'type' => 'Mock mat 85x150 grey']],
            $task['in_building']);
    }

    public function test_a_driver_does_not_see_other_or_closed_tasks(): void
    {
        [$task] = $this->activateContract(1, 'driver');
        $this->loginAs('driver2');
        $this->assertSame([], $this->ok($this->api('GET', '/tasks'))['tasks']);

        $this->loginAs('office');
        $this->ok($this->api('PATCH', "/tasks/$task", ['status' => 'cancelled']));
        $this->loginAs('driver');
        $this->assertSame([], $this->ok($this->api('GET', '/tasks'))['tasks']);
    }

    public function test_inventory_and_outbound_see_how_many_mats_each_driver_still_needs(): void
    {
        $this->activateContract(1, 'driver');
        $this->activateContract(2, 'driver2');

        foreach (['inventory', 'outbound'] as $who) {
            $this->loginAs($who);
            $drivers = $this->ok($this->api('GET', '/tasks'))['drivers'];
            $this->assertSame(['Driver', 'Driver2'], array_column(array_column($drivers, 'driver'), 'name'));
            $this->assertSame([['item_type_id' => 1, 'type' => 'Mock mat 85x150 grey', 'needed' => 3, 'carried' => 0, 'remaining' => 3]],
                $drivers[0]['needs']);
            // Contract 2: type 3 once, type 2 three times over two buildings, type 1 once. Sorted by type name.
            $this->assertSame([3 => 1, 2 => 3, 1 => 1], array_column($drivers[1]['needs'], 'needed', 'item_type_id'));
        }

        $this->setItems([1, 2], 'out_for_delivery', $this->users['driver']);
        $this->assertSame([2, 1], [$this->ok($this->api('GET', '/tasks'))['drivers'][0]['needs'][0]['carried'],
            $this->ok($this->api('GET', '/tasks'))['drivers'][0]['needs'][0]['remaining']]);
        $this->setItems([3, 4], 'out_for_delivery', $this->users['driver']);
        $this->assertSame(0, $this->ok($this->api('GET', '/tasks'))['drivers'][0]['needs'][0]['remaining']);   // never below zero
    }

    public function test_a_task_without_a_driver_needs_no_mats_yet(): void
    {
        $this->activateContract(1);
        $this->loginAs('inventory');
        $this->assertSame([], $this->ok($this->api('GET', '/tasks'))['drivers']);
    }

    public function test_the_office_moves_and_cancels_an_open_task(): void
    {
        [$task] = $this->activateContract(1);
        $this->assertSame('2026-12-01', $this->ok($this->api('PATCH', "/tasks/$task", ['due_date' => '2026-12-01']))['task']['due_date']);
        $this->assertError(422, 'invalid_input', $this->api('PATCH', "/tasks/$task", ['due_date' => 'tomorrow']));
        $this->assertError(422, 'invalid_input', $this->api('PATCH', "/tasks/$task", ['status' => 'done']));
        $this->assertError(404, 'not_found', $this->api('PATCH', '/tasks/999', ['due_date' => '2026-12-01']));

        $this->assertSame('cancelled', $this->ok($this->api('PATCH', "/tasks/$task", ['status' => 'cancelled']))['task']['status']);
        $this->assertError(409, 'not_open', $this->api('PATCH', "/tasks/$task", ['due_date' => '2026-12-02']));
    }

    public function test_the_office_creates_a_collection_task_for_a_building_of_the_contract(): void
    {
        $this->activateContract(1);
        $new = ['contract_id' => 1, 'building_id' => 1, 'due_date' => '2026-12-24'];
        $task = $this->ok($this->api('POST', '/tasks', $new))['task'];
        $this->assertSame(['collection', 'open', '2026-12-24', null], [$task['type'], $task['status'], $task['due_date'], $task['assigned_to']]);

        $this->assertError(422, 'invalid_reference', $this->api('POST', '/tasks', ['building_id' => 2] + $new));   // not in contract 1
        $this->assertError(409, 'not_started', $this->api('POST', '/tasks', ['contract_id' => 2, 'building_id' => 2] + $new));   // still a draft
        $this->assertError(404, 'not_found', $this->api('POST', '/tasks', ['contract_id' => 999] + $new));

        $this->loginAs('driver');
        $this->assertError(403, 'forbidden', $this->api('POST', '/tasks', $new));
        $this->assertError(403, 'forbidden', $this->api('PATCH', "/tasks/{$task['id']}", ['status' => 'cancelled']));
        $this->loginAs('washing');
        $this->assertError(403, 'forbidden', $this->api('GET', '/tasks'));
    }

    public function test_the_office_plans_a_swap_by_hand_when_a_building_has_no_visit_planned(): void
    {
        [$delivery] = $this->activateContract(1);
        $new = ['type' => 'swap', 'contract_id' => 1, 'building_id' => 1, 'due_date' => '2026-12-24'];
        // The delivery is still open: this building has its visit already.
        $this->assertError(409, 'already_planned', $this->api('POST', '/tasks', $new));

        // Cancelling stops the repeat. A swap made by hand starts it again.
        $this->ok($this->api('PATCH', "/tasks/$delivery", ['status' => 'cancelled']));
        $task = $this->ok($this->api('POST', '/tasks', $new))['task'];
        $this->assertSame(['swap', 'open', '2026-12-24', null, null],
            [$task['type'], $task['status'], $task['due_date'], $task['assigned_to'], $task['previous_task_id']]);
        $this->assertError(409, 'already_planned', $this->api('POST', '/tasks', $new));   // one open visit per building

        // A collection is no visit that lays mats, so it may stand next to the swap. Without a type it is a collection, as before.
        $this->assertSame('collection', $this->ok($this->api('POST', '/tasks', ['type' => 'collection'] + $new))['task']['type']);
        $this->assertError(422, 'invalid_input', $this->api('POST', '/tasks', ['type' => 'delivery'] + $new));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/tasks', ['building_id' => 2] + $new));   // not in contract 1
        $this->assertError(409, 'not_started', $this->api('POST', '/tasks', ['contract_id' => 2, 'building_id' => 2] + $new));

        // The driver gets it like any swap, with the mats the contract names.
        $this->ok($this->api('PATCH', "/tasks/{$task['id']}", ['assigned_to' => $this->users['driver']]));
        $this->loginAs('driver');
        $mine = $this->ok($this->api('GET', '/tasks'))['tasks'];
        $this->assertSame([[$task['id'], 'swap', 3]], array_map(fn (array $t) => [$t['id'], $t['type'], array_sum(array_column($t['expected'], 'quantity'))], $mine));
        $this->assertError(403, 'forbidden', $this->api('POST', '/tasks', $new));

        // An ended contract gets no more swaps, only collections.
        $this->loginAs('office');
        $this->ok($this->api('POST', '/contracts/1/end'));
        $this->assertError(409, 'not_active', $this->api('POST', '/tasks', $new));
        $this->assertSame('collection', $this->ok($this->api('POST', '/tasks', ['type' => 'collection'] + $new))['task']['type']);
    }
}
