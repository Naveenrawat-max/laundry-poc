<?php
/** Handovers: one person passes a pile to another. A mat moves when both have scanned it (Handover C: when the giver has). */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class HandoverController extends Controller
{
    // Per type: who may give, which role receives, the status a mat must have, the status it gets,
    // whether the receiver's scan is needed, and whether the move counts as a wash.
    // A wash is counted once: when the washing house manager scans the washed mat out (Handover C).
    public const TYPES = [
        'inventory_to_driver' => ['giver' => ['inventory', 'outbound'], 'receiver' => 'driver',
            'from' => 'qc_passed', 'to' => 'out_for_delivery', 'two_sided' => true, 'wash' => false],
        'driver_to_washing' => ['giver' => ['driver'], 'receiver' => 'washing',
            'from' => 'collected', 'to' => 'in_washing', 'two_sided' => true, 'wash' => false],
        'washing_to_inventory' => ['giver' => ['washing'], 'receiver' => 'inventory',
            'from' => 'in_washing', 'to' => 'ready', 'two_sided' => false, 'wash' => true],
        'driver_to_inventory' => ['giver' => ['driver'], 'receiver' => 'inventory',
            'from' => 'refused', 'to' => 'registered', 'two_sided' => true, 'wash' => false],
        'inventory_to_washing' => ['giver' => ['inventory'], 'receiver' => 'washing',
            'from' => 'rewash', 'to' => 'in_washing', 'two_sided' => true, 'wash' => false],
    ];

    // Optional receipts remain available only while this is the mat's latest, unclaimed placement.
    private const RECEIVABLE = "h.type = 'washing_to_inventory' AND hi.given_at IS NOT NULL AND hi.received_at IS NULL
        AND i.status = 'ready' AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.reserved_item_id = i.id)
        AND (SELECT e.handover_id FROM item_events e WHERE e.item_id = i.id ORDER BY e.id DESC LIMIT 1) = h.id";
    private const PENDING_RECEIPT = 'EXISTS (SELECT 1 FROM handover_items hi JOIN items i ON i.id = hi.item_id
        WHERE hi.handover_id = h.id AND ' . self::RECEIVABLE . ')';

    /** One handover with its two people and its pile. 404 when unknown. */
    private static function view(int $id): array
    {
        $handover = row('SELECT h.*, (' . self::PENDING_RECEIPT . ') AS pending_receipt,
            g.name AS giver_name, r.name AS receiver_name, o.id AS order_id, o.number AS order_number
            FROM handovers h JOIN users g ON g.id = h.giver_id JOIN users r ON r.id = h.receiver_id
            LEFT JOIN orders o ON o.dispatch_handover_id = h.id OR o.return_handover_id = h.id
            WHERE h.id = ?', [$id]);
        if ($handover === null) {
            fail(404, 'not_found', 'No such handover.');
        }
        if ($handover['order_id'] !== null && $handover['type'] === 'driver_to_inventory') {
            // Expected mats come from the order; scan rows exist only after a real participant scan.
            $items = rows("SELECT i.*, t.name AS type_name, hi.given_at, hi.received_at, hi.resolved_at
                FROM order_items oi JOIN items i ON i.id = oi.item_id JOIN item_types t ON t.id = i.item_type_id
                LEFT JOIN handover_items hi ON hi.item_id = i.id AND hi.handover_id = ?
                WHERE oi.order_id = ? AND oi.replaced_at IS NULL AND oi.customer_response = 'refused' ORDER BY i.sku",
                [$id, $handover['order_id']]);
        } else {
            $items = rows('SELECT i.*, t.name AS type_name, hi.given_at, hi.received_at, hi.resolved_at,
                (' . self::RECEIVABLE . ') AS receivable
                FROM handover_items hi JOIN handovers h ON h.id = hi.handover_id
                JOIN items i ON i.id = hi.item_id JOIN item_types t ON t.id = i.item_type_id
                WHERE hi.handover_id = ? ORDER BY i.sku', [$id]);
        }
        return [
            'id' => $handover['id'],
            'type' => $handover['type'],
            'status' => $handover['status'],
            'pending_receipt' => (bool) $handover['pending_receipt'],
            'created_at' => $handover['created_at'],
            'closed_at' => $handover['closed_at'],
            'order_id' => $handover['order_id'],
            'order_number' => $handover['order_number'],
            'giver' => ['id' => $handover['giver_id'], 'name' => $handover['giver_name']],
            'receiver' => ['id' => $handover['receiver_id'], 'name' => $handover['receiver_name']],
            'items' => array_map(fn (array $item) => Scan::view($item)
                + ['given_at' => $item['given_at'], 'received_at' => $item['received_at'], 'resolved_at' => $item['resolved_at'],
                    'receivable' => (bool) ($item['receivable'] ?? false)], $items),
        ];
    }

    /** The caller opens a handover as giver. */
    public function open(Request $request): array
    {
        $in = $request->validate([
            'type' => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'receiver_id' => 'required|integer|min:0',
        ]);
        $type = $in['type'];
        $receiverId = (int) $in['receiver_id'];
        $user = $request->user();
        $rule = self::TYPES[$type];
        if (!in_array($user->role, $rule['giver'], true)) {
            fail(403, 'forbidden', "Your role cannot give in a $type handover.");
        }
        if (DB::scalar('SELECT 1 FROM users WHERE id = ? AND role = ? AND is_active = 1', [$receiverId, $rule['receiver']]) === null) {
            fail(422, 'invalid_reference', "The receiver must be an active {$rule['receiver']} user.");
        }
        return DB::transaction(fn (): array => ['handover' => self::view(self::openFor($type, (int) $user->id, $receiverId))], 3);
    }

    /** Reuse an open pile, except when a requested mat already completed an earlier wash in that pile. */
    private static function openFor(string $type, int $giverId, int $receiverId, array $freshItems = []): int
    {
        $fresh = $freshItems === [] ? '' : ' AND NOT EXISTS (SELECT 1 FROM handover_items hi
            WHERE hi.handover_id = handovers.id AND hi.given_at IS NOT NULL AND hi.item_id IN (' . marks($freshItems) . '))';
        $open = row("SELECT id FROM handovers WHERE type = ? AND giver_id = ? AND receiver_id = ? AND status = 'open'
            AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.dispatch_handover_id = handovers.id OR o.return_handover_id = handovers.id)"
            . $fresh . ' ORDER BY id DESC LIMIT 1 FOR UPDATE', [$type, $giverId, $receiverId, ...$freshItems]);
        if ($open !== null) {
            return (int) $open['id'];
        }
        DB::insert('INSERT INTO handovers (type, giver_id, receiver_id) VALUES (?, ?, ?)', [$type, $giverId, $receiverId]);
        return (int) DB::getPdo()->lastInsertId();
    }

    /** Order and menu wash-outs use the same handover movement and reservation release. Caller owns the transaction. */
    public static function washOrder(Request $request, array $order, array $epcs, int $receiverId, string $source): ?int
    {
        if (DB::scalar("SELECT 1 FROM users WHERE id = ? AND role = 'inventory' AND is_active = 1", [$receiverId]) === null) {
            fail(422, 'invalid_reference', 'Choose an active inventory receiver.');
        }
        $lines = array_column(rows('SELECT * FROM order_items WHERE order_id = ? AND replaced_at IS NULL FOR UPDATE', [$order['id']]), null, 'item_id');
        $items = Scan::itemsByEpc($epcs, true);
        $pending = [];
        foreach ($epcs as $epc) {
            $item = $items[$epc] ?? null;
            $line = $item === null ? null : ($lines[$item['id']] ?? null);
            if ($line === null) {
                fail(422, 'not_on_order', 'Scan an exact mat on this order.');
            }
            if ($line['received_at'] !== null && $line['reserved_item_id'] === null) {
                continue;
            }
            if ($line['received_at'] === null || $item['status'] !== 'in_washing') {
                fail(409, 'wrong_status', 'Receive this exact mat before confirming its wash.');
            }
            $pending[] = $item;
        }
        if ($pending === []) {
            return null;
        }
        $id = self::openFor('washing_to_inventory', (int) $request->user()->id, $receiverId, array_column($pending, 'id'));
        $handover = row('SELECT * FROM handovers WHERE id = ? FOR UPDATE', [$id]);
        $handoverLines = array_column(rows('SELECT * FROM handover_items WHERE handover_id = ?', [$id]), null, 'item_id');
        foreach ($pending as $item) {
            $result = self::give($handover, $item, $handoverLines[$item['id']] ?? null, $request->user(), $source);
            if ($result['result'] !== 'found') {
                fail(409, 'wrong_status', $result['reason'] ?? 'This mat cannot be handed over.');
            }
        }
        return $id;
    }

    /** One scan into a handover. Who calls decides whether it counts as giving or as receiving. */
    public function scan(Request $request, int $id): array
    {
        $source = Scan::source($request->all());
        $epcs = Scan::reads($request->all());
        $user = $request->user();
        return DB::transaction(function () use ($source, $epcs, $user, $id): array {
            $handover = row('SELECT * FROM handovers WHERE id = ? FOR UPDATE', [$id]);
            if ($handover === null) {
                fail(404, 'not_found', 'No such handover.');
            }
            $side = match ($user->id) {
                $handover['giver_id'] => 'give',
                $handover['receiver_id'] => 'receive',
                default => fail(403, 'forbidden', 'This is not your handover.'),
            };
            if ($handover['status'] !== 'open' && !($side === 'receive' && $handover['type'] === 'washing_to_inventory')) {
                fail(409, 'closed', 'This handover is closed.');
            }
            if ($handover['status'] !== 'open' && ($user->role !== 'inventory' || !$user->is_active)) {
                fail(403, 'forbidden', 'Only the active inventory receiver can acknowledge this closed washing handover.');
            }
            $order = row('SELECT * FROM orders WHERE dispatch_handover_id = ? OR return_handover_id = ? FOR UPDATE', [$id, $id]);
            if ($order !== null) {
                return self::orderScan($handover, $order, $side, $epcs, $user, $source);
            }

            $items = Scan::itemsByEpc($epcs, true);
            $lines = $items === [] ? [] : array_column(
                rows('SELECT * FROM handover_items WHERE handover_id = ? AND item_id IN (' . marks($items) . ')',
                    [$id, ...array_column($items, 'id')]),
                null, 'item_id');

            $results = [];
            foreach ($epcs as $epc) {
                $item = $items[$epc] ?? null;
                if ($item === null) {
                    $results[] = Scan::result($epc, 'unknown', null);
                    continue;
                }
                $line = $lines[$item['id']] ?? null;
                if ($handover['status'] !== 'open' && ($line === null || $line['given_at'] === null)) {
                    $results[] = Scan::result($epc, 'rejected', $item, 'Only previously given mats can be received after closing.');
                    continue;
                }
                if (Scan::reserved((int) $item['id']) && !($side === 'give' && $handover['type'] === 'washing_to_inventory')) {
                    $results[] = Scan::result($epc, 'rejected', $item, 'Use the reserved order workflow for this mat.');
                    continue;
                }
                $results[] = $side === 'give'
                    ? self::give($handover, $item, $line, $user, $source)
                    : self::receive($handover, $item, $line, $user, $source);
            }

            // The receiver sees which mats the giver scanned and he has not.
            $missing = $side !== 'receive' ? [] : array_map(Scan::view(...), rows(Scan::ITEM_SELECT
                . ' JOIN handover_items hi ON hi.item_id = i.id
                    JOIN handovers h ON h.id = hi.handover_id
                    WHERE hi.handover_id = ? AND hi.given_at IS NOT NULL AND hi.received_at IS NULL'
                . ($handover['type'] === 'washing_to_inventory' ? ' AND (' . self::RECEIVABLE . ')' : '')
                . ' ORDER BY i.sku', [$id]));
            return ['results' => $results, 'missing' => $missing];
        }, 3);
    }

    /** Linked orders accept only their locked exact list; both participants must scan before custody moves. */
    private static function orderScan(array $handover, array $order, string $side, array $epcs, User $user, string $source): array
    {
        $dispatch = (int) $order['dispatch_handover_id'] === (int) $handover['id'];
        $expectedType = $dispatch ? 'inventory_to_driver' : 'driver_to_inventory';
        $driver = $dispatch ? $handover['receiver_id'] : $handover['giver_id'];
        $inventory = $dispatch ? $handover['giver_id'] : $handover['receiver_id'];
        if ($handover['type'] !== $expectedType || (int) $driver !== (int) $order['assigned_to']
            || DB::scalar("SELECT 1 FROM users WHERE id = ? AND role = 'driver' AND is_active = 1", [$driver]) === null
            || DB::scalar("SELECT 1 FROM users WHERE id = ? AND role = 'inventory' AND is_active = 1", [$inventory]) === null) {
            fail(403, 'forbidden', 'This order handover must stay between its assigned driver and inventory receiver.');
        }
        if ($order['type'] !== 'delivery' || !in_array($order['status'], $dispatch ? ['ready', 'in_progress'] : ['awaiting_receipt', 'done'], true)) {
            fail(409, 'wrong_status', 'This order is not ready for this handover.');
        }
        $orderLines = array_column(rows('SELECT * FROM order_items WHERE order_id = ? AND replaced_at IS NULL'
            . ($dispatch ? '' : " AND customer_response = 'refused'"), [$order['id']]), null, 'item_id');
        $items = Scan::itemsByEpc($epcs, true);
        $handoverLines = array_column(rows('SELECT * FROM handover_items WHERE handover_id = ?', [$handover['id']]), null, 'item_id');
        $results = [];
        foreach ($epcs as $epc) {
            $item = $items[$epc] ?? null;
            $line = $item === null ? null : ($orderLines[$item['id']] ?? null);
            $hi = $item === null ? null : ($handoverLines[$item['id']] ?? null);
            if ($line === null || ($dispatch && $hi === null)) {
                $results[] = Scan::result($epc, 'rejected', null, 'This exact mat is not on this order handover.');
                continue;
            }
            if ($hi !== null && $hi['given_at'] !== null && $hi['received_at'] !== null) {
                $results[] = Scan::result($epc, 'found', $item);
                continue;
            }
            $valid = (int) $line['reserved_item_id'] === (int) $item['id']
                && ($dispatch ? $line['qc_status'] === 'passed' && $item['status'] === 'qc_passed'
                    : ($line['refusal_scanned_at'] !== null || ($order['task_id'] === null && $order['dispatch_handover_id'] === null && $order['customer_response'] === 'refused'))
                        && $item['status'] === 'refused' && (int) $item['holder_user_id'] === (int) $driver);
            if (!$valid) {
                $results[] = Scan::result($epc, 'rejected', $item, 'This mat no longer matches the order custody or quality check.');
                continue;
            }
            $result = $side === 'give' ? self::give($handover, $item, $hi, $user, $source)
                : self::receive($handover, $item, $hi, $user, $source);
            // Return lines are preselected, so a receiver-first scan is still on the exact list.
            if ($result['result'] === 'not_on_list') {
                $result['result'] = 'found';
            }
            $results[] = $result;
        }
        $completed = rows('SELECT oi.id FROM order_items oi JOIN handover_items hi ON hi.item_id = oi.item_id AND hi.handover_id = ?
            WHERE oi.order_id = ? AND oi.replaced_at IS NULL AND hi.given_at IS NOT NULL AND hi.received_at IS NOT NULL'
            . ($dispatch ? '' : " AND oi.customer_response = 'refused'"), [$handover['id'], $order['id']]);
        if (!$dispatch) {
            foreach ($completed as $line) {
                DB::update('UPDATE order_items SET received_at = COALESCE(received_at,UTC_TIMESTAMP()), reserved_item_id = NULL WHERE id = ?', [$line['id']]);
            }
        }
        if ($orderLines !== [] && count($completed) === count($orderLines)) {
            if ($dispatch) {
                DB::update("UPDATE orders SET status = 'in_progress', dispatched_at = COALESCE(dispatched_at,UTC_TIMESTAMP()) WHERE id = ?", [$order['id']]);
            } else {
                DB::update("UPDATE orders SET status = 'done', received_at = COALESCE(received_at,UTC_TIMESTAMP()), completed_at = COALESCE(completed_at,UTC_TIMESTAMP()) WHERE id = ?", [$order['id']]);
            }
            // Order handovers have no manual close. Leave this open and the delivered mats stay blocked from pickup.
            DB::update("UPDATE handovers SET status = 'closed', closed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'open'", [$handover['id']]);
        }
        $missing = array_map(Scan::view(...), rows(Scan::ITEM_SELECT . ' JOIN order_items oi ON oi.item_id = i.id
            LEFT JOIN handover_items hi ON hi.item_id = i.id AND hi.handover_id = ?
            WHERE oi.order_id = ? AND oi.replaced_at IS NULL AND (hi.given_at IS NULL OR hi.received_at IS NULL)'
            . ($dispatch ? '' : " AND oi.customer_response = 'refused'") . ' ORDER BY i.sku', [$handover['id'], $order['id']]));

        return ['results' => $results, 'missing' => $missing];
    }

    /** The giver scans one mat. $line is the mat's row in this handover, or null when it has none yet. */
    private static function give(array $handover, array $item, ?array $line, User $user, string $source): array
    {
        $rule = self::TYPES[$handover['type']];
        $epc = $item['epc'];
        $washingOrder = $handover['type'] !== 'washing_to_inventory' ? null : row('SELECT oi.id, oi.order_id,
            oi.received_at, oi.replaced_at, o.type, o.destination, o.status, o.washing_user_id
            FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.reserved_item_id = ? FOR UPDATE', [$item['id']]);
        if ($washingOrder !== null && ($user->role !== 'washing' || $washingOrder['type'] !== 'pickup'
            || $washingOrder['destination'] !== 'washing' || !in_array($washingOrder['status'], ['received', 'done'], true)
            || (int) $washingOrder['washing_user_id'] !== (int) $user->id
            || $washingOrder['received_at'] === null || $washingOrder['replaced_at'] !== null)) {
            return Scan::result($epc, 'rejected', $item, 'Only the assigned washing receiver can hand over this received pickup mat.');
        }
        if ($line !== null && $line['given_at'] !== null) {
            if ($washingOrder !== null) {
                return Scan::result($epc, 'rejected', $item, 'This mat was given in an earlier wash. Start a new handover.');
            }
            return Scan::result($epc, 'found', $item);   // a second scan: nothing to change
        }
        if ($item['status'] !== $rule['from']) {
            return Scan::result($epc, 'rejected', $item, "{$item['sku']} is {$item['status']}, not {$rule['from']}.");
        }
        if ($user->role === 'driver' && $item['holder_user_id'] !== $user->id) {
            return Scan::result($epc, 'rejected', $item, "{$item['sku']} is not on your van.");
        }
        // "In another handover" means: given there and still waiting for that receiver's scan.
        $elsewhere = DB::scalar("SELECT 1 FROM handover_items hi JOIN handovers h ON h.id = hi.handover_id
            WHERE hi.item_id = ? AND hi.given_at IS NOT NULL AND hi.received_at IS NULL
              AND h.status = 'open' AND h.type <> 'washing_to_inventory' AND h.id <> ? LIMIT 1",
            [$item['id'], $handover['id']]);
        if ($elsewhere !== null) {
            return Scan::result($epc, 'rejected', $item, "{$item['sku']} is already in another open handover.");
        }

        DB::insert('INSERT INTO handover_items (handover_id, item_id, given_at) VALUES (?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE given_at = UTC_TIMESTAMP()', [$handover['id'], $item['id']]);
        $receivedAlready = $line !== null && $line['received_at'] !== null;
        $after = !$rule['two_sided'] || $receivedAlready ? self::move($handover, $item) + $item : $item;
        Scan::log($item, $user->id, 'handover_give', $after['status'], $source, ['handover_id' => $handover['id']]);
        if ($washingOrder !== null && $after['status'] === 'ready') {
            DB::update('UPDATE order_items SET reserved_item_id = NULL WHERE id = ?', [$washingOrder['id']]);
            if (DB::scalar('SELECT 1 FROM order_items WHERE order_id = ? AND reserved_item_id IS NOT NULL LIMIT 1', [$washingOrder['order_id']]) === null) {
                DB::update("UPDATE orders SET status = 'done', completed_at = COALESCE(completed_at,UTC_TIMESTAMP()) WHERE id = ?", [$washingOrder['order_id']]);
            }
        }
        return Scan::result($epc, 'found', $after);
    }

    /** The receiver scans one mat. */
    private static function receive(array $handover, array $item, ?array $line, User $user, string $source): array
    {
        $epc = $item['epc'];
        $given = $line !== null && $line['given_at'] !== null;
        $result = $given ? 'found' : 'not_on_list';
        if ($line !== null && $line['received_at'] !== null) {
            return Scan::result($epc, $result, $item);   // a second scan: nothing to change
        }
        if ($handover['type'] === 'washing_to_inventory' && $given
            && ($item['status'] !== 'ready' || (int) DB::scalar('SELECT handover_id FROM item_events WHERE item_id = ? ORDER BY id DESC LIMIT 1', [$item['id']]) !== (int) $handover['id'])) {
            return Scan::result($epc, 'rejected', $item, 'This mat has moved on from this washing handover.');
        }

        // A mat the giver did not scan is still recorded: it shows up as a handover issue for the office.
        DB::insert('INSERT INTO handover_items (handover_id, item_id, received_at) VALUES (?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE received_at = UTC_TIMESTAMP()', [$handover['id'], $item['id']]);
        $after = $given && self::TYPES[$handover['type']]['two_sided'] ? self::move($handover, $item) + $item : $item;
        Scan::log($item, $user->id, 'handover_receive', $after['status'], $source, ['handover_id' => $handover['id']]);
        return Scan::result($epc, $result, $after);
    }

    /**
     * Moves one mat to the status its handover type gives it, only if it still has the status the type expects.
     * Returns the fields that changed, or nothing when the mat did not move.
     * This "set new where still old" in one query is what makes a second scan harmless. Never load, change and save.
     */
    private static function move(array $handover, array $item): array
    {
        $rule = self::TYPES[$handover['type']];
        $holder = $rule['to'] === 'out_for_delivery' ? $handover['receiver_id'] : null;
        $today = today_nl();
        // Keep this order: MySQL and MariaDB assign left to right, so previous_wash_date gets the old current date.
        $wash = $rule['wash'] ? ', previous_wash_date = current_wash_date, current_wash_date = ?, wash_count = wash_count + 1' : '';
        $params = [$rule['to'], $holder, ...($rule['wash'] ? [$today] : []), $item['id'], $rule['from']];
        $moved = DB::update("UPDATE items SET status = ?, holder_user_id = ?$wash WHERE id = ? AND status = ?", $params);
        if ($moved !== 1) {
            return [];
        }
        $changed = ['status' => $rule['to'], 'holder_user_id' => $holder];
        if ($rule['wash']) {
            $changed += ['wash_count' => $item['wash_count'] + 1, 'current_wash_date' => $today,
                'previous_wash_date' => $item['current_wash_date']];
        }
        return $changed;
    }

    // A mat in a closed handover with only one of its two scans, not handled yet by the office.
    // In Handover C the receiver's scan is optional, so "given only" is normal there.
    private const ISSUE = "h.status = 'closed' AND hi.resolved_at IS NULL
        AND (hi.given_at IS NULL OR (hi.received_at IS NULL AND h.type <> 'washing_to_inventory'))";

    public function close(Request $request, int $id): array
    {
        $user = $request->user();
        return DB::transaction(function () use ($user, $id): array {
            $handover = row('SELECT * FROM handovers WHERE id = ? FOR UPDATE', [$id]);
            if ($handover === null) {
                fail(404, 'not_found', 'No such handover.');
            }
            // The receiver says "done". In Handover C the receiver may never show up, so the giver may close too.
            $mayClose = self::TYPES[$handover['type']]['two_sided']
                ? [$handover['receiver_id']]
                : [$handover['giver_id'], $handover['receiver_id']];
            if ($user->role !== 'office' && !in_array($user->id, $mayClose, true)) {
                fail(403, 'forbidden', 'Only the receiver closes this handover.');
            }
            if ($handover['status'] !== 'open') {
                fail(409, 'closed', 'This handover is already closed.');
            }
            $order = row('SELECT id,dispatch_handover_id FROM orders WHERE dispatch_handover_id = ? OR return_handover_id = ? FOR UPDATE', [$id, $id]);
            if ($order !== null) {
                $missing = DB::scalar('SELECT 1 FROM order_items oi LEFT JOIN handover_items hi ON hi.item_id = oi.item_id AND hi.handover_id = ?
                    WHERE oi.order_id = ? AND oi.replaced_at IS NULL AND (hi.given_at IS NULL OR hi.received_at IS NULL)'
                    . ((int) $order['dispatch_handover_id'] === $id ? '' : " AND oi.customer_response = 'refused'") . ' LIMIT 1', [$id, $order['id']]);
                if ($missing !== null) {
                    fail(409, 'incomplete_handover', 'Both sides must scan every exact order mat before closing this handover.');
                }
            }
            DB::update("UPDATE handovers SET status = 'closed', closed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'open'", [$id]);
            return ['handover' => self::view($id)];
        });
    }

    public function show(Request $request, int $id): array
    {
        $user = $request->user();
        $handover = self::view($id);
        $mine = in_array($user->id, [$handover['giver']['id'], $handover['receiver']['id']], true);
        if ($user->role !== 'office' && !$mine) {
            fail(403, 'forbidden', 'This is not your handover.');
        }
        return ['handover' => $handover];
    }

    public function index(Request $request): array
    {
        $user = $request->user();
        $office = $user->role === 'office';
        if ($office && $request->query('issues') === '1') {
            return ['issues' => rows('SELECT h.id AS handover_id, h.type, h.closed_at, g.name AS giver_name, r.name AS receiver_name,
                    hi.given_at, hi.received_at, i.id AS item_id, i.sku, t.name AS type_name, i.status
                FROM handover_items hi
                JOIN handovers h ON h.id = hi.handover_id
                JOIN items i ON i.id = hi.item_id
                JOIN item_types t ON t.id = i.item_type_id
                JOIN users g ON g.id = h.giver_id
                JOIN users r ON r.id = h.receiver_id
                WHERE ' . self::ISSUE . ' ORDER BY h.closed_at DESC, i.sku')];
        }
        $where = $office ? '1 = 1' : '(h.giver_id = ? OR h.receiver_id = ?)';
        $params = $office ? [] : [$user->id, $user->id];
        // ponytail: the newest 100, no paging. Add paging when the office needs to look further back.
        $handovers = rows("SELECT h.id, h.type, h.status, h.created_at, h.closed_at, o.id AS order_id, o.number AS order_number,
                h.giver_id, g.name AS giver_name, h.receiver_id, r.name AS receiver_name,
                (" . self::PENDING_RECEIPT . ") AS pending_receipt,
                (SELECT COUNT(*) FROM handover_items hi WHERE hi.handover_id = h.id AND hi.given_at IS NOT NULL) AS given,
                (SELECT COUNT(*) FROM handover_items hi WHERE hi.handover_id = h.id AND hi.received_at IS NOT NULL) AS received
            FROM handovers h JOIN users g ON g.id = h.giver_id JOIN users r ON r.id = h.receiver_id
            LEFT JOIN orders o ON o.dispatch_handover_id = h.id OR o.return_handover_id = h.id
            WHERE $where ORDER BY (h.status = 'open' OR pending_receipt) DESC, h.id DESC LIMIT 100", $params);
        return ['handovers' => array_map(fn (array $handover) => array_replace($handover,
            ['pending_receipt' => (bool) $handover['pending_receipt']]), $handovers)];
    }

    /** The office marks one handover issue as handled, with a note. */
    public function resolve(Request $request, int $id, int $itemId): array
    {
        $in = $request->validate(['note' => 'required|string|max:500']);
        $resolved = DB::update('UPDATE handover_items hi JOIN handovers h ON h.id = hi.handover_id
            SET hi.resolved_at = UTC_TIMESTAMP(), hi.resolved_by = ?, hi.resolution_note = ?
            WHERE hi.handover_id = ? AND hi.item_id = ? AND ' . self::ISSUE,
            [$request->user()->id, $in['note'], $id, $itemId]);
        if ($resolved !== 1) {
            fail(409, 'not_an_issue', 'This mat is not an open handover issue.');
        }
        return ['ok' => true];
    }

    /** The people a giver can pick as receiver for one handover type. */
    public function receivers(Request $request): array
    {
        $in = $request->validate(['type' => 'required|in:' . implode(',', array_keys(self::TYPES))]);
        $rule = self::TYPES[$in['type']];
        if (!in_array($request->user()->role, $rule['giver'], true)) {
            fail(403, 'forbidden', 'Your role cannot give in this handover.');
        }
        return ['rows' => rows('SELECT id, name FROM users WHERE role = ? AND is_active = 1 ORDER BY name', [$rule['receiver']])];
    }
}
