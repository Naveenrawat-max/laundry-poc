<?php

/** Contract-linked deliveries require two-sided custody and explicit per-mat refusal before proof and returns. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

final class PartialRefusalTest extends ApiTestCase
{
    private function body(array $over = []): array
    {
        return $over + ['type' => 'delivery', 'contract_id' => 1, 'customer_id' => 1, 'building_id' => 1,
            'assigned_to' => $this->users['driver'], 'due_date' => today_nl(), 'destination' => 'customer',
            'items' => [['item_id' => $this->item(1)['id'], 'zone_id' => 1], ['item_id' => $this->item(2)['id'], 'zone_id' => 1],
                ['item_id' => $this->item(3)['id'], 'zone_id' => 2]]];
    }

    private function order(): array
    {
        $this->activateContract(1);
        $this->loginAs('office');

        return $this->ok($this->api('POST', '/orders', $this->body()))['order'];
    }

    private function given(): array
    {
        $order = $this->order();
        $this->loginAs('inventory');
        foreach ([1, 2, 3] as $mat) {
            $this->ok($this->api('POST', "/orders/{$order['id']}/qc", ['source' => 'simulator', 'epc' => $this->epc($mat), 'result' => 'pass']));
        }

        return $this->ok($this->api('POST', "/orders/{$order['id']}/dispatch", $this->reads(1, 2, 3)))['order'];
    }

    private function ready(): array
    {
        $order = $this->given();
        $this->scanHandover('driver', $order['dispatch_handover_id'], 1, 2, 3);

        return $this->ok($this->api('GET', "/orders/{$order['id']}"))['order'];
    }

    private function scanAll(int $id): void
    {
        $this->ok($this->api('POST', "/orders/$id/scan", $this->reads(1, 2, 3)));
    }

    private function proof(int $id, string $response = 'accepted', ?array $refused = null): array
    {
        $body = ['source' => 'simulator', 'signed_name' => 'Partial Test Customer', 'customer_response' => $response];
        if ($refused !== null) {
            $body['refused_epcs'] = $refused;
        }

        return $this->api('POST', "/orders/$id/complete", $body,
            ['photo' => $this->proofFile('jpeg'), 'signature' => $this->proofFile('png')]);
    }

    private function partial(): array
    {
        $order = $this->ready();
        $this->scanAll($order['id']);
        $this->ok($this->api('POST', "/orders/{$order['id']}/refusal-scan", $this->reads(3)));

        return $this->ok($this->proof($order['id'], 'refused', $this->epcs(3)))['order'];
    }

    public function test_delivery_requires_active_matching_contract_and_exact_quantities_by_zone_and_type(): void
    {
        $this->loginAs('office');
        $this->assertError(422, 'invalid_contract', $this->api('POST', '/orders', $this->body(['contract_id' => null])));
        $this->assertError(422, 'invalid_contract', $this->api('POST', '/orders', $this->body()));
        $this->activateContract(1);
        $this->assertError(422, 'invalid_contract', $this->api('POST', '/orders', $this->body(['contract_id' => 2])));
        $this->assertError(422, 'contract_mismatch', $this->api('POST', '/orders', $this->body(['items' => [['item_id' => $this->item(1)['id'], 'zone_id' => 1]]])));
        $wrongZone = $this->body();
        $wrongZone['items'][2]['zone_id'] = 1;
        $this->assertError(422, 'contract_mismatch', $this->api('POST', '/orders', $wrongZone));
        $wrongType = $this->body();
        $wrongType['items'][2]['item_id'] = $this->item(25)['id'];
        $this->assertError(422, 'contract_mismatch', $this->api('POST', '/orders', $wrongType));
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_items')->count());
    }

    public function test_order_reuses_open_contract_visit_syncs_driver_due_and_prevents_duplicate_task_work(): void
    {
        [$task] = $this->activateContract(1, 'driver2');
        $this->loginAs('office');
        $order = $this->ok($this->api('POST', '/orders', $this->body(['due_date' => '2026-12-02'])))['order'];
        $this->assertSame($task, $order['task_id']);
        $this->assertSame(1, $order['contract_id']);
        $this->assertSame(1, DB::table('tasks')->where('contract_id', 1)->count());
        $linked = DB::table('tasks')->where('id', $task)->first();
        $this->assertSame([$this->users['driver'], '2026-12-02'], [$linked->assigned_to, $linked->due_date]);
        $officeTasks = $this->ok($this->api('GET', '/tasks'))['tasks'];
        $this->assertSame([$order['id'], $order['number']], [$officeTasks[0]['order_id'], $officeTasks[0]['order_number']]);
        foreach ([['assigned_to' => $this->users['driver2']], ['due_date' => '2026-12-03'], ['status' => 'cancelled']] as $patch) {
            $this->assertSame(409, $this->api('PATCH', "/tasks/$task", $patch)[0]);
        }
        $this->assertSame(409, $this->api('POST', '/contracts/1/end')[0]);
        $this->assertError(409, 'active_order', $this->api('PATCH', '/contracts/1', ['swap_interval_days' => 21]));
        $this->assertSame(14, $this->ok($this->api('PATCH', '/contracts/1', ['swap_interval_days' => 14]))['contract']['swap_interval_days']);
        $this->assertSame('2026-12-02', DB::table('tasks')->where('id', $task)->value('due_date'));
        $this->loginAs('driver');
        $this->assertSame([], $this->ok($this->api('GET', '/tasks'))['tasks']);
        $this->assertSame(409, $this->api('POST', "/tasks/$task/complete", [
            'source' => 'simulator', 'drops' => [], 'pickups' => [], 'refused' => [], 'signed_name' => 'Bypass', 'issue_note' => 'Bypass'],
            ['photo' => $this->proofFile('jpeg'), 'signature' => $this->proofFile('png')])[0]);
        $this->assertSame('open', DB::table('tasks')->where('id', $task)->value('status'));
    }

    public function test_active_contract_without_open_visit_gets_one_linked_delivery_task(): void
    {
        $this->activateContract(1);
        DB::table('tasks')->where('contract_id', 1)->update(['status' => 'cancelled']);
        $this->loginAs('office');
        $order = $this->ok($this->api('POST', '/orders', $this->body()))['order'];
        $task = DB::table('tasks')->where('id', $order['task_id'])->first();
        $this->assertSame(['delivery', 'open', 1, $this->users['driver']], [$task->type, $task->status, $task->contract_id, $task->assigned_to]);
        $this->assertSame(1, DB::table('tasks')->where('contract_id', 1)->where('status', 'open')->count());
    }

    public function test_inventory_give_is_not_driver_receipt_and_partial_or_extra_receipts_cannot_unlock_customer_work(): void
    {
        $order = $this->given();
        $id = $order['id'];
        $handover = $order['dispatch_handover_id'];
        $this->assertSame('ready', $order['status']);
        $this->assertNull($order['dispatched_at']);
        $this->assertSame('qc_passed', $this->item(1)['status']);
        $detail = $this->ok($this->api('GET', "/handovers/$handover"))['handover'];
        $this->assertSame([$id, $order['number']], [$detail['order_id'], $detail['order_number']]);
        $this->loginAs('driver2');
        $this->assertError(403, 'forbidden', $this->api('POST', "/handovers/$handover/scan", $this->reads(1)));
        $this->loginAs('driver');
        $this->assertError(409, 'wrong_status', $this->api('POST', "/orders/$id/scan", $this->reads(1, 2, 3)));
        $this->assertError(409, 'wrong_status', $this->proof($id));
        $partial = $this->scanHandover('driver', $handover, 1, 4);
        $this->assertSame(['found', 'rejected'], array_column($partial['results'], 'result'));
        $this->assertSame('ready', $this->ok($this->api('GET', "/orders/$id"))['order']['status']);
        $this->assertSame('ready', $this->item(4)['status']);
        $this->assertError(409, 'wrong_status', $this->api('POST', "/orders/$id/scan", $this->reads(1)));
        $this->loginAs('office');
        $this->assertSame(409, $this->api('POST', "/handovers/$handover/close")[0]);
        $this->scanHandover('driver', $handover, 2, 3);
        $ready = $this->ok($this->api('GET', "/orders/$id"))['order'];
        $this->assertSame('in_progress', $ready['status']);
        $this->assertNotNull($ready['dispatched_at']);
        $this->assertSame(['out_for_delivery', $this->users['driver']], [$this->item(3)['status'], $this->item(3)['holder_user_id']]);
    }

    public function test_refusal_needs_separate_exact_scan_and_never_bypasses_customer_scan_or_proof_gate(): void
    {
        $order = $this->ready();
        $id = $order['id'];
        $this->assertError(422, 'scan_required', $this->api('POST', "/orders/$id/refusal-scan", $this->reads(3)));
        $this->scanAll($id);
        $this->assertSame(422, $this->proof($id, 'refused', $this->epcs(3))[0]);
        $this->assertSame([], Storage::disk('local')->files('proofs'));
        $this->assertError(422, 'not_on_order', $this->api('POST', "/orders/$id/refusal-scan", $this->reads(3, 4)));
        $this->assertSame([null, null, null], DB::table('order_items')->where('order_id', $id)->pluck('refusal_scanned_at')->all());
        $this->loginAs('driver2');
        $this->assertError(403, 'forbidden', $this->api('POST', "/orders/$id/refusal-scan", $this->reads(3)));
        $this->loginAs('driver');
        $checked = $this->ok($this->api('POST', "/orders/$id/refusal-scan", $this->reads(3)))['order'];
        $stamp = $checked['items'][2]['refusal_scanned_at'];
        $this->assertNotNull($stamp);
        $this->assertSame($stamp, $this->ok($this->api('POST', "/orders/$id/refusal-scan", $this->reads(3)))['order']['items'][2]['refusal_scanned_at']);
        $this->assertSame(422, $this->proof($id, 'accepted', $this->epcs(3))[0]);
        $this->assertSame(422, $this->proof($id, 'refused', [])[0]);
        $this->assertSame(422, $this->proof($id, 'refused', $this->epcs(4))[0]);
        $this->assertSame('in_progress', DB::table('orders')->where('id', $id)->value('status'));
        $this->assertSame([], Storage::disk('local')->files('proofs'));
    }

    public function test_partial_delivery_keeps_only_refused_mat_reserved_and_finishes_linked_task_and_next_visit_once(): void
    {
        $order = $this->partial();
        $id = $order['id'];
        $this->assertSame(['awaiting_receipt', 'partial', 1, 1], [$order['status'], $order['customer_response'], $order['refused_count'], $order['return_pending_count']]);
        $this->assertSame(['accepted', 'accepted', 'refused'], array_column($order['items'], 'customer_response'));
        $this->assertSame([null, null, $this->item(3)['id']], array_column($order['items'], 'reserved_item_id'));
        $this->assertSame(['at_customer', 'at_customer', 'refused'], [$this->item(1)['status'], $this->item(2)['status'], $this->item(3)['status']]);
        $this->assertSame([null, $this->users['driver']], [$this->item(1)['holder_user_id'], $this->item(3)['holder_user_id']]);
        $task = DB::table('tasks')->where('id', $order['task_id'])->first();
        $stored = DB::table('orders')->where('id', $id)->first();
        $this->assertSame(['done', 'Partial Test Customer', $stored->photo_path, $stored->signature_path], [$task->status, $task->signed_name, $task->photo_path, $task->signature_path]);
        $next = DB::table('tasks')->where('previous_task_id', $order['task_id'])->first();
        $this->assertSame(['swap', days_after(today_nl(), 14)], [$next->type, $next->due_date]);
        foreach ([1, 2, 3] as $mat) {
            $events = $this->events($mat);
            $this->assertSame($order['task_id'], $events[array_key_last($events)]['task_id']);
        }
        $this->assertCount(2, Storage::disk('local')->files('proofs'));
        $this->assertError(409, 'wrong_status', $this->proof($id, 'refused', $this->epcs(3)));
        $this->assertSame(1, DB::table('tasks')->where('previous_task_id', $order['task_id'])->count());
        $this->loginAs('office');
        $notice = $this->ok($this->api('GET', '/orders'))['orders'][0];
        $this->assertSame([1, 1], [$notice['refused_count'], $notice['return_pending_count']]);
        $this->assertSame(409, $this->api('POST', '/contracts/1/end')[0]);
    }

    public function test_refused_return_requires_driver_and_inventory_scans_without_office_approval_or_accepted_mats(): void
    {
        $order = $this->partial();
        $id = $order['id'];
        $this->assertError(422, 'invalid_reference', $this->api('POST', "/orders/$id/return", ['receiver_id' => $this->users['washing']]));
        $this->loginAs('driver2');
        $this->assertError(403, 'forbidden', $this->api('POST', "/orders/$id/return", ['receiver_id' => $this->users['inventory']]));
        $this->loginAs('driver');
        $planned = $this->ok($this->api('POST', "/orders/$id/return", ['receiver_id' => $this->users['inventory']]))['order'];
        $handover = $planned['return_handover_id'];
        $again = $this->ok($this->api('POST', "/orders/$id/return", ['receiver_id' => $this->users['inventory']]))['order'];
        $this->assertSame($handover, $again['return_handover_id']);
        $this->assertSame(0, DB::table('handover_items')->where('handover_id', $handover)->count());
        $detail = $this->ok($this->api('GET', "/handovers/$handover"))['handover'];
        $this->assertSame([$this->item(3)['id']], array_column($detail['items'], 'id'));
        $this->assertNull($detail['items'][0]['given_at']);
        $this->assertNull($detail['items'][0]['received_at']);
        $this->loginAs('office');
        $this->assertError(409, 'incomplete_handover', $this->api('POST', "/handovers/$handover/close"));
        $this->loginAs('inventory');
        $this->assertSame(409, $this->api('POST', "/orders/$id/receive", $this->reads(3))[0]);
        $earlyReceipt = $this->scanHandover('inventory', $handover, 3, 1);
        $this->assertSame(['found', 'rejected'], array_column($earlyReceipt['results'], 'result'));
        $physical = DB::table('handover_items')->where('handover_id', $handover)->get();
        $this->assertCount(1, $physical);
        $this->assertSame($this->item(3)['id'], $physical[0]->item_id);
        $this->assertNull($physical[0]->given_at);
        $this->assertNotNull($physical[0]->received_at);
        $this->assertSame('refused', $this->item(3)['status']);
        $this->assertSame('awaiting_receipt', $this->ok($this->api('GET', "/orders/$id"))['order']['status']);
        $this->scanHandover('driver', $handover, 3);
        $done = $this->ok($this->api('GET', "/orders/$id"))['order'];
        $this->assertSame(['done', 1, 0], [$done['status'], $done['refused_count'], $done['return_pending_count']]);
        $this->assertSame(['registered', 0, null], [$this->item(3)['status'], $this->item(3)['wash_count'], $this->item(3)['holder_user_id']]);
        $this->assertSame(['at_customer', 'at_customer'], [$this->item(1)['status'], $this->item(2)['status']]);
        $this->assertSame('closed', DB::table('handovers')->where('id', $handover)->value('status'));
        $this->assertSame('closed', DB::table('handovers')->where('id', $order['dispatch_handover_id'])->value('status'));
        $before = count($this->events(3));
        $this->api('POST', "/handovers/$handover/scan", $this->reads(3));
        $this->assertCount($before, $this->events(3));
        $this->loginAs('office');
        $notice = $this->ok($this->api('GET', '/orders'))['orders'][0];
        $this->assertSame([1, 0], [$notice['refused_count'], $notice['return_pending_count']]);
        $pickup = array_column($this->ok($this->api('GET', '/orders/available-items?type=pickup&customer_id=1&building_id=1'))['items'], 'id');
        $this->assertEqualsCanonicalizing([$this->item(1)['id'], $this->item(2)['id']], $pickup);
        $this->assertSame('ended', $this->ok($this->api('POST', '/contracts/1/end'))['contract']['status']);
    }

    public function test_return_receiver_must_be_active_inventory_and_other_inventory_cannot_take_custody(): void
    {
        $order = $this->partial();
        $id = $order['id'];
        $hash = DB::table('users')->where('id', $this->users['inventory'])->value('password_hash');
        $this->users['inventory2'] = DB::table('users')->insertGetId(['name' => 'Other Inventory', 'username' => 'inventory2',
            'role' => 'inventory', 'password_hash' => $hash, 'is_active' => 0]);
        $this->assertError(422, 'invalid_reference', $this->api('POST', "/orders/$id/return", ['receiver_id' => $this->users['inventory2']]));
        DB::table('users')->where('id', $this->users['inventory2'])->update(['is_active' => 1]);
        $planned = $this->ok($this->api('POST', "/orders/$id/return", ['receiver_id' => $this->users['inventory']]))['order'];
        $handover = $planned['return_handover_id'];
        $this->assertError(409, 'already_planned', $this->api('POST', "/orders/$id/return", ['receiver_id' => $this->users['inventory2']]));
        $this->loginAs('inventory2');
        $this->assertError(403, 'forbidden', $this->api('GET', "/handovers/$handover"));
        $this->assertError(403, 'forbidden', $this->api('POST', "/handovers/$handover/scan", $this->reads(3)));
        $this->assertSame(0, DB::table('handover_items')->where('handover_id', $handover)->count());
        $this->assertSame('refused', $this->item(3)['status']);
    }

    public function test_all_refused_keeps_legacy_omitted_subset_behavior_and_schedules_once(): void
    {
        $order = $this->ready();
        $this->scanAll($order['id']);
        $this->ok($this->api('POST', "/orders/{$order['id']}/refusal-scan", $this->reads(1, 2, 3)));
        $done = $this->ok($this->proof($order['id'], 'refused'))['order'];
        $this->assertSame(['refused', 3, 3], [$done['customer_response'], $done['refused_count'], $done['return_pending_count']]);
        $this->assertSame(['refused', 'refused', 'refused'], array_column($done['items'], 'customer_response'));
        $this->assertSame(1, DB::table('tasks')->where('previous_task_id', $order['task_id'])->count());
    }

    public function test_all_accepted_finishes_linked_task_without_return_and_reports_next_visit(): void
    {
        $order = $this->ready();
        $this->scanAll($order['id']);
        $answer = $this->ok($this->proof($order['id']));
        $this->assertSame(['done', 'accepted', 0, 0], [$answer['order']['status'], $answer['order']['customer_response'],
            $answer['order']['refused_count'], $answer['order']['return_pending_count']]);
        $this->assertSame(['accepted', 'accepted', 'accepted'], array_column($answer['order']['items'], 'customer_response'));
        $this->assertNull($answer['order']['return_handover_id']);
        $this->assertNotNull($answer['next_task_id']);
        $this->assertSame($order['task_id'], DB::table('tasks')->where('id', $answer['next_task_id'])->value('previous_task_id'));
        $this->assertSame('done', DB::table('tasks')->where('id', $order['task_id'])->value('status'));
    }

    public function test_historical_refused_order_with_null_links_returns_without_fabricating_old_scan_evidence(): void
    {
        $this->setItems([1], 'refused', $this->users['driver']);
        $id = DB::table('orders')->insertGetId(['number' => 'LEGACY-REFUSED-1', 'type' => 'delivery', 'customer_id' => 1,
            'building_id' => 1, 'assigned_to' => $this->users['driver'], 'created_by' => $this->users['office'],
            'due_date' => today_nl(), 'status' => 'awaiting_receipt', 'destination' => 'customer', 'customer_response' => 'refused']);
        DB::table('order_items')->insert(['order_id' => $id, 'item_id' => $this->item(1)['id'], 'reserved_item_id' => $this->item(1)['id'],
            'zone_id' => 1, 'qc_status' => 'passed']);
        $this->loginAs('driver');
        $order = $this->ok($this->api('POST', "/orders/$id/return", ['receiver_id' => $this->users['inventory']]))['order'];
        $this->assertNull($order['contract_id']);
        $this->assertNull($order['task_id']);
        $this->assertNull($order['dispatch_handover_id']);
        $this->assertNull($order['items'][0]['refusal_scanned_at']);
        $this->assertSame('refused', $order['items'][0]['customer_response']);
        $this->scanHandover('driver', $order['return_handover_id'], 1);
        $this->scanHandover('inventory', $order['return_handover_id'], 1);
        $done = $this->ok($this->api('GET', "/orders/$id"))['order'];
        $this->assertSame('done', $done['status']);
        $this->assertNull($done['items'][0]['refusal_scanned_at']);
        $this->assertSame(['registered', 0], [$this->item(1)['status'], $this->item(1)['wash_count']]);
        $this->assertSame(0, DB::table('tasks')->count());
    }

    public function test_historical_in_progress_order_can_complete_without_invented_task_or_dispatch_links(): void
    {
        $this->setItems([1], 'out_for_delivery', $this->users['driver']);
        $id = DB::table('orders')->insertGetId(['number' => 'LEGACY-DRIVING-1', 'type' => 'delivery', 'customer_id' => 1,
            'building_id' => 1, 'assigned_to' => $this->users['driver'], 'created_by' => $this->users['office'],
            'due_date' => today_nl(), 'status' => 'in_progress', 'destination' => 'customer']);
        DB::table('order_items')->insert(['order_id' => $id, 'item_id' => $this->item(1)['id'], 'reserved_item_id' => $this->item(1)['id'],
            'zone_id' => 1, 'qc_status' => 'passed']);
        $this->loginAs('driver');
        $this->ok($this->api('POST', "/orders/$id/scan", $this->reads(1)));
        $answer = $this->ok($this->proof($id));
        $this->assertSame('done', $answer['order']['status']);
        $this->assertNull($answer['order']['task_id']);
        $this->assertNull($answer['order']['dispatch_handover_id']);
        $this->assertNull($answer['next_task_id']);
        $this->assertSame('at_customer', $this->item(1)['status']);
        $this->assertSame(0, DB::table('tasks')->count());
    }
}
