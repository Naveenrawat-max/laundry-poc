<?php
/** Closing a stop: scan, photo and signature; hard refusals; the note for a mismatch; the next swap task; the proof files. */
declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Scan;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

final class TaskCompleteTest extends ApiTestCase
{
    private function stop(array $over = []): array
    {
        return $over + ['source' => 'simulator', 'drops' => [], 'pickups' => [], 'refused' => [],
            'signed_name' => 'Mock Contact One', 'issue_note' => null];
    }

    /** Contract 1 wants two mats at the front door (zone 1) and one at the staff entrance (zone 2). */
    private function fullDrop(int $a, int $b, int $c): array
    {
        return [['zone_id' => 1, 'epcs' => $this->epcs($a, $b)], ['zone_id' => 2, 'epcs' => $this->epcs($c)]];
    }

    private function complete(int $task, array $stop, ?array $files = null): array
    {
        return $this->api('POST', "/tasks/$task/complete", $stop,
            $files ?? ['photo' => $this->proofFile('jpeg'), 'signature' => $this->proofFile('png')]);
    }

    /** Contract 1 is active, its delivery task belongs to the driver, mats 1-3 are on his van. Logged in as the driver. */
    private function deliveryReady(): int
    {
        [$task] = $this->activateContract(1, 'driver');
        $this->setItems([1, 2, 3], 'out_for_delivery', $this->users['driver']);
        $this->loginAs('driver');
        return $task;
    }

    /** Mats 1-3 lie at the customer, the swap task belongs to the driver, mats 4-6 are on his van. Logged in as the driver. */
    private function swapReady(): int
    {
        $delivery = $this->deliveryReady();
        $swap = $this->ok($this->complete($delivery, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])))['next_task_id'];
        $this->loginAs('office');
        $this->ok($this->api('PATCH', "/tasks/$swap", ['assigned_to' => $this->users['driver']]));
        $this->setItems([4, 5, 6], 'out_for_delivery', $this->users['driver']);
        $this->loginAs('driver');
        return $swap;
    }

    private function taskRow(int $id): array
    {
        return row('SELECT * FROM tasks WHERE id = ?', [$id]);
    }

    private function storedProofs(): array
    {
        return Storage::disk('local')->files('proofs');
    }

    public function test_closing_a_delivery_puts_the_mats_in_their_zones_and_plans_the_swap(): void
    {
        $task = $this->deliveryReady();
        $out = $this->ok($this->complete($task, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));

        $this->assertSame(['done', 'Mock Contact One', 1], [$out['task']['status'], $out['task']['signed_name'], $out['task']['has_proof']]);
        $this->assertSame(['at_customer', 1, null], [$this->item(1)['status'], $this->item(1)['zone_id'], $this->item(1)['holder_user_id']]);
        $this->assertSame(2, $this->item(3)['zone_id']);

        $row = $this->taskRow($task);
        $this->assertNotNull($row['completed_at']);
        $this->assertMatchesRegularExpression('/^task-' . $task . '-[0-9a-f]{16}\.jpg$/', $row['photo_path']);
        $this->assertMatchesRegularExpression('/^task-' . $task . '-[0-9a-f]{16}\.png$/', $row['signature_path']);
        Storage::disk('local')->assertExists('proofs/' . $row['photo_path']);
        Storage::disk('local')->assertExists('proofs/' . $row['signature_path']);

        $next = $this->taskRow($out['next_task_id']);
        $this->assertSame(['swap', 'open', 1, 1, $task, days_after(today_nl(), 14), null],
            [$next['type'], $next['status'], $next['contract_id'], $next['building_id'], $next['previous_task_id'], $next['due_date'], $next['assigned_to']]);

        $event = $this->events(1)[0];
        $this->assertSame(['deliver', 'out_for_delivery', 'at_customer', $task, 1, 'simulator'],
            [$event['event_type'], $event['from_status'], $event['to_status'], $event['task_id'], $event['zone_id'], $event['source']]);
    }

    public function test_a_stop_needs_a_photo_and_a_signature_of_the_right_kind(): void
    {
        $task = $this->deliveryReady();
        $stop = $this->stop(['drops' => $this->fullDrop(1, 2, 3)]);
        $jpeg = $this->proofFile('jpeg');
        $png = $this->proofFile('png');
        $text = UploadedFile::fake()->createWithContent('proof.jpg', 'this is not an image');

        $this->assertError(422, 'proof_required', $this->complete($task, $stop, ['signature' => $png]));
        $this->assertError(422, 'proof_required', $this->complete($task, $stop, ['photo' => $jpeg]));
        $this->assertError(422, 'wrong_file_type', $this->complete($task, $stop, ['photo' => $png, 'signature' => $png]));
        $this->assertError(422, 'wrong_file_type', $this->complete($task, $stop, ['photo' => $jpeg, 'signature' => $jpeg]));
        $this->assertError(422, 'wrong_file_type', $this->complete($task, $stop, ['photo' => $text, 'signature' => $png]));

        $this->assertSame('open', $this->taskRow($task)['status']);
        $this->assertSame('out_for_delivery', $this->item(1)['status']);
        $this->assertSame([], $this->storedProofs());
    }

    public function test_a_phone_sized_photo_is_accepted_and_an_oversized_one_is_refused(): void
    {
        $task = $this->deliveryReady();
        $stop = $this->stop(['drops' => $this->fullDrop(1, 2, 3)]);
        $png = $this->proofFile('png');

        $tooBig = $this->proofFile('jpeg', 5 * 1024 * 1024);
        $this->assertError(422, 'file_too_big', $this->complete($task, $stop, ['photo' => $tooBig, 'signature' => $png]));
        $bigSignature = $this->proofFile('png', 1024 * 1024);
        $this->assertError(422, 'file_too_big', $this->complete($task, $stop, ['photo' => $this->proofFile('jpeg'), 'signature' => $bigSignature]));

        $phonePhoto = $this->proofFile('jpeg', 3 * 1024 * 1024);   // above PHP's 2 MB default
        $this->ok($this->complete($task, $stop, ['photo' => $phonePhoto, 'signature' => $png]));
        // PHP itself must let such a photo through, before this code ever sees it.
        $ini = parse_ini_file(public_path('.user.ini'));
        $this->assertSame(['6M', '8M'], [$ini['upload_max_filesize'], $ini['post_max_size']]);
    }

    public function test_only_the_assigned_driver_closes_a_stop_and_only_once(): void
    {
        $task = $this->deliveryReady();
        $stop = $this->stop(['drops' => $this->fullDrop(1, 2, 3)]);

        $this->loginAs('driver2');
        $this->assertError(403, 'forbidden', $this->complete($task, $stop));
        [$unassigned] = $this->activateContract(2);
        $this->loginAs('driver');
        $this->assertError(403, 'forbidden', $this->complete($unassigned, $stop));
        $this->assertError(404, 'not_found', $this->complete(999, $stop));

        $this->ok($this->complete($task, $stop));
        $this->assertError(409, 'not_open', $this->complete($task, $stop));   // sent twice
        $this->assertSame(1, (int) DB::scalar("SELECT COUNT(*) FROM tasks WHERE type = 'swap'"));
        $this->assertCount(2, $this->storedProofs());
    }

    public function test_a_stop_that_does_not_match_the_contract_needs_a_note(): void
    {
        $task = $this->deliveryReady();
        $short = ['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1, 2)]]];   // nothing for the staff entrance

        $this->assertError(422, 'note_required', $this->complete($task, $this->stop($short)));
        $this->assertSame('out_for_delivery', $this->item(1)['status']);
        $this->assertSame('open', $this->taskRow($task)['status']);
        $this->assertSame([], $this->storedProofs());

        // Right number of mats, wrong zone: also a mismatch.
        $wrongZone = ['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1)], ['zone_id' => 2, 'epcs' => $this->epcs(2, 3)]]];
        $this->assertError(422, 'note_required', $this->complete($task, $this->stop($wrongZone)));

        $out = $this->ok($this->complete($task, $this->stop($short + ['issue_note' => 'Staff entrance was locked'])));
        $this->assertSame('Staff entrance was locked', $out['task']['issue_note']);
        $this->assertSame(['out_for_delivery', $this->users['driver']], [$this->item(3)['status'], $this->item(3)['holder_user_id']]);
    }

    public function test_a_refused_mat_stays_on_the_van(): void
    {
        $task = $this->deliveryReady();
        $stop = $this->stop(['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1, 2)]], 'refused' => $this->epcs(3),
            'issue_note' => 'Customer refused the third mat']);
        $this->ok($this->complete($task, $stop));

        $this->assertSame(['refused', $this->users['driver'], null],
            [$this->item(3)['status'], $this->item(3)['holder_user_id'], $this->item(3)['zone_id']]);
        $event = $this->events(3)[0];
        $this->assertSame(['refuse', 'out_for_delivery', 'refused', $task], [$event['event_type'], $event['from_status'], $event['to_status'], $event['task_id']]);
    }

    public function test_an_impossible_stop_is_refused_whole(): void
    {
        $task = $this->deliveryReady();
        $note = ['issue_note' => 'x'];

        // Mat 10 is ready in inventory, not on the van.
        $this->assertError(409, 'wrong_status', $this->complete($task, $this->stop($note + ['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1, 2, 10)]]])));
        // Zone 3 is in another customer's building.
        $this->assertError(422, 'wrong_zone', $this->complete($task, $this->stop($note + ['drops' => [['zone_id' => 3, 'epcs' => $this->epcs(1)]]])));
        $this->assertError(422, 'unknown_tag', $this->complete($task, $this->stop($note + ['refused' => ['AAAA0000']])));
        $this->assertError(422, 'duplicate_tag', $this->complete($task, $this->stop($note + ['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1)]], 'refused' => $this->epcs(1)])));
        $this->assertError(422, 'invalid_input', $this->complete($task, $this->stop(['drops' => $this->fullDrop(1, 2, 3), 'signed_name' => ''])));
        $this->assertError(422, 'invalid_input', $this->complete($task, ['source' => null] + $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));
        $this->assertError(422, 'invalid_input', $this->complete($task, $this->stop(['drops' => 'everything'])));
        // More tags than one scan may carry: refused before any of them is looked up.
        $tooMany = array_map(fn (int $n) => sprintf('%08X', $n), range(1, Scan::MAX_READS + 1));
        $this->assertError(422, 'invalid_input', $this->complete($task, $this->stop($note + ['refused' => $tooMany])));

        $this->assertSame(['out_for_delivery', 'out_for_delivery', 'out_for_delivery'],
            [$this->item(1)['status'], $this->item(2)['status'], $this->item(3)['status']]);
        $this->assertSame('open', $this->taskRow($task)['status']);
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM item_events'));
        $this->assertSame([], $this->storedProofs());
    }

    public function test_a_swap_drops_clean_mats_and_takes_the_used_ones(): void
    {
        $swap = $this->swapReady();
        $out = $this->ok($this->complete($swap, $this->stop(['drops' => $this->fullDrop(4, 5, 6), 'pickups' => $this->epcs(1, 2, 3)])));

        $this->assertSame(['collected', $this->users['driver'], null],
            [$this->item(1)['status'], $this->item(1)['holder_user_id'], $this->item(1)['zone_id']]);
        $this->assertSame(['at_customer', 1], [$this->item(4)['status'], $this->item(4)['zone_id']]);
        $this->assertSame(['swap', $swap], [$this->taskRow($out['next_task_id'])['type'], $this->taskRow($out['next_task_id'])['previous_task_id']]);

        $collect = $this->events(3)[1];
        $this->assertSame(['collect', 'at_customer', 'collected', 2], [$collect['event_type'], $collect['from_status'], $collect['to_status'], $collect['zone_id']]);
    }

    public function test_a_mat_missing_at_pick_up_stays_expected_at_the_customer(): void
    {
        $swap = $this->swapReady();
        $stop = ['drops' => $this->fullDrop(4, 5, 6), 'pickups' => $this->epcs(1, 2)];
        $this->assertError(422, 'note_required', $this->complete($swap, $this->stop($stop)));

        $this->ok($this->complete($swap, $this->stop($stop + ['issue_note' => 'Mat at the staff entrance is gone'])));
        $this->assertSame(['at_customer', 2], [$this->item(3)['status'], $this->item(3)['zone_id']]);
    }

    public function test_a_mat_from_another_building_cannot_be_picked_up(): void
    {
        $swap = $this->swapReady();
        $this->setItems([20], 'at_customer', null, 3);   // zone 3 is in another building
        $stop = $this->stop(['drops' => $this->fullDrop(4, 5, 6), 'pickups' => $this->epcs(1, 2, 3, 20), 'issue_note' => 'x']);
        $this->assertError(409, 'wrong_status', $this->complete($swap, $stop));
        $this->assertSame('at_customer', $this->item(1)['status']);
    }

    public function test_a_collection_only_picks_up_and_plans_nothing(): void
    {
        $delivery = $this->deliveryReady();
        $this->ok($this->complete($delivery, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));

        $this->loginAs('office');
        $this->ok($this->api('POST', '/contracts/1/end'));   // cancels the swap that the delivery planned
        $collection = $this->ok($this->api('POST', '/tasks', ['contract_id' => 1, 'building_id' => 1, 'due_date' => today_nl()]))['task']['id'];
        $this->ok($this->api('PATCH', "/tasks/$collection", ['assigned_to' => $this->users['driver']]));

        $this->loginAs('driver');
        $this->assertError(422, 'no_drops', $this->complete($collection,
            $this->stop(['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(4)]], 'pickups' => $this->epcs(1, 2, 3)])));

        $out = $this->ok($this->complete($collection, $this->stop(['pickups' => $this->epcs(1, 2, 3)])));
        $this->assertNull($out['next_task_id']);
        $this->assertSame('collected', $this->item(2)['status']);
        $this->assertSame(0, (int) DB::scalar("SELECT COUNT(*) FROM tasks WHERE status = 'open'"));
    }

    public function test_a_delivery_for_an_ended_contract_plans_no_swap(): void
    {
        $task = $this->deliveryReady();
        $this->loginAs('office');
        $this->ok($this->api('POST', '/contracts/1/end'));

        $this->loginAs('driver');
        $out = $this->ok($this->complete($task, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));
        $this->assertNull($out['next_task_id']);
        $this->assertSame(0, (int) DB::scalar("SELECT COUNT(*) FROM tasks WHERE type = 'swap'"));
    }

    public function test_proof_files_are_served_to_the_office_only(): void
    {
        $task = $this->deliveryReady();
        $this->ok($this->complete($task, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));
        $this->assertError(403, 'forbidden', $this->api('GET', "/tasks/$task/photo"));   // not even the driver who took it

        $this->loginAs('office');
        [$status, $photo] = $this->api('GET', "/tasks/$task/photo");
        $this->assertSame(200, $status);
        $this->assertSame('image/jpeg', $photo->headers->get('Content-Type'));
        $this->assertSame('image/png', $this->api('GET', "/tasks/$task/signature")[1]->headers->get('Content-Type'));

        $open = DB::scalar("SELECT id FROM tasks WHERE status = 'open'");   // the planned swap has no proof yet
        $this->assertError(404, 'no_proof', $this->api('GET', "/tasks/$open/photo"));
        $this->assertError(404, 'not_found', $this->api('GET', '/tasks/999/photo'));
    }
}
