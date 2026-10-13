<?php
/** Contracts: lines per zone, activation into delivery tasks, changing n, and ending. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class ContractsTest extends ApiTestCase
{
    private function newContract(array $over = []): array
    {
        return $over + ['customer_id' => 1, 'swap_interval_days' => 7, 'start_date' => '2026-11-01',
            'lines' => [['zone_id' => 1, 'item_type_id' => 1, 'quantity' => 2], ['zone_id' => 2, 'item_type_id' => 3, 'quantity' => 1]]];
    }

    private function tasksOf(int $contractId): array
    {
        return rows('SELECT * FROM tasks WHERE contract_id = ? ORDER BY id', [$contractId]);
    }

    public function test_office_makes_a_draft_contract_with_lines(): void
    {
        $this->loginAs('office');
        $contract = $this->ok($this->api('POST', '/contracts', $this->newContract()))['contract'];
        $this->assertSame(['draft', 7, '2026-11-01', null, 'Mock Bakery BV'],
            [$contract['status'], $contract['swap_interval_days'], $contract['start_date'], $contract['end_date'], $contract['customer_name']]);
        $this->assertSame([['Front door', 'Mock mat 85x150 grey', 2], ['Staff entrance', 'Mock logo mat 85x150', 1]],
            array_map(fn (array $l) => [$l['zone_name'], $l['type_name'], $l['quantity']], $contract['lines']));
        $this->assertSame('Mock Bakery shop', $contract['lines'][0]['building_name']);
        $this->assertCount(3, $this->ok($this->api('GET', '/contracts'))['rows']);
    }

    public function test_a_contract_with_broken_input_is_refused(): void
    {
        $this->loginAs('office');
        $line = fn (array $over) => ['lines' => [$over + ['zone_id' => 1, 'item_type_id' => 1, 'quantity' => 1]]];
        // Zone 3 belongs to customer 2.
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/contracts', $this->newContract($line(['zone_id' => 3]))));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/contracts', $this->newContract($line(['item_type_id' => 999]))));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/contracts', $this->newContract(['customer_id' => 999])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $this->newContract($line(['quantity' => 0]))));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $this->newContract(['swap_interval_days' => 0])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $this->newContract(['swap_interval_days' => 366])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $this->newContract(['start_date' => '19-10-2026'])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $this->newContract(['lines' => 'none'])));
        $same = ['zone_id' => 1, 'item_type_id' => 1, 'quantity' => 1];
        $this->assertError(409, 'duplicate', $this->api('POST', '/contracts', $this->newContract(['lines' => [$same, $same]])));
        $this->assertSame(2, (int) DB::scalar('SELECT COUNT(*) FROM contracts'));
    }

    public function test_activating_creates_one_delivery_task_per_building(): void
    {
        $this->loginAs('office');
        $out = $this->ok($this->api('POST', '/contracts/2/activate'));   // contract 2 has lines in buildings 2 and 3
        $this->assertSame('active', $out['contract']['status']);

        $tasks = $this->tasksOf(2);
        $this->assertSame(array_column($tasks, 'id'), $out['task_ids']);
        $this->assertSame([2, 3], array_column($tasks, 'building_id'));
        foreach ($tasks as $task) {
            $this->assertSame(['delivery', 'open', $out['contract']['start_date'], null, null],
                [$task['type'], $task['status'], $task['due_date'], $task['assigned_to'], $task['previous_task_id']]);
        }

        $this->assertError(409, 'not_draft', $this->api('POST', '/contracts/2/activate'));
        $this->assertCount(2, $this->tasksOf(2));
        $this->assertError(404, 'not_found', $this->api('POST', '/contracts/999/activate'));
    }

    public function test_a_contract_without_lines_cannot_start(): void
    {
        $this->loginAs('office');
        $id = $this->ok($this->api('POST', '/contracts', $this->newContract(['lines' => []])))['contract']['id'];
        $this->assertError(422, 'no_lines', $this->api('POST', "/contracts/$id/activate"));
        $this->assertSame('draft', DB::scalar('SELECT status FROM contracts WHERE id = ?', [$id]));
    }

    public function test_lines_and_start_date_change_only_while_draft(): void
    {
        $this->loginAs('office');
        $contract = $this->ok($this->api('PATCH', '/contracts/1', ['start_date' => '2026-12-01', 'swap_interval_days' => 21,
            'lines' => [['zone_id' => 2, 'item_type_id' => 1, 'quantity' => 4]]]))['contract'];
        $this->assertSame(['2026-12-01', 21], [$contract['start_date'], $contract['swap_interval_days']]);
        $this->assertSame([['Staff entrance', 4]], array_map(fn (array $l) => [$l['zone_name'], $l['quantity']], $contract['lines']));

        $this->ok($this->api('POST', '/contracts/1/activate'));
        $this->assertError(409, 'not_draft', $this->api('PATCH', '/contracts/1', ['lines' => []]));
        $this->assertError(409, 'not_draft', $this->api('PATCH', '/contracts/1', ['start_date' => '2027-01-01']));
        $this->assertError(404, 'not_found', $this->api('PATCH', '/contracts/999', ['swap_interval_days' => 5]));
    }

    public function test_changing_n_on_an_active_contract_moves_its_open_swap_tasks(): void
    {
        $this->loginAs('office');
        $this->ok($this->api('POST', '/contracts/1/activate'));
        DB::statement("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('swap', 1, 1, '2026-11-02')");
        DB::statement("INSERT INTO tasks (type, contract_id, building_id, due_date, status, completed_at, photo_path, signature_path)
           VALUES ('swap', 1, 1, '2026-10-20', 'done', UTC_TIMESTAMP(), 'p.jpg', 's.png')");
        $before = $this->tasksOf(1);

        $contract = $this->ok($this->api('PATCH', '/contracts/1', ['swap_interval_days' => 21]))['contract'];   // 14 -> 21
        $this->assertSame(21, $contract['swap_interval_days']);
        $after = $this->tasksOf(1);
        $this->assertSame($before[0]['due_date'], $after[0]['due_date']);   // the delivery task does not move
        $this->assertSame('2026-11-09', $after[1]['due_date']);             // the open swap moves 7 days
        $this->assertSame('2026-10-20', $after[2]['due_date']);             // a closed swap does not move
    }

    public function test_ending_a_contract_cancels_its_open_swap_tasks(): void
    {
        $this->loginAs('office');
        $this->assertError(409, 'not_active', $this->api('POST', '/contracts/1/end'));   // still a draft
        $this->ok($this->api('POST', '/contracts/1/activate'));
        DB::statement("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('swap', 1, 1, '2026-11-02')");

        $contract = $this->ok($this->api('POST', '/contracts/1/end'))['contract'];
        $this->assertSame(['ended', max(today_nl(), $contract['start_date'])], [$contract['status'], $contract['end_date']]);
        $this->assertSame(['open', 'cancelled'], array_column($this->tasksOf(1), 'status'));   // delivery stays, swap is cancelled

        $this->assertError(409, 'not_active', $this->api('POST', '/contracts/1/end'));
        $this->assertError(409, 'ended', $this->api('PATCH', '/contracts/1', ['swap_interval_days' => 10]));
    }

    public function test_a_contract_can_be_ended_before_its_start_date(): void
    {
        DB::statement("UPDATE contracts SET start_date = '2099-01-01' WHERE id = 1");
        $this->loginAs('office');
        $this->ok($this->api('POST', '/contracts/1/activate'));
        $contract = $this->ok($this->api('POST', '/contracts/1/end'))['contract'];
        $this->assertSame(['ended', '2099-01-01'], [$contract['status'], $contract['end_date']]);
    }

    public function test_only_the_office_sees_contracts(): void
    {
        foreach (['inventory', 'driver', 'washing', 'outbound'] as $who) {
            $this->loginAs($who);
            $this->assertError(403, 'forbidden', $this->api('GET', '/contracts'));
        }
    }
}
