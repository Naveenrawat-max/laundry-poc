<?php

/** Office orders reserve exact mats through quality checks, driver proof, receipt and washing. */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Scan;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class OrderController extends Controller
{
    private const SELECT = 'SELECT o.*, c.name AS customer_name, b.name AS building_name, b.street, b.postcode, b.city,
        u.name AS assigned_name, w.name AS washing_name, ct.start_date AS contract_start_date, ct.swap_interval_days,
        (SELECT MIN(t.due_date) FROM tasks t WHERE t.contract_id = o.contract_id AND t.building_id = o.building_id
            AND t.type = \'swap\' AND t.status = \'open\') AS next_swap_date FROM orders o
        JOIN customers c ON c.id = o.customer_id JOIN buildings b ON b.id = o.building_id
        JOIN users u ON u.id = o.assigned_to LEFT JOIN users w ON w.id = o.washing_user_id
        LEFT JOIN contracts ct ON ct.id = o.contract_id';

    private static function view(Request $request, int $id): array
    {
        $order = row(self::SELECT.' WHERE o.id = ?', [$id]);
        if ($order === null) {
            fail(404, 'not_found', 'No such order.');
        }
        unset($order['photo_path'], $order['signature_path']);
        $order['items'] = rows('SELECT oi.*, i.sku, i.epc, i.item_type_id, i.catalog_sku_id, i.status,
            CASE WHEN o.type = \'pickup\' AND o.status NOT IN (\'done\',\'cancelled\') AND oi.received_at IS NULL THEN i.delivered_on ELSE NULL END AS delivered_on,
            CASE WHEN o.type = \'pickup\' AND o.status NOT IN (\'done\',\'cancelled\') AND oi.received_at IS NULL THEN i.swap_due_date ELSE NULL END AS swap_due_date,
            t.name AS type_name, s.code AS catalog_sku_code, z.name AS zone_name
            FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN items i ON i.id = oi.item_id JOIN item_types t ON t.id = i.item_type_id
            LEFT JOIN sku_catalog s ON s.id = i.catalog_sku_id JOIN zones z ON z.id = oi.zone_id
            WHERE oi.order_id = ? ORDER BY oi.id', [$id]);
        if ($order['type'] === 'delivery' && $order['customer_response'] === 'refused') {
            foreach ($order['items'] as &$line) {
                // Historical whole-order refusals predate line outcomes; retain their recorded summary.
                $line['customer_response'] ??= 'refused';
            }
            unset($line);
        }
        $current = array_filter($order['items'], fn (array $line) => $line['replaced_at'] === null);
        $refused = array_filter($current, fn (array $line) => $line['customer_response'] === 'refused');
        $order['refused_count'] = count($refused);
        $order['return_pending_count'] = $order['type'] === 'delivery'
            ? count(array_filter($refused, fn (array $line) => $line['received_at'] === null)) : 0;
        if ($request->user()->role === 'washing') {
            // Washing receives mats from a driver; customer and proof details stay in the office.
            $order = array_diff_key($order, array_flip(['customer_id', 'customer_name', 'building_id', 'building_name',
                'street', 'postcode', 'city', 'signed_name', 'customer_response', 'reason', 'contract_id', 'task_id', 'refused_count', 'return_pending_count',
                'contract_start_date', 'swap_interval_days', 'next_swap_date']));
            $hidden = array_flip(['zone_id', 'zone_name', 'qc_note', 'current_zone_id', 'customer_response', 'refusal_scanned_at',
                'delivered_on', 'swap_due_date']);
            $order['items'] = array_map(fn (array $line) => array_diff_key($line, $hidden), $order['items']);
        }

        return $order;
    }

    private static function authorize(Request $request, array $order): void
    {
        $user = $request->user();
        $allowed = match ($user->role) {
            'office', 'inventory' => true,
            'driver' => (int) $order['assigned_to'] === (int) $user->id,
            'washing' => (int) $order['washing_user_id'] === (int) $user->id,
            default => false,
        };
        if (! $allowed) {
            fail(403, 'forbidden', 'This order is not assigned to you.');
        }
    }

    private static function locked(Request $request, int $id): array
    {
        $order = row('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$id]);
        if ($order === null) {
            fail(404, 'not_found', 'No such order.');
        }
        self::authorize($request, $order);

        return $order;
    }

    private static function lines(int $id): array
    {
        return rows('SELECT oi.*, i.epc, i.sku, i.status, i.item_type_id, i.catalog_sku_id, i.holder_user_id,
            i.zone_id AS current_zone_id, i.wash_count, i.current_wash_date, i.previous_wash_date
            FROM order_items oi JOIN items i ON i.id = oi.item_id
            WHERE oi.order_id = ? AND oi.replaced_at IS NULL ORDER BY i.id FOR UPDATE', [$id]);
    }

    private static function state(array $order, array $allowed): void
    {
        if (! in_array($order['status'], $allowed, true)) {
            fail(409, 'wrong_status', 'This order is '.$order['status'].'.');
        }
    }

    private static function move(array $line, Request $request, string $to, ?int $holder, ?int $zone, string $event, string $source, array $extra = []): void
    {
        DB::update('UPDATE items SET status = ?, holder_user_id = ?, zone_id = ? WHERE id = ?', [$to, $holder, $zone, $line['item_id']]);
        Scan::log(['id' => $line['item_id']] + $line, (int) $request->user()->id, $event, $to, $source,
            $extra + ['note' => 'Office order '.$line['order_id'], 'zone_id' => $zone ?? $line['current_zone_id']]);
    }

    public function index(Request $request): array
    {
        $user = $request->user();
        $where = match ($user->role) {
            'office', 'inventory' => '1 = 1',
            'driver' => 'o.assigned_to = ?',
            'washing' => 'o.washing_user_id = ?',
            default => '1 = 0',
        };
        $params = in_array($user->role, ['driver', 'washing'], true) ? [$user->id] : [];
        // shortcut: newest 200 orders; add pagination when office history exceeds one screen.
        $ids = rows('SELECT o.id FROM orders o WHERE '.$where." ORDER BY o.status NOT IN ('done','cancelled') DESC, o.id DESC LIMIT 200", $params);

        return ['orders' => array_map(fn ($order) => self::view($request, (int) $order['id']), $ids)];
    }

    public function show(Request $request, int $id): array
    {
        $order = self::view($request, $id);
        self::authorize($request, $order);

        return ['order' => $order];
    }

    public function available(Request $request): array
    {
        $in = $request->validate(['type' => 'required|in:delivery,pickup', 'customer_id' => 'nullable|integer|min:1', 'building_id' => 'nullable|integer|min:1']);
        $where = "i.status IN ('ready','qc_passed')";
        $params = [];
        if ($in['type'] === 'pickup') {
            if ($request->user()->role !== 'office') {
                fail(403, 'forbidden', 'Only the office selects customer pickup mats.');
            }
            if (! isset($in['customer_id'])) {
                fail(422, 'invalid_input', 'Select the pickup customer.');
            }
            $where = "i.status = 'at_customer' AND b.customer_id = ?";
            $params[] = $in['customer_id'];
            if (isset($in['building_id'])) {
                $where .= ' AND b.id = ?';
                $params[] = $in['building_id'];
            }
        }

        return ['items' => rows('SELECT i.id, i.sku, i.epc, i.item_type_id, t.name AS type_name, i.catalog_sku_id,
            s.code AS catalog_sku_code, i.status, i.zone_id, i.delivered_on, i.swap_due_date FROM items i JOIN item_types t ON t.id = i.item_type_id
            LEFT JOIN sku_catalog s ON s.id = i.catalog_sku_id LEFT JOIN zones z ON z.id = i.zone_id
            LEFT JOIN buildings b ON b.id = z.building_id WHERE '.$where.'
            AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.reserved_item_id = i.id)
            AND NOT EXISTS (SELECT 1 FROM handover_items hi JOIN handovers h ON h.id = hi.handover_id
                WHERE hi.item_id = i.id AND h.status = \'open\' AND (hi.given_at IS NOT NULL OR hi.received_at IS NOT NULL))
            ORDER BY i.sku LIMIT 5000', $params)];
    }

    public function store(Request $request): array
    {
        $in = $request->validate([
            'type' => 'required|in:delivery,pickup', 'customer_id' => 'required|integer|min:1',
            'building_id' => 'required|integer|min:1', 'assigned_to' => 'required|integer|min:1',
            'due_date' => 'required|date_format:Y-m-d', 'destination' => 'required|in:customer,washing,inventory',
            'contract_id' => 'nullable|integer|min:1',
            'return_reason' => 'required_if:type,pickup|prohibited_unless:type,pickup|nullable|in:used,unused_clean,damaged',
            'reason' => 'nullable|string|max:500', 'washing_user_id' => 'nullable|integer|min:1',
            'items' => 'required|array|min:1|max:5000', 'items.*.item_id' => 'required|integer|min:1|distinct',
            'items.*.zone_id' => 'required|integer|min:1',
        ]);
        if (($in['type'] === 'delivery' && $in['destination'] !== 'customer') || ($in['type'] === 'pickup' && $in['destination'] === 'customer')) {
            fail(422, 'invalid_input', 'Choose a valid destination for this order type.');
        }
        if ($in['type'] === 'pickup') {
            $destination = $in['return_reason'] === 'used' ? 'washing' : 'inventory';
            if ($in['destination'] !== $destination) {
                fail(422, 'invalid_input', 'Dirty mats go to washing; unused clean or damaged mats go to inventory.');
            }
        }
        if (($in['destination'] === 'washing') !== isset($in['washing_user_id'])) {
            fail(422, 'invalid_input', 'Select a washing receiver only for a washing pickup.');
        }

        return DB::transaction(function () use ($request, $in): array {
            $taskId = null;
            $building = row('SELECT b.id FROM buildings b JOIN customers c ON c.id = b.customer_id
                WHERE b.id = ? AND c.id = ? AND b.is_active = 1 AND c.is_active = 1 FOR UPDATE', [$in['building_id'], $in['customer_id']]);
            if ($building === null) {
                fail(422, 'invalid_reference', 'Choose an active building belonging to this customer.');
            }
            if ($in['type'] === 'delivery') {
                $contract = ContractController::deliveryContract((int) $in['customer_id'], (int) $in['building_id']);
                if (isset($in['contract_id']) && (int) $in['contract_id'] !== (int) $contract['id']) {
                    fail(422, 'invalid_contract', 'The supplied contract does not match this customer location active contract.');
                }
                $in['contract_id'] = (int) $contract['id'];
            }
            foreach ([[$in['assigned_to'], 'driver'], ...(isset($in['washing_user_id']) ? [[$in['washing_user_id'], 'washing']] : [])] as [$userId, $role]) {
                if (DB::scalar('SELECT 1 FROM users WHERE id = ? AND role = ? AND is_active = 1', [$userId, $role]) === null) {
                    fail(422, 'invalid_reference', "Choose an active $role user.");
                }
            }
            $selected = $in['items'];
            usort($selected, fn ($a, $b) => $a['item_id'] <=> $b['item_id']);
            foreach ($selected as $line) {
                $item = Scan::itemById((int) $line['item_id'], true);
                self::availableItem($item, $in['type'], (int) $in['building_id'], (int) $line['zone_id']);
            }
            if ($in['type'] === 'delivery') {
                $wanted = [];
                foreach (rows('SELECT l.zone_id, l.item_type_id, l.quantity FROM contract_lines l JOIN zones z ON z.id = l.zone_id
                    WHERE l.contract_id = ? AND z.building_id = ?', [$in['contract_id'], $in['building_id']]) as $line) {
                    $wanted[$line['zone_id'].':'.$line['item_type_id']] = (int) $line['quantity'];
                }
                $got = [];
                foreach ($selected as $line) {
                    $item = Scan::itemById((int) $line['item_id']);
                    $key = $line['zone_id'].':'.$item['item_type_id'];
                    $got[$key] = ($got[$key] ?? 0) + 1;
                }
                ksort($wanted);
                ksort($got);
                if ($wanted === [] || $got !== $wanted) {
                    fail(422, 'contract_mismatch', 'Selected mats must match this building contract quantities by zone and mat type.');
                }
                $task = row("SELECT t.* FROM tasks t WHERE t.contract_id = ? AND t.building_id = ?
                    AND t.status = 'open' AND t.type IN ('delivery','swap') ORDER BY t.due_date,t.id LIMIT 1 FOR UPDATE", [$in['contract_id'], $in['building_id']]);
                if ($task !== null && DB::scalar('SELECT 1 FROM orders WHERE task_id = ?', [$task['id']]) !== null) {
                    fail(409, 'already_planned', 'This contract visit already has an office order.');
                }
                if ($task === null) {
                    DB::insert("INSERT INTO tasks (type,contract_id,building_id,due_date,assigned_to) VALUES ('delivery',?,?,?,?)",
                        [$in['contract_id'], $in['building_id'], $in['due_date'], $in['assigned_to']]);
                    $taskId = (int) DB::getPdo()->lastInsertId();
                } else {
                    $taskId = (int) $task['id'];
                    DB::update('UPDATE tasks SET assigned_to = ?, due_date = ? WHERE id = ?', [$in['assigned_to'], $in['due_date'], $taskId]);
                }
            }
            $number = ($in['type'] === 'delivery' ? 'DO-' : 'PO-').gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(6)));
            $status = $in['type'] === 'delivery' ? 'pending_qc' : 'ready';
            DB::insert('INSERT INTO orders (number,type,customer_id,building_id,assigned_to,created_by,due_date,status,destination,reason,washing_user_id,contract_id,task_id,return_reason)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$number, $in['type'], $in['customer_id'], $in['building_id'], $in['assigned_to'], $request->user()->id,
                $in['due_date'], $status, $in['destination'], $in['reason'] ?? null, $in['washing_user_id'] ?? null,
                $in['type'] === 'delivery' ? $in['contract_id'] : null, $taskId, $in['type'] === 'pickup' ? $in['return_reason'] : null]);
            $id = (int) DB::getPdo()->lastInsertId();
            foreach ($selected as $line) {
                DB::insert('INSERT INTO order_items (order_id,item_id,reserved_item_id,zone_id,qc_status) VALUES (?,?,?,?,?)',
                    [$id, $line['item_id'], $line['item_id'], $line['zone_id'], $in['type'] === 'pickup' ? 'passed' : 'pending']);
            }

            return ['order' => self::view($request, $id)];
        });
    }

    private static function availableItem(array $item, string $type, int $buildingId, int $zoneId): void
    {
        if (DB::scalar('SELECT 1 FROM item_types t JOIN categories c ON c.id = t.category_id
                WHERE t.id = ? AND t.is_active = 1 AND c.is_active = 1', [$item['item_type_id']]) === null
            || ($item['catalog_sku_id'] !== null && DB::scalar('SELECT 1 FROM sku_catalog WHERE id = ? AND item_type_id = ? AND is_active = 1', [$item['catalog_sku_id'], $item['item_type_id']]) === null)) {
            fail(422, 'invalid_reference', 'Choose an active mat type and catalog SKU.');
        }
        if (Scan::reserved((int) $item['id'])) {
            fail(409, 'reserved', 'This mat is already reserved by an order.');
        }
        if (DB::scalar('SELECT 1 FROM zones WHERE id = ? AND building_id = ? AND is_active = 1', [$zoneId, $buildingId]) === null) {
            fail(422, 'wrong_zone', 'Choose an active zone in this building.');
        }
        $valid = $type === 'delivery' ? in_array($item['status'], ['ready', 'qc_passed'], true)
            : $item['status'] === 'at_customer' && (int) $item['zone_id'] === $zoneId;
        if (! $valid) {
            fail(409, 'wrong_status', 'This exact mat is not available at the selected location.');
        }
        if (DB::scalar("SELECT 1 FROM handover_items hi JOIN handovers h ON h.id = hi.handover_id
            WHERE hi.item_id = ? AND h.status = 'open' AND (hi.given_at IS NOT NULL OR hi.received_at IS NOT NULL) LIMIT 1", [$item['id']]) !== null) {
            fail(409, 'in_handover', 'This mat is in an open handover.');
        }
    }

    public function qc(Request $request, int $id): array
    {
        $source = Scan::source($request->all());
        $epc = Scan::hex($request->input('epc'), 'epc');
        $in = $request->validate(['result' => 'required|in:pass,rewash,repair,scrap', 'note' => 'nullable|string|max:500']);

        return DB::transaction(function () use ($request, $id, $source, $epc, $in): array {
            $order = self::locked($request, $id);
            self::state($order, ['pending_qc', 'ready']);
            if ($order['type'] !== 'delivery') {
                fail(409, 'wrong_type', 'Only delivery orders need this quality check.');
            }
            $lines = self::lines($id);
            $line = self::exact($lines, $epc);
            if ($line['qc_status'] !== 'pending') {
                fail(409, 'already_checked', 'This mat was checked. Replace a failed mat before checking again.');
            }
            if (! in_array($line['status'], ['ready', 'qc_passed'], true)) {
                fail(409, 'wrong_status', 'This mat is not ready for quality checking.');
            }
            $to = ['pass' => 'qc_passed', 'rewash' => 'rewash', 'repair' => 'repair', 'scrap' => 'scrapped'][$in['result']];
            DB::update('UPDATE order_items SET qc_status = ?, qc_by = ?, qc_at = UTC_TIMESTAMP(), qc_note = ? WHERE id = ?',
                [$in['result'] === 'pass' ? 'passed' : 'failed', $request->user()->id, $in['note'] ?? null, $line['id']]);
            self::move($line, $request, $to, null, null, 'qc', $source);
            $pending = DB::scalar("SELECT 1 FROM order_items WHERE order_id = ? AND replaced_at IS NULL AND qc_status <> 'passed' LIMIT 1", [$id]);
            DB::update('UPDATE orders SET status = ? WHERE id = ?', [$pending === null ? 'ready' : 'pending_qc', $id]);

            return ['order' => self::view($request, $id)];
        });
    }

    public function replace(Request $request, int $id): array
    {
        $in = $request->validate(['item_id' => 'required|integer|min:1', 'replacement_item_id' => 'required|integer|min:1|different:item_id']);

        return DB::transaction(function () use ($request, $id, $in): array {
            $order = self::locked($request, $id);
            self::state($order, ['pending_qc']);
            if ($order['type'] !== 'delivery') {
                fail(409, 'wrong_type', 'Only a failed delivery mat can be replaced.');
            }
            // Lock both mats by ID before inspecting reservation rows.
            $ids = [(int) $in['item_id'], (int) $in['replacement_item_id']];
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $itemId) {
                Scan::itemById($itemId, true);
            }
            $line = row('SELECT * FROM order_items WHERE order_id = ? AND item_id = ? AND replaced_at IS NULL FOR UPDATE', [$id, $in['item_id']]);
            if ($line === null || $line['qc_status'] !== 'failed') {
                fail(409, 'not_failed', 'Choose a failed current order mat.');
            }
            $old = Scan::itemById((int) $in['item_id']);
            $replacement = Scan::itemById((int) $in['replacement_item_id']);
            if ((int) $old['item_type_id'] !== (int) $replacement['item_type_id'] || $old['catalog_sku_id'] !== $replacement['catalog_sku_id']) {
                fail(422, 'wrong_product', 'Replacement must have the same mat type and catalog SKU.');
            }
            self::availableItem($replacement, 'delivery', (int) $order['building_id'], (int) $line['zone_id']);
            if (DB::scalar('SELECT 1 FROM order_items WHERE order_id = ? AND item_id = ?', [$id, $replacement['id']]) !== null) {
                fail(409, 'already_used', 'This mat has already appeared on this order.');
            }
            DB::update('UPDATE order_items SET replaced_at = UTC_TIMESTAMP(), reserved_item_id = NULL WHERE id = ?', [$line['id']]);
            DB::insert('INSERT INTO order_items (order_id,item_id,reserved_item_id,zone_id) VALUES (?,?,?,?)', [$id, $replacement['id'], $replacement['id'], $line['zone_id']]);

            return ['order' => self::view($request, $id)];
        });
    }

    private static function exact(array $lines, string $epc): array
    {
        foreach ($lines as $line) {
            if ($line['epc'] === $epc) {
                return $line;
            }
        }
        fail(422, 'not_on_order', 'Scan an exact mat on this order.');
    }

    public function dispatch(Request $request, int $id): array
    {
        $source = Scan::source($request->all());
        $epcs = Scan::reads($request->all());

        return DB::transaction(function () use ($request, $id, $source, $epcs): array {
            $order = self::locked($request, $id);
            self::state($order, ['ready']);
            if ($order['type'] !== 'delivery') {
                fail(409, 'wrong_type', 'Pickup orders are scanned at the customer.');
            }
            if ($order['dispatch_handover_id'] !== null) {
                return ['order' => self::view($request, $id)];
            }
            $lines = self::lines($id);
            if (count($epcs) !== count($lines)) {
                fail(422, 'incomplete_scan', 'Scan every exact order mat before dispatch.');
            }
            foreach ($epcs as $epc) {
                $line = self::exact($lines, $epc);
                if ($line['qc_status'] !== 'passed' || $line['status'] !== 'qc_passed') {
                    fail(409, 'qc_required', 'Every current mat must pass this order quality check.');
                }
            }
            DB::insert("INSERT INTO handovers (type,giver_id,receiver_id) VALUES ('inventory_to_driver',?,?)", [$request->user()->id, $order['assigned_to']]);
            $handoverId = (int) DB::getPdo()->lastInsertId();
            foreach ($lines as $line) {
                DB::insert('INSERT INTO handover_items (handover_id,item_id,given_at) VALUES (?,?,UTC_TIMESTAMP())', [$handoverId, $line['item_id']]);
                Scan::log(['id' => $line['item_id']] + $line, (int) $request->user()->id, 'handover_give', $line['status'], $source,
                    ['handover_id' => $handoverId, 'task_id' => $order['task_id'], 'note' => 'Office order '.$order['number']]);
            }
            DB::update('UPDATE orders SET dispatch_handover_id = ? WHERE id = ?', [$handoverId, $id]);

            return ['order' => self::view($request, $id)];
        });
    }

    public function scan(Request $request, int $id): array
    {
        Scan::source($request->all());
        $batch = array_key_exists('reads', $request->all());
        $epcs = $batch ? Scan::reads($request->all()) : [Scan::hex($request->input('epc'), 'epc')];

        return DB::transaction(function () use ($request, $id, $batch, $epcs): array {
            $order = self::locked($request, $id);
            self::state($order, $order['type'] === 'pickup' ? ['ready', 'in_progress'] : ['in_progress']);
            $lines = array_column(self::lines($id), null, 'epc');
            $rejected = [];
            $matched = false;
            foreach ($epcs as $epc) {
                $line = $lines[$epc] ?? null;
                if ($line === null) {
                    if (! $batch) {
                        fail(422, 'not_on_order', 'Scan an exact mat on this order.');
                    }
                    $rejected[] = ['epc' => $epc, 'reason' => 'Scan an exact mat on this order.'];

                    continue;
                }
                self::atStop($order, $line);
                DB::update('UPDATE order_items SET scanned_at = COALESCE(scanned_at, UTC_TIMESTAMP()) WHERE id = ?', [$line['id']]);
                $matched = true;
            }
            if ($matched) {
                DB::update("UPDATE orders SET status = 'in_progress' WHERE id = ?", [$id]);
            }

            return ['order' => self::view($request, $id)] + ($batch ? ['rejected' => $rejected] : []);
        });
    }

    private static function atStop(array $order, array $line): void
    {
        $valid = $order['type'] === 'delivery'
            ? $line['status'] === 'out_for_delivery' && (int) $line['holder_user_id'] === (int) $order['assigned_to']
            : $line['status'] === 'at_customer' && (int) $line['current_zone_id'] === (int) $line['zone_id'];
        if (! $valid) {
            fail(409, 'wrong_status', 'This exact mat is not at the assigned stop.');
        }
    }

    public function refusalScan(Request $request, int $id): array
    {
        Scan::source($request->all());
        $epcs = Scan::reads($request->all());

        return DB::transaction(function () use ($request, $id, $epcs): array {
            $order = self::locked($request, $id);
            self::state($order, ['in_progress']);
            if ($order['type'] !== 'delivery') {
                fail(409, 'wrong_type', 'Only deliveries use per-mat refusal confirmation.');
            }
            $lines = self::lines($id);
            foreach ($epcs as $epc) {
                $line = self::exact($lines, $epc);
                self::atStop($order, $line);
                if ($line['scanned_at'] === null) {
                    fail(422, 'scan_required', 'Scan this order mat before confirming its refusal.');
                }
                DB::update('UPDATE order_items SET refusal_scanned_at = COALESCE(refusal_scanned_at,UTC_TIMESTAMP()) WHERE id = ?', [$line['id']]);
            }

            return ['order' => self::view($request, $id)];
        });
    }

    public function returnHandover(Request $request, int $id): array
    {
        $in = $request->validate(['receiver_id' => 'required|integer|min:1']);

        return DB::transaction(function () use ($request, $id, $in): array {
            $order = self::locked($request, $id);
            if ($order['type'] !== 'delivery') {
                fail(409, 'wrong_type', 'Only refused deliveries use this return handover.');
            }
            if ($order['return_handover_id'] !== null) {
                $receiver = DB::scalar('SELECT receiver_id FROM handovers WHERE id = ?', [$order['return_handover_id']]);
                if ((int) $receiver !== (int) $in['receiver_id']) {
                    fail(409, 'already_planned', 'This order already has a return receiver.');
                }

                return ['order' => self::view($request, $id)];
            }
            self::state($order, ['awaiting_receipt']);
            if (DB::scalar("SELECT 1 FROM users WHERE id = ? AND role = 'inventory' AND is_active = 1", [$in['receiver_id']]) === null) {
                fail(422, 'invalid_reference', 'Choose an active inventory receiver.');
            }
            $legacy = $order['task_id'] === null && $order['dispatch_handover_id'] === null && $order['customer_response'] === 'refused';
            $refused = array_filter(self::lines($id), fn (array $line) => $line['customer_response'] === 'refused'
                || ($legacy && $line['customer_response'] === null && $line['status'] === 'refused' && (int) $line['holder_user_id'] === (int) $order['assigned_to']));
            if ($refused === []) {
                fail(409, 'no_refused_mats', 'This order has no refused mats to return.');
            }
            DB::insert("INSERT INTO handovers (type,giver_id,receiver_id) VALUES ('driver_to_inventory',?,?)", [$order['assigned_to'], $in['receiver_id']]);
            $handoverId = (int) DB::getPdo()->lastInsertId();
            foreach ($refused as $line) {
                if ($line['customer_response'] === null) {
                    DB::update("UPDATE order_items SET customer_response = 'refused' WHERE id = ?", [$line['id']]);
                }
            }
            DB::update('UPDATE orders SET return_handover_id = ? WHERE id = ?', [$handoverId, $id]);

            return ['order' => self::view($request, $id)];
        });
    }

    private static function proof(Request $request, string $field, string $mime, int $max): UploadedFile
    {
        $file = $request->file($field);
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            fail(422, 'proof_required', "A valid $field is required.");
        }
        if ($file->getSize() > $max) {
            fail(422, 'file_too_big', "The $field exceeds its upload limit.");
        }
        $image = @getimagesize($file->getRealPath());
        if (! $image || $image['mime'] !== $mime || (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) !== $mime) {
            fail(422, 'wrong_file_type', "The $field must be $mime.");
        }

        return $file;
    }

    public function complete(Request $request, int $id): array
    {
        $payload = json_decode((string) $request->input('payload'), true);
        if (! is_array($payload)) {
            fail(422, 'invalid_input', 'payload must contain JSON.');
        }
        $source = Scan::source($payload);
        $in = Validator::make($payload, ['signed_name' => 'required|string|max:120', 'customer_response' => 'required|in:accepted,refused',
            'refused_epcs' => 'sometimes|array|list|max:5000'])->validate();
        $refusedEpcs = isset($in['refused_epcs']) ? array_values(array_unique(array_map(fn ($epc) => Scan::hex($epc, 'refused_epcs'), $in['refused_epcs']))) : null;
        if ($in['customer_response'] === 'accepted' && $refusedEpcs !== null && $refusedEpcs !== []) {
            fail(422, 'invalid_response', 'An accepted order cannot contain refused mats.');
        }
        $photo = self::proof($request, 'photo', 'image/jpeg', 5 * 1024 * 1024);
        $signature = self::proof($request, 'signature', 'image/png', 1024 * 1024);
        $saved = [];
        try {
            return DB::transaction(function () use ($request, $id, $source, $in, $refusedEpcs, $photo, $signature, &$saved): array {
                $order = self::locked($request, $id);
                self::state($order, ['in_progress']);
                $task = $order['task_id'] === null ? null : row('SELECT t.*,c.status AS contract_status,c.swap_interval_days
                    FROM tasks t JOIN contracts c ON c.id = t.contract_id WHERE t.id = ? FOR UPDATE', [$order['task_id']]);
                if ($order['task_id'] !== null && ($task === null || $task['status'] !== 'open' || (int) $task['assigned_to'] !== (int) $order['assigned_to'])) {
                    fail(409, 'wrong_task', 'This contract task is no longer open for the assigned driver.');
                }
                $lines = self::lines($id);
                foreach ($lines as $line) {
                    if ($line['scanned_at'] === null) {
                        fail(422, 'scan_required', 'Scan every exact order mat before adding proof.');
                    }
                    self::atStop($order, $line);
                }
                $refused = $order['type'] === 'delivery' && $in['customer_response'] === 'refused'
                    ? ($refusedEpcs ?? array_column($lines, 'epc')) : [];
                if ($order['type'] === 'delivery' && $in['customer_response'] === 'refused' && $refused === []) {
                    fail(422, 'invalid_response', 'Select at least one refused mat.');
                }
                foreach ($refused as $epc) {
                    $line = self::exact($lines, $epc);
                    if ($line['refusal_scanned_at'] === null) {
                        fail(422, 'refusal_scan_required', 'Scan every refused mat separately before confirming proof.');
                    }
                }
                $pickupAccepted = $in['customer_response'] === 'accepted';
                $deliveryContract = $task;
                if ($order['type'] === 'delivery' && count($refused) < count($lines) && $deliveryContract === null) {
                    row('SELECT id FROM buildings WHERE id = ? FOR UPDATE', [$order['building_id']]);
                    $deliveryContract = ContractController::deliveryContract((int) $order['customer_id'], (int) $order['building_id']);
                }
                $deliveryDate = today_nl();
                foreach ($lines as $line) {
                    $accepted = $order['type'] === 'delivery' ? ! in_array($line['epc'], $refused, true) : $pickupAccepted;
                    DB::update('UPDATE order_items SET customer_response = ?, reserved_item_id = ? WHERE id = ?',
                        [$accepted ? 'accepted' : 'refused', ($order['type'] === 'delivery' ? $accepted : ! $accepted) ? null : $line['item_id'], $line['id']]);
                    if ($order['type'] === 'delivery') {
                        self::move($line, $request, $accepted ? 'at_customer' : 'refused', $accepted ? null : (int) $order['assigned_to'],
                            $accepted ? (int) $line['zone_id'] : null, $accepted ? 'deliver' : 'refuse', $source, ['task_id' => $order['task_id']]);
                        if ($accepted) {
                            DB::update('UPDATE items SET delivered_on = ?, swap_due_date = ?, delivered_contract_id = ? WHERE id = ?',
                                [$deliveryDate, days_after($deliveryDate, (int) $deliveryContract['swap_interval_days']),
                                    $task['contract_id'] ?? $deliveryContract['id'], $line['item_id']]);
                        }
                    } elseif ($accepted) {
                        self::move($line, $request, 'collected', (int) $order['assigned_to'], null, 'collect', $source);
                    }
                }
                $awaiting = ($order['type'] === 'pickup' && $pickupAccepted) || ($order['type'] === 'delivery' && $refused !== []);
                $response = $order['type'] === 'delivery'
                    ? ($refused === [] ? 'accepted' : (count($refused) === count($lines) ? 'refused' : 'partial')) : $in['customer_response'];
                if (! $awaiting) {
                    DB::update('UPDATE order_items SET reserved_item_id = NULL WHERE order_id = ?', [$id]);
                }
                $stem = "order-$id-".bin2hex(random_bytes(8));
                foreach ([[$photo, "$stem.jpg"], [$signature, "$stem.png"]] as [$file,$name]) {
                    if ($file->storeAs('proofs', $name, 'local') === false) {
                        fail(500, 'storage', 'The proof could not be stored.');
                    }
                    $saved[] = "proofs/$name";
                }
                DB::update('UPDATE orders SET status = ?, customer_response = ?, signed_name = ?, photo_path = ?, signature_path = ?,
                    completed_at = IF(? = \'done\', UTC_TIMESTAMP(), NULL) WHERE id = ?',
                    [$awaiting ? 'awaiting_receipt' : 'done', $response, trim($in['signed_name']), "$stem.jpg", "$stem.png", $awaiting ? 'awaiting_receipt' : 'done', $id]);
                $taskNote = $refused === [] ? '' : count($refused).' mat(s) refused on office order '.$order['number'].'.';
                if ($task !== null && $task['type'] === 'swap') {
                    $taskNote .= ' Delivery fulfilled by office order; collection of existing customer mats requires a separate pickup order.';
                }
                $nextId = $task === null ? null : TaskController::finishVisit($task, "$stem.jpg", "$stem.png", trim($in['signed_name']),
                    trim($taskNote) ?: null, $deliveryDate, $order['type'] === 'delivery' && count($refused) < count($lines));

                return ['order' => self::view($request, $id), 'next_task_id' => $nextId];
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($saved);
            throw $error;
        }
    }

    public function receive(Request $request, int $id): array
    {
        $source = Scan::source($request->all());
        $epcs = Scan::reads($request->all());

        return DB::transaction(function () use ($request, $id, $source, $epcs): array {
            $order = self::locked($request, $id);
            if ($order['type'] === 'delivery') {
                fail(409, 'handover_required', 'Return refused delivery mats through both sides of the linked handover.');
            }
            self::state($order, ['awaiting_receipt', 'received', 'done']);
            $washing = $order['type'] === 'pickup' && $order['destination'] === 'washing';
            if (($washing && ($request->user()->role !== 'washing' || (int) $order['washing_user_id'] !== (int) $request->user()->id))
                || (! $washing && $request->user()->role !== 'inventory')) {
                fail(403, 'forbidden', 'Only this destination receiver can accept these mats.');
            }
            $lines = self::lines($id);
            foreach ($epcs as $epc) {
                $line = self::exact($lines, $epc);
                if ($line['received_at'] !== null) {
                    continue;
                }
                $from = $order['type'] === 'delivery' ? 'refused' : 'collected';
                if ($line['status'] !== $from || (int) $line['holder_user_id'] !== (int) $order['assigned_to']) {
                    fail(409, 'wrong_status', 'This exact mat is not with the assigned driver.');
                }
                $to = $washing ? 'in_washing' : (($order['return_reason'] ?? null) === 'damaged' ? 'repair' : 'ready');
                self::move($line, $request, $to, null, null, 'handover_receive', $source);
                DB::update('UPDATE order_items SET received_at = UTC_TIMESTAMP(), reserved_item_id = ? WHERE id = ?', [$washing ? $line['item_id'] : null, $line['id']]);
            }
            $missing = DB::scalar('SELECT 1 FROM order_items WHERE order_id = ? AND replaced_at IS NULL AND received_at IS NULL LIMIT 1', [$id]);
            if ($missing === null && $order['status'] === 'awaiting_receipt') {
                DB::update('UPDATE orders SET status = ?, received_at = UTC_TIMESTAMP(), completed_at = IF(? = \'done\',UTC_TIMESTAMP(),NULL) WHERE id = ?',
                    [$washing ? 'received' : 'done', $washing ? 'received' : 'done', $id]);
            }

            return ['order' => self::view($request, $id)];
        });
    }

    public function wash(Request $request, int $id): array
    {
        $source = Scan::source($request->all());
        $epcs = Scan::reads($request->all());
        $in = $request->validate(['receiver_id' => 'required|integer|min:1']);

        return DB::transaction(function () use ($request, $id, $source, $epcs, $in): array {
            $order = self::locked($request, $id);
            self::state($order, ['received', 'done']);
            if ($order['type'] !== 'pickup' || $order['destination'] !== 'washing' || (int) $order['washing_user_id'] !== (int) $request->user()->id) {
                fail(403, 'forbidden', 'Only the assigned washing receiver can finish washing.');
            }
            $handoverId = HandoverController::washOrder($request, $order, $epcs, (int) $in['receiver_id'], $source);

            return ['order' => self::view($request, $id), 'handover_id' => $handoverId];
        }, 3);
    }

    /** Removes an order that has not been checked, scanned or handed over. The visit task stays. */
    public function destroy(int $id): array
    {
        DB::transaction(function () use ($id): void {
            $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
            if ($order === null) {
                fail(404, 'not_found', 'No such order.');
            }
            $started = ! in_array($order->status, ['pending_qc', 'ready'], true)
                || $order->dispatch_handover_id !== null
                || $order->return_handover_id !== null
                || DB::table('order_items')->where('order_id', $id)->where(function ($query): void {
                    $query->whereNotNull('scanned_at')->orWhereNotNull('received_at')
                        ->orWhereNotNull('qc_at')->orWhereNotNull('replaced_at');
                })->exists();
            if ($started) {
                fail(409, 'in_use', 'This order has already started, so it stays on record.');
            }
            DB::table('order_items')->where('order_id', $id)->delete();
            DB::table('orders')->where('id', $id)->delete();
        });
        return ['deleted' => true];
    }
}
