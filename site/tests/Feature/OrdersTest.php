<?php

/** Exact-mat office orders: reservations, QC, scan-before-proof, pickup routing, receiver isolation and wash retries. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

final class OrdersTest extends ApiTestCase
{
    private ?int $deliveryContractId = null;

    private function body(string $type = 'delivery', array $mats = [1, 2], array $over = []): array
    {
        $body = $over + ['type' => $type, 'customer_id' => 1, 'building_id' => 1,
            'assigned_to' => $this->users['driver'], 'due_date' => today_nl(),
            'destination' => $type === 'delivery' ? 'customer' : 'inventory',
            'return_reason' => $type === 'pickup' ? (($over['destination'] ?? 'inventory') === 'washing' ? 'used' : 'unused_clean') : null,
            'reason' => $type === 'pickup' ? 'Customer return without washing' : null,
            'items' => array_map(fn (int $n) => ['item_id' => $this->item($n)['id'], 'zone_id' => 1], $mats)];
        if ($type === 'delivery' && !array_key_exists('contract_id', $body)) {
            $body['contract_id'] = $this->contractFor($mats);
        }

        return $body;
    }

    /** Isolated fixture contract describes exactly these selected mats in the front-door zone. */
    private function contractFor(array $mats): int
    {
        if ($this->deliveryContractId !== null) {
            return $this->deliveryContractId;
        }
        $id = DB::table('contracts')->insertGetId(['customer_id' => 1, 'building_id' => 1, 'swap_interval_days' => 14,
            'start_date' => today_nl(), 'status' => 'active']);
        $types = [];
        foreach ($mats as $mat) {
            $type = $this->item($mat)['item_type_id'];
            $types[$type] = ($types[$type] ?? 0) + 1;
        }
        foreach ($types as $type => $quantity) {
            DB::table('contract_lines')->insert(['contract_id' => $id, 'zone_id' => 1, 'item_type_id' => $type, 'quantity' => $quantity]);
        }

        return $this->deliveryContractId = $id;
    }

    private function order(string $type = 'delivery', array $mats = [1, 2], array $over = []): array
    {
        $this->loginAs('office');

        return $this->ok($this->api('POST', '/orders', $this->body($type, $mats, $over)))['order'];
    }

    private function qc(int $id, int $mat, string $result = 'pass'): array
    {
        return $this->api('POST', "/orders/$id/qc", ['source' => 'simulator', 'epc' => $this->epc($mat), 'result' => $result]);
    }

    private function dispatch(array $mats = [1, 2]): int
    {
        $id = $this->order('delivery', $mats)['id'];
        $this->loginAs('inventory');
        foreach ($mats as $mat) {
            $this->ok($this->qc($id, $mat));
        }
        $order = $this->ok($this->api('POST', "/orders/$id/dispatch", $this->reads(...$mats)))['order'];
        $this->scanHandover('driver', $order['dispatch_handover_id'], ...$mats);
        $this->loginAs('driver');

        return $id;
    }

    private function scan(int $id, int $mat): array
    {
        return $this->api('POST', "/orders/$id/scan", ['source' => 'simulator', 'epc' => $this->epc($mat)]);
    }

    private function complete(int $id, string $response = 'accepted', ?array $files = null, array $extra = []): array
    {
        return $this->api('POST', "/orders/$id/complete",
            $extra + ['source' => 'simulator', 'signed_name' => 'Mock Contact One', 'customer_response' => $response],
            $files ?? ['photo' => $this->proofFile('jpeg'), 'signature' => $this->proofFile('png')]);
    }

    private function pickup(string $destination = 'inventory', array $mats = [1, 2], ?string $reason = null): int
    {
        $this->setItems($mats, 'at_customer', null, 1);
        $extra = ['destination' => $destination];
        if ($reason !== null) {
            $extra['return_reason'] = $reason;
        }
        if ($destination === 'washing') {
            $extra['washing_user_id'] = $this->users['washing'];
        }
        $id = $this->order('pickup', $mats, $extra)['id'];
        $this->loginAs('driver');

        return $id;
    }

    private function collected(string $destination = 'inventory', array $mats = [1, 2], ?string $reason = null): int
    {
        $id = $this->pickup($destination, $mats, $reason);
        foreach ($mats as $mat) {
            $this->ok($this->scan($id, $mat));
        }
        $this->assertSame('awaiting_receipt', $this->ok($this->complete($id))['order']['status']);

        return $id;
    }

    private function activeItems(array $order): array
    {
        return array_values(array_filter($order['items'], fn (array $line) => $line['replaced_at'] === null));
    }

    private function assertWashingRedacted(array $order): void
    {
        foreach (['customer_id', 'customer_name', 'building_id', 'building_name', 'street', 'postcode', 'city',
            'signed_name', 'customer_response', 'reason', 'photo_path', 'signature_path', 'contract_id', 'task_id',
            'refused_count', 'return_pending_count', 'contract_start_date', 'swap_interval_days', 'next_swap_date'] as $field) {
            $this->assertArrayNotHasKey($field, $order);
        }
        foreach ($order['items'] as $line) {
            foreach (['zone_id', 'zone_name', 'qc_note', 'current_zone_id', 'customer_response', 'refusal_scanned_at'] as $field) {
                $this->assertArrayNotHasKey($field, $line);
            }
        }
    }

    public function test_office_order_reserves_exact_mats_and_validates_customer_building_zone_and_driver(): void
    {
        $this->loginAs('office');
        $this->assertCount(40, $this->ok($this->api('GET', '/orders/available-items?type=delivery'))['items']);
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/orders', $this->body(over: ['building_id' => 2])));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/orders', $this->body(over: ['assigned_to' => $this->users['washing']])));
        $this->assertError(422, 'wrong_zone', $this->api('POST', '/orders', $this->body(over: ['items' => [['item_id' => $this->item(1)['id'], 'zone_id' => 3]]])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/orders', $this->body(mats: [1, 1])));
        $this->assertSame(0, DB::table('orders')->count());

        $order = $this->order();
        $this->assertMatchesRegularExpression('/^DO-\d{8}-[A-F0-9]{12}$/', $order['number']);
        $this->assertSame(['pending_qc', 'delivery', 'Mock Bakery BV', 'Driver'],
            [$order['status'], $order['type'], $order['customer_name'], $order['assigned_name']]);
        $this->assertSame([$this->item(1)['id'], $this->item(2)['id']], array_column($order['items'], 'item_id'));
        $this->assertSame(['pending', 'pending'], array_column($order['items'], 'qc_status'));
        $this->assertSame(['ready', 'ready'], [$this->item(1)['status'], $this->item(2)['status']]);
        $this->assertCount(38, $this->ok($this->api('GET', '/orders/available-items?type=delivery'))['items']);
        $this->assertError(409, 'reserved', $this->api('POST', '/orders', $this->body()));
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_inactive_customer_building_or_driver_cannot_create_order(): void
    {
        $this->loginAs('office');
        foreach ([['customers', 1], ['buildings', 1], ['users', $this->users['driver']]] as [$table, $id]) {
            DB::table($table)->where('id', $id)->update(['is_active' => 0]);
            $this->assertError(422, 'invalid_reference', $this->api('POST', '/orders', $this->body()));
            DB::table($table)->where('id', $id)->update(['is_active' => 1]);
        }
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_order_needs_fresh_qc_even_for_prechecked_mats_and_dispatch_is_atomic(): void
    {
        $this->setItems([1, 2], 'qc_passed');
        $id = $this->order()['id'];
        $this->loginAs('inventory');
        $this->assertError(409, 'wrong_status', $this->api('POST', "/orders/$id/dispatch", $this->reads(1, 2)));
        $this->assertError(422, 'not_on_order', $this->qc($id, 3));
        $this->assertSame('pending_qc', $this->ok($this->qc($id, 1))['order']['status']);
        $this->assertError(409, 'already_checked', $this->qc($id, 1));
        $this->assertSame('ready', $this->ok($this->qc($id, 2))['order']['status']);
        $this->assertError(422, 'incomplete_scan', $this->api('POST', "/orders/$id/dispatch", $this->reads(1)));
        $this->assertError(422, 'not_on_order', $this->api('POST', "/orders/$id/dispatch", $this->reads(1, 3)));
        $this->assertSame(['qc_passed', 'qc_passed'], [$this->item(1)['status'], $this->item(2)['status']]);
        $dispatched = $this->ok($this->api('POST', "/orders/$id/dispatch", $this->reads(1, 2)))['order'];
        $this->assertSame('ready', $dispatched['status']);
        $this->assertNull($dispatched['dispatched_at']);
        $this->assertNotNull($dispatched['dispatch_handover_id']);
        $this->assertSame(['qc_passed', null], [$this->item(1)['status'], $this->item(1)['holder_user_id']]);
        $this->scanHandover('driver', $dispatched['dispatch_handover_id'], 1, 2);
        $this->assertSame('in_progress', $this->ok($this->api('GET', "/orders/$id"))['order']['status']);
        $this->assertSame('closed', DB::table('handovers')->where('id', $dispatched['dispatch_handover_id'])->value('status'));
    }

    public function test_failed_qc_requires_compatible_replacement_then_recheck(): void
    {
        $id = $this->order()['id'];
        $this->loginAs('inventory');
        $this->assertError(409, 'not_failed', $this->api('POST', "/orders/$id/replace", ['item_id' => $this->item(1)['id'], 'replacement_item_id' => $this->item(3)['id']]));
        $this->ok($this->qc($id, 1, 'repair'));
        $this->ok($this->qc($id, 2));
        $this->assertSame('repair', $this->item(1)['status']);
        $this->assertError(422, 'wrong_product', $this->api('POST', "/orders/$id/replace", ['item_id' => $this->item(1)['id'], 'replacement_item_id' => $this->item(25)['id']]));
        $order = $this->ok($this->api('POST', "/orders/$id/replace", ['item_id' => $this->item(1)['id'], 'replacement_item_id' => $this->item(3)['id']]))['order'];
        $this->assertNotNull($order['items'][0]['replaced_at']);
        $this->assertNull($order['items'][0]['reserved_item_id']);
        $this->assertSame([$this->item(2)['id'], $this->item(3)['id']], array_column($this->activeItems($order), 'item_id'));
        $this->assertError(422, 'not_on_order', $this->qc($id, 1));
        $this->assertSame('ready', $this->ok($this->qc($id, 3))['order']['status']);
        $this->ok($this->api('POST', "/orders/$id/dispatch", $this->reads(2, 3)));
        $this->assertSame('repair', $this->item(1)['status']);
    }

    public function test_driver_checks_only_scanned_exact_mat_persists_reload_and_cannot_submit_forged_proof_early(): void
    {
        $id = $this->dispatch();
        $this->assertError(422, 'scan_required', $this->complete($id, extra: ['scanned' => $this->epcs(1, 2)]));
        $this->assertError(422, 'not_on_order', $this->scan($id, 3));
        $order = $this->ok($this->scan($id, 1))['order'];
        $this->assertNotNull($order['items'][0]['scanned_at']);
        $this->assertNull($order['items'][1]['scanned_at']);
        $stamp = $order['items'][0]['scanned_at'];
        $this->loginAs('driver');
        $reload = $this->ok($this->api('GET', "/orders/$id"))['order'];
        $this->assertSame([$stamp, null], array_column($reload['items'], 'scanned_at'));
        $this->assertSame($stamp, $this->ok($this->scan($id, 1))['order']['items'][0]['scanned_at']);
        $this->assertError(422, 'scan_required', $this->complete($id));
        $this->assertSame([], Storage::disk('local')->files('proofs'));
        $this->assertSame('out_for_delivery', $this->item(1)['status']);
        $this->ok($this->scan($id, 2));
        $done = $this->ok($this->complete($id))['order'];
        $this->assertSame(['done', 'accepted', 'Mock Contact One'], [$done['status'], $done['customer_response'], $done['signed_name']]);
        $this->assertSame(['at_customer', 1, null], [$this->item(1)['status'], $this->item(1)['zone_id'], $this->item(1)['holder_user_id']]);
        $this->assertSame([null, null], array_column($done['items'], 'reserved_item_id'));
        $this->assertCount(2, Storage::disk('local')->files('proofs'));
        $this->assertError(409, 'wrong_status', $this->complete($id));
        $this->assertCount(2, Storage::disk('local')->files('proofs'));
    }

    public function test_driver_cannot_scan_or_read_another_drivers_order(): void
    {
        $id = $this->dispatch();
        $this->loginAs('driver2');
        $this->assertSame([], $this->ok($this->api('GET', '/orders'))['orders']);
        $this->assertError(403, 'forbidden', $this->api('GET', "/orders/$id"));
        $this->assertError(403, 'forbidden', $this->scan($id, 1));
        $this->assertError(403, 'forbidden', $this->complete($id));
        $this->assertSame([null, null], DB::table('order_items')->where('order_id', $id)->pluck('scanned_at')->all());
    }

    public function test_driver_batch_scan_marks_every_exact_mat_and_unlocks_proof(): void
    {
        $id = $this->dispatch([1, 2, 3]);
        $answer = $this->ok($this->api('POST', "/orders/$id/scan", $this->reads(1, 2, 3)));
        $this->assertSame([], $answer['rejected']);
        $this->assertCount(3, array_filter(array_column($answer['order']['items'], 'scanned_at')));
        $this->assertSame('done', $this->ok($this->complete($id))['order']['status']);
        $this->assertSame(['at_customer', 'at_customer', 'at_customer'], [$this->item(1)['status'], $this->item(2)['status'], $this->item(3)['status']]);
    }

    public function test_partial_batch_persists_only_matches_reports_strays_and_keeps_proof_blocked(): void
    {
        $id = $this->dispatch([1, 2, 3]);
        $unknown = 'AAAA00000000000000000000';
        $reads = ['source' => 'simulator', 'reads' => [['epc' => $this->epc(1)], ['epc' => $this->epc(4)], ['epc' => $unknown]]];
        $answer = $this->ok($this->api('POST', "/orders/$id/scan", $reads));
        $this->assertSame([$this->epc(4), $unknown], array_column($answer['rejected'], 'epc'));
        $this->assertSame(['Scan an exact mat on this order.', 'Scan an exact mat on this order.'], array_column($answer['rejected'], 'reason'));
        $this->assertNotNull($answer['order']['items'][0]['scanned_at']);
        $this->assertSame([null, null], array_slice(array_column($answer['order']['items'], 'scanned_at'), 1));
        $this->assertSame('ready', $this->item(4)['status']);
        $this->assertCount(0, $this->events(4));
        $this->assertError(422, 'scan_required', $this->complete($id, extra: ['reads' => $this->reads(1, 2, 3)['reads']]));
        $this->assertSame([], Storage::disk('local')->files('proofs'));
        $reload = $this->ok($this->api('GET', "/orders/$id"))['order'];
        $this->assertSame(array_column($answer['order']['items'], 'scanned_at'), array_column($reload['items'], 'scanned_at'));
        $this->ok($this->api('POST', "/orders/$id/scan", $this->reads(2, 3)));
        $this->assertSame('done', $this->ok($this->complete($id))['order']['status']);
    }

    public function test_batch_duplicate_and_lowercase_tags_are_normalized_and_retries_preserve_timestamps(): void
    {
        $id = $this->dispatch([1, 2]);
        $reads = ['source' => 'simulator', 'reads' => [['epc' => strtolower($this->epc(1))], ['epc' => $this->epc(1)],
            ['epc' => strtolower($this->epc(2))], ['epc' => $this->epc(2)]]];
        $answer = $this->ok($this->api('POST', "/orders/$id/scan", $reads));
        $this->assertSame([], $answer['rejected']);
        $stamps = array_column($answer['order']['items'], 'scanned_at');
        $this->assertCount(2, array_filter($stamps));
        $this->assertSame($stamps, array_column($this->ok($this->api('POST', "/orders/$id/scan", $reads))['order']['items'], 'scanned_at'));
        $this->assertCount(3, $this->events(1));
    }

    public function test_all_unrelated_pickup_batch_does_not_start_order_or_check_any_mat(): void
    {
        $id = $this->pickup();
        $answer = $this->ok($this->api('POST', "/orders/$id/scan", $this->reads(3, 4)));
        $this->assertSame('ready', $answer['order']['status']);
        $this->assertSame($this->epcs(3, 4), array_column($answer['rejected'], 'epc'));
        $this->assertSame([null, null], array_column($answer['order']['items'], 'scanned_at'));
        $this->assertError(409, 'wrong_status', $this->complete($id));
        $full = $this->ok($this->api('POST', "/orders/$id/scan", $this->reads(1, 2)));
        $this->assertCount(2, array_filter(array_column($full['order']['items'], 'scanned_at')));
        $this->assertSame('awaiting_receipt', $this->ok($this->complete($id))['order']['status']);
    }

    public function test_batch_scan_rejects_other_driver_and_undispatched_delivery(): void
    {
        $id = $this->order()['id'];
        $this->loginAs('driver');
        $this->assertError(409, 'wrong_status', $this->api('POST', "/orders/$id/scan", $this->reads(1, 2)));
        $this->loginAs('driver2');
        $this->assertError(403, 'forbidden', $this->api('POST', "/orders/$id/scan", $this->reads(1, 2)));
        $this->assertSame([null, null], DB::table('order_items')->where('order_id', $id)->pluck('scanned_at')->all());
    }

    public function test_batch_scan_rejects_bad_input_before_persisting_any_mat(): void
    {
        $id = $this->dispatch();
        foreach ([[], null, 'all', ['epc' => $this->epc(1)], array_fill(0, 5001, ['epc' => $this->epc(1)])] as $reads) {
            $this->assertError(422, 'invalid_input', $this->api('POST', "/orders/$id/scan", ['source' => 'simulator', 'reads' => $reads]));
        }
        $this->assertError(422, 'invalid_tag', $this->api('POST', "/orders/$id/scan", ['source' => 'simulator', 'reads' => [['epc' => $this->epc(1)], ['epc' => 'NOT-HEX!']]]));
        $this->assertSame([null, null], DB::table('order_items')->where('order_id', $id)->pluck('scanned_at')->all());
    }

    public function test_wrong_holder_on_exact_mat_rolls_back_entire_batch(): void
    {
        $id = $this->dispatch();
        DB::table('items')->where('id', $this->item(2)['id'])->update(['holder_user_id' => $this->users['driver2']]);
        $this->assertError(409, 'wrong_status', $this->api('POST', "/orders/$id/scan", $this->reads(1, 2)));
        $this->assertSame([null, null], DB::table('order_items')->where('order_id', $id)->pluck('scanned_at')->all());
    }

    public function test_order_proof_requires_real_photo_signature_and_customer_choice(): void
    {
        $id = $this->dispatch([1]);
        $this->ok($this->scan($id, 1));
        $this->assertError(422, 'proof_required', $this->complete($id, files: ['signature' => $this->proofFile('png')]));
        $this->assertError(422, 'wrong_file_type', $this->complete($id, files: ['photo' => $this->proofFile('png'), 'signature' => $this->proofFile('png')]));
        $this->assertError(422, 'file_too_big', $this->complete($id, files: ['photo' => $this->proofFile('jpeg', 5 * 1024 * 1024), 'signature' => $this->proofFile('png')]));
        $this->assertError(422, 'invalid_input', $this->complete($id, 'maybe'));
        $this->assertSame([], Storage::disk('local')->files('proofs'));
        $this->assertSame('in_progress', DB::table('orders')->where('id', $id)->value('status'));
    }

    public function test_customer_refused_delivery_returns_only_to_inventory_without_counting_wash(): void
    {
        $id = $this->dispatch([1]);
        $this->ok($this->scan($id, 1));
        $this->ok($this->api('POST', "/orders/$id/refusal-scan", $this->reads(1)));
        $refused = $this->ok($this->complete($id, 'refused'))['order'];
        $this->assertSame('awaiting_receipt', $refused['status']);
        $this->assertSame(['refused', $this->users['driver']], [$this->item(1)['status'], $this->item(1)['holder_user_id']]);
        $this->loginAs('washing');
        $this->assertError(403, 'forbidden', $this->api('POST', "/orders/$id/receive", $this->reads(1)));
        $this->loginAs('inventory');
        $this->assertError(409, 'handover_required', $this->api('POST', "/orders/$id/receive", $this->reads(1)));
        $this->loginAs('driver');
        $return = $this->ok($this->api('POST', "/orders/$id/return", ['receiver_id' => $this->users['inventory']]))['order']['return_handover_id'];
        $this->scanHandover('driver', $return, 1);
        $this->scanHandover('inventory', $return, 1);
        $this->assertSame('done', $this->ok($this->api('GET', "/orders/$id"))['order']['status']);
        $this->assertSame(['ready', 0], [$this->item(1)['status'], $this->item(1)['wash_count']]);
    }

    public function test_pickup_offers_only_this_customers_delivered_unreserved_mats_and_requires_reason(): void
    {
        $this->setItems([1, 2], 'at_customer', null, 1);
        $this->setItems([3], 'at_customer', null, 3);
        $this->loginAs('office');
        $available = $this->ok($this->api('GET', '/orders/available-items?type=pickup&customer_id=1&building_id=1'))['items'];
        $this->assertSame([$this->item(1)['id'], $this->item(2)['id']], array_column($available, 'id'));
        $this->assertError(422, 'invalid_input', $this->api('GET', '/orders/available-items?type=pickup'));
        $this->assertError(409, 'wrong_status', $this->api('POST', '/orders', $this->body('pickup', [3])));
        $this->assertError(409, 'wrong_status', $this->api('POST', '/orders', $this->body('pickup', [4])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/orders', $this->body('pickup', [1], ['return_reason' => null])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/orders', $this->body('pickup', [1], ['destination' => 'washing'])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/orders', $this->body('pickup', [1], ['washing_user_id' => $this->users['washing']])));
        $order = $this->order('pickup', [1]);
        $this->assertMatchesRegularExpression('/^PO-\d{8}-[A-F0-9]{12}$/', $order['number']);
        $this->assertSame('ready', $order['status']);
        $this->assertSame([$this->item(2)['id']], array_column($this->ok($this->api('GET', '/orders/available-items?type=pickup&customer_id=1'))['items'], 'id'));
        $this->loginAs('inventory');
        $this->assertError(403, 'forbidden', $this->api('GET', '/orders/available-items?type=pickup&customer_id=1'));
    }

    public function test_pickup_scan_gate_matches_delivery_and_refusal_leaves_mats_at_customer(): void
    {
        $id = $this->pickup();
        $this->ok($this->scan($id, 1));
        $this->assertError(422, 'scan_required', $this->complete($id));
        $this->assertError(422, 'not_on_order', $this->scan($id, 3));
        $this->ok($this->scan($id, 2));
        $done = $this->ok($this->complete($id, 'refused'))['order'];
        $this->assertSame('done', $done['status']);
        $this->assertSame(['at_customer', 'at_customer'], [$this->item(1)['status'], $this->item(2)['status']]);
        $this->assertSame([null, null], array_column($done['items'], 'reserved_item_id'));
    }

    public function test_inventory_pickup_receipt_is_exact_incremental_idempotent_and_never_washes(): void
    {
        $id = $this->collected();
        $this->assertSame(['collected', $this->users['driver'], null], [$this->item(1)['status'], $this->item(1)['holder_user_id'], $this->item(1)['zone_id']]);
        $this->loginAs('inventory');
        $this->assertError(422, 'not_on_order', $this->api('POST', "/orders/$id/receive", $this->reads(3)));
        $partial = $this->ok($this->api('POST', "/orders/$id/receive", $this->reads(1)))['order'];
        $this->assertSame('awaiting_receipt', $partial['status']);
        $this->assertSame(['ready', 'collected'], [$this->item(1)['status'], $this->item(2)['status']]);
        $count = count($this->events(1));
        $this->ok($this->api('POST', "/orders/$id/receive", $this->reads(1)));
        $this->assertCount($count, $this->events(1));
        $this->assertSame('done', $this->ok($this->api('POST', "/orders/$id/receive", $this->reads(2)))['order']['status']);
        $this->assertSame([0, 0], [$this->item(1)['wash_count'], $this->item(2)['wash_count']]);
        $this->loginAs('washing');
        $this->assertError(403, 'forbidden', $this->api('POST', "/orders/$id/wash", $this->reads(1) + ['receiver_id' => $this->users['inventory']]));
    }

    public function test_return_reason_controls_destination_and_free_text_note_is_optional(): void
    {
        $this->setItems([1], 'at_customer', null, 1);
        $this->loginAs('office');
        foreach ([['used', 'inventory'], ['unused_clean', 'washing'], ['damaged', 'washing']] as [$reason, $destination]) {
            $this->assertError(422, 'invalid_input', $this->api('POST', '/orders', $this->body('pickup', [1],
                ['return_reason' => $reason, 'destination' => $destination])));
        }
        $this->assertError(422, 'invalid_input', $this->api('POST', '/orders', $this->body('pickup', [1], ['return_reason' => 'other'])));
        $this->assertSame(0, DB::table('orders')->count());
        $order = $this->order('pickup', [1], ['return_reason' => 'unused_clean', 'reason' => null]);
        $this->assertSame(['unused_clean', 'inventory', null], [$order['return_reason'], $order['destination'], $order['reason']]);
    }

    public function test_damaged_pickup_receipt_requires_inventory_and_goes_to_repair(): void
    {
        $id = $this->collected('inventory', [1], 'damaged');
        $this->loginAs('washing');
        $this->assertError(403, 'forbidden', $this->api('POST', "/orders/$id/receive", $this->reads(1)));
        $this->loginAs('inventory');
        $order = $this->ok($this->api('POST', "/orders/$id/receive", $this->reads(1)))['order'];
        $this->assertSame(['done', 'damaged'], [$order['status'], $order['return_reason']]);
        $this->assertSame(['repair', 0, null, null],
            [$this->item(1)['status'], $this->item(1)['wash_count'], $this->item(1)['holder_user_id'], $this->item(1)['zone_id']]);
        $count = count($this->events(1));
        $this->ok($this->api('POST', "/orders/$id/receive", $this->reads(1)));
        $this->assertCount($count, $this->events(1));
    }

    public function test_washing_notification_is_scoped_to_recipient_and_exact_driver_receipt_required(): void
    {
        $hash = DB::table('users')->where('id', $this->users['washing'])->value('password_hash');
        $this->users['washing2'] = DB::table('users')->insertGetId(['name' => 'Other Washing', 'username' => 'washing2', 'role' => 'washing', 'password_hash' => $hash]);
        $id = $this->collected('washing');
        $this->loginAs('washing2');
        $this->assertSame([], $this->ok($this->api('GET', '/orders'))['orders']);
        $this->assertError(403, 'forbidden', $this->api('GET', "/orders/$id"));
        $this->assertError(403, 'forbidden', $this->api('POST', "/orders/$id/receive", $this->reads(1)));
        $this->assertError(403, 'forbidden', $this->api('POST', "/orders/$id/wash", $this->reads(1) + ['receiver_id' => $this->users['inventory']]));
        $this->loginAs('inventory');
        $this->assertError(403, 'forbidden', $this->api('POST', "/orders/$id/receive", $this->reads(1)));
        $this->loginAs('washing');
        $incoming = $this->ok($this->api('GET', '/orders'))['orders'];
        $this->assertWashingRedacted($incoming[0]);
        $this->assertWashingRedacted($this->ok($this->api('GET', "/orders/$id"))['order']);
        $this->assertSame([$id], array_column($incoming, 'id'));
        $this->assertSame('Driver', $incoming[0]['assigned_name']);
        $this->assertSame($this->epcs(1, 2), array_column($incoming[0]['items'], 'epc'));
        $this->assertError(409, 'wrong_status', $this->api('POST', "/orders/$id/wash", $this->reads(1) + ['receiver_id' => $this->users['inventory']]));
        $this->assertError(422, 'not_on_order', $this->api('POST', "/orders/$id/receive", $this->reads(3)));
        DB::table('items')->where('id', $this->item(1)['id'])->update(['holder_user_id' => $this->users['driver2']]);
        $this->assertError(409, 'wrong_status', $this->api('POST', "/orders/$id/receive", $this->reads(1)));
        $this->assertSame('collected', $this->item(1)['status']);
    }

    public function test_washing_receives_then_confirms_wash_and_returns_inventory_once(): void
    {
        $id = $this->collected('washing');
        $this->loginAs('washing');
        $received = $this->ok($this->api('POST', "/orders/$id/receive", $this->reads(1)))['order'];
        $this->assertWashingRedacted($received);
        $this->assertSame('awaiting_receipt', $received['status']);
        $this->assertSame(['in_washing', 'collected', 0], [$this->item(1)['status'], $this->item(2)['status'], $this->item(1)['wash_count']]);
        $this->assertSame('received', $this->ok($this->api('POST', "/orders/$id/receive", $this->reads(2)))['order']['status']);
        $this->assertError(422, 'not_on_order', $this->api('POST', "/orders/$id/wash", $this->reads(3) + ['receiver_id' => $this->users['inventory']]));
        $washed = $this->ok($this->api('POST', "/orders/$id/wash", $this->reads(1) + ['receiver_id' => $this->users['inventory']]))['order'];
        $this->assertWashingRedacted($washed);
        $this->assertSame('received', $washed['status']);
        $this->assertSame(['ready', 'in_washing', 1, today_nl()], [$this->item(1)['status'], $this->item(2)['status'], $this->item(1)['wash_count'], $this->item(1)['current_wash_date']]);
        $this->assertSame('done', $this->ok($this->api('POST', "/orders/$id/wash", $this->reads(1, 2) + ['receiver_id' => $this->users['inventory']]))['order']['status']);
        $count = count($this->events(1));
        $this->ok($this->api('POST', "/orders/$id/wash", $this->reads(1, 2) + ['receiver_id' => $this->users['inventory']]));
        $this->assertSame([1, 1], [$this->item(1)['wash_count'], $this->item(2)['wash_count']]);
        $this->assertCount($count, $this->events(1));
        $this->assertSame(0, DB::table('order_items')->where('order_id', $id)->whereNotNull('reserved_item_id')->count());
        $this->loginAs('office');
        $this->assertCount(40, $this->ok($this->api('GET', '/orders/available-items?type=delivery'))['items']);
    }

    public function test_legacy_qc_ready_correction_and_handover_cannot_change_reserved_mat(): void
    {
        $id = $this->order(mats: [1])['id'];
        $this->loginAs('inventory');
        $qc = $this->ok($this->api('POST', '/items/qc', ['source' => 'simulator', 'epc' => $this->epc(1), 'result' => 'pass']));
        $this->assertSame('rejected', $qc['results'][0]['result']);
        $this->loginAs('office');
        $this->assertSame('rejected', $this->ok($this->api('POST', '/items/ready', $this->reads(1)))['results'][0]['result']);
        $this->assertError(409, 'reserved', $this->api('POST', '/items/'.$this->item(1)['id'].'/correct', ['status' => 'repair', 'note' => 'Bypass attempt']));
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->assertSame('rejected', $this->scanHandover('inventory', $handover, 1)['results'][0]['result']);
        $this->assertSame('ready', $this->item(1)['status']);
        $this->assertCount(0, $this->events(1));
        $this->assertSame('pending_qc', DB::table('orders')->where('id', $id)->value('status'));
    }

    public function test_legacy_task_cannot_deliver_order_reserved_mat(): void
    {
        [$task] = $this->activateContract(1, 'driver');
        $this->ok($this->api('POST', '/contracts/1/end'));
        $id = $this->dispatch([1]);
        $this->assertError(409, 'reserved', $this->api('POST', "/tasks/$task/complete",
            ['source' => 'simulator', 'drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1)]],
                'pickups' => [], 'refused' => [], 'signed_name' => 'Contact', 'issue_note' => 'Bypass attempt'],
            ['photo' => $this->proofFile('jpeg'), 'signature' => $this->proofFile('png')]));
        $this->assertSame('out_for_delivery', $this->item(1)['status']);
        $this->assertSame('in_progress', DB::table('orders')->where('id', $id)->value('status'));
    }
}
