<?php
/** The testing gate: one mat round the whole loop through the API, and the lifecycle rules as a table. */
declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HandoverController;
use App\Support\Scan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\ApiTestCase;

final class LifecycleTest extends ApiTestCase
{
    private function qcPass(int ...$mats): void
    {
        $this->loginAs('inventory');
        foreach ($mats as $mat) {
            $this->ok($this->api('POST', '/items/qc', ['source' => 'simulator', 'epc' => $this->epc($mat), 'result' => 'pass']));
        }
    }

    private function handoverA(int ...$mats): void
    {
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, ...$mats);
        $this->scanHandover('driver', $handover, ...$mats);
        $this->ok($this->api('POST', "/handovers/$handover/close"));
    }

    private function closeStop(int $task, array $drops, array $pickups): array
    {
        $this->loginAs('driver');
        return $this->ok($this->api('POST', "/tasks/$task/complete",
            ['source' => 'simulator', 'drops' => $drops, 'pickups' => $pickups, 'refused' => [],
                'signed_name' => 'Mock Contact One', 'issue_note' => null],
            ['photo' => $this->proofFile('jpeg'), 'signature' => $this->proofFile('png')]));
    }

    private function remainingForDriver(): int
    {
        $this->loginAs('inventory');
        return $this->ok($this->api('GET', '/tasks'))['drivers'][0]['needs'][0]['remaining'];
    }

    public function test_a_mat_goes_round_the_whole_loop(): void
    {
        $driver = $this->users['driver'];

        // 1. The office starts contract 1 and gives the delivery to the driver.
        [$delivery] = $this->activateContract(1, 'driver');

        // 2. Inventory checks three mats. The scanner says the driver still needs three.
        $this->qcPass(1, 2, 3);
        $this->assertSame(3, $this->remainingForDriver());

        // 3. Handover A: inventory scans out, the driver scans in.
        $this->handoverA(1, 2, 3);
        $this->assertSame(['out_for_delivery', $driver], [$this->item(1)['status'], $this->item(1)['holder_user_id']]);
        $this->assertSame(0, $this->remainingForDriver());

        // 4. The driver delivers: scan, photo, signature. Today is D.
        $delivered = $this->closeStop($delivery,
            [['zone_id' => 1, 'epcs' => $this->epcs(1, 2)], ['zone_id' => 2, 'epcs' => $this->epcs(3)]], []);
        $swap = $delivered['next_task_id'];
        $this->assertSame(days_after(today_nl(), 14), DB::scalar('SELECT due_date FROM tasks WHERE id = ?', [$swap]));
        $this->assertSame('at_customer', $this->item(1)['status']);

        // 5. D + n: the office assigns the swap. Three clean mats go out, the three used ones come back.
        $this->loginAs('office');
        $this->ok($this->api('PATCH', "/tasks/$swap", ['assigned_to' => $driver]));
        $this->qcPass(4, 5, 6);
        $this->handoverA(4, 5, 6);
        $this->closeStop($swap,
            [['zone_id' => 1, 'epcs' => $this->epcs(4, 5)], ['zone_id' => 2, 'epcs' => $this->epcs(6)]], $this->epcs(1, 2, 3));
        $this->assertSame(['collected', $driver], [$this->item(1)['status'], $this->item(1)['holder_user_id']]);
        $this->assertSame('at_customer', $this->item(4)['status']);

        // 6. Handover B: the driver scans out, washing scans in. The mat is in washing. No wash is counted yet.
        $b = $this->openHandover('driver', 'driver_to_washing', 'washing');
        $this->scanHandover('driver', $b, 1, 2, 3);
        $this->scanHandover('washing', $b, 1, 2, 3);
        $this->ok($this->api('POST', "/handovers/$b/close"));
        $this->assertSame(['in_washing', null, 0, null],
            [$this->item(1)['status'], $this->item(1)['holder_user_id'], $this->item(1)['wash_count'], $this->item(1)['current_wash_date']]);

        // 7. After washing: the washing house manager scans the mats out, twice by mistake.
        //    That scan confirms they are ready to use and counts one wash. No inventory scan is needed.
        $c = $this->openHandover('washing', 'washing_to_inventory', 'inventory');
        $this->scanHandover('washing', $c, 1, 2, 3);
        $this->scanHandover('washing', $c, 1, 2, 3);
        $this->ok($this->api('POST', "/handovers/$c/close"));
        $this->assertSame(['ready', 1, today_nl(), null],
            [$this->item(1)['status'], $this->item(1)['wash_count'], $this->item(1)['current_wash_date'], $this->item(1)['previous_wash_date']]);

        // 8. A washed mat must pass the quality check again before it can go out.
        $again = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->assertSame('rejected', $this->scanHandover('inventory', $again, 1)['results'][0]['result']);

        // 9. The office sees every step in the mat history, and no handover issue.
        $this->loginAs('office');
        $history = $this->ok($this->api('GET', "/items/{$this->item(1)['id']}"))['events'];
        $this->assertSame(['qc', 'handover_give', 'handover_receive', 'deliver', 'collect', 'handover_give', 'handover_receive', 'handover_give'],
            array_column($history, 'event_type'));
        $this->assertSame(['ready', 'qc_passed', 'qc_passed', 'out_for_delivery', 'at_customer', 'collected', 'collected', 'in_washing'],
            array_column($history, 'from_status'));
        $this->assertSame(['qc_passed', 'qc_passed', 'out_for_delivery', 'at_customer', 'collected', 'collected', 'in_washing', 'ready'],
            array_column($history, 'to_status'));
        $this->assertSame([], $this->ok($this->api('GET', '/handovers?issues=1'))['issues']);
    }

    public function test_no_handover_takes_a_mat_in_a_status_it_is_not_meant_for(): void
    {
        $people = [
            'inventory_to_driver' => ['inventory', 'driver'],
            'driver_to_washing' => ['driver', 'washing'],
            'washing_to_inventory' => ['washing', 'inventory'],
            'driver_to_inventory' => ['driver', 'inventory'],
            'inventory_to_washing' => ['inventory', 'washing'],
        ];
        foreach ($people as $type => [$giver, $receiver]) {
            $handover = $this->openHandover($giver, $type, $receiver);
            foreach (Scan::STATUSES as $status) {
                if ($status === HandoverController::TYPES[$type]['from']) {
                    continue;
                }
                $this->setItems([1], $status,
                    in_array($status, Scan::HELD, true) ? $this->users['driver'] : null,
                    $status === 'at_customer' ? 1 : null);
                $out = $this->scanHandover($giver, $handover, 1);
                $this->assertSame('rejected', $out['results'][0]['result'], "$type must not take a mat that is $status");
                $this->assertSame($status, $this->item(1)['status']);
            }
        }
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM handover_items'));
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM item_events'));
    }

    public function test_the_database_itself_refuses_a_closed_stop_without_proof(): void
    {
        [$task] = $this->activateContract(1);
        $this->expectException(PDOException::class);
        DB::statement("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP() WHERE id = ?", [$task]);
    }
}
