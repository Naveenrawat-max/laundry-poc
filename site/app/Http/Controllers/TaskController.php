<?php
/** Tasks: one visit to one building. Lists per role, assignment, closing a stop with proof, and the next swap. */
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TaskController extends Controller
{
    private const SELECT = 'SELECT t.id, t.type, t.status, t.due_date, t.completed_at, t.contract_id, t.building_id, t.previous_task_id,
            t.assigned_to, u.name AS assigned_name, t.signed_name, t.issue_note, t.photo_path IS NOT NULL AS has_proof,
            b.name AS building_name, b.street, b.postcode, b.city, a.name AS area_name,
            c.id AS customer_id, c.name AS customer_name, c.contact_name, c.contact_phone
        FROM tasks t
        JOIN buildings b ON b.id = t.building_id
        JOIN areas a ON a.id = b.area_id
        JOIN customers c ON c.id = b.customer_id
        LEFT JOIN users u ON u.id = t.assigned_to';

    /** One task. 404 when unknown. */
    private static function view(int $id): array
    {
        $task = row(self::SELECT . ' WHERE t.id = ?', [$id]);
        if ($task === null) {
            fail(404, 'not_found', 'No such task.');
        }
        return $task;
    }

    /** What the driver needs at the stop: the zones, the mats the contract expects, and the mats lying there now. */
    private static function stopDetails(array $task): array
    {
        $task['zones'] = rows('SELECT id, name FROM zones WHERE building_id = ? AND is_active = 1 ORDER BY name',
            [$task['building_id']]);
        $task['expected'] = rows('SELECT l.zone_id, z.name AS zone_name, l.item_type_id, it.name AS type_name, l.quantity
            FROM contract_lines l JOIN zones z ON z.id = l.zone_id JOIN item_types it ON it.id = l.item_type_id
            WHERE l.contract_id = ? AND z.building_id = ? ORDER BY z.name, it.name',
            [$task['contract_id'], $task['building_id']]);
        $task['in_building'] = rows("SELECT i.id, i.sku, i.epc, i.zone_id, it.name AS type
            FROM items i JOIN item_types it ON it.id = i.item_type_id JOIN zones z ON z.id = i.zone_id
            WHERE i.status = 'at_customer' AND z.building_id = ? ORDER BY i.sku", [$task['building_id']]);
        return $task;
    }

    /** Per driver and mat type: mats his open delivery and swap tasks need, mats already on his van, and the difference. */
    private static function needs(): array
    {
        $needed = rows("SELECT t.assigned_to AS driver_id, u.name AS driver_name, l.item_type_id, it.name AS type_name,
                SUM(l.quantity) AS needed
            FROM tasks t
            JOIN users u ON u.id = t.assigned_to
            JOIN contract_lines l ON l.contract_id = t.contract_id
            JOIN zones z ON z.id = l.zone_id AND z.building_id = t.building_id
            JOIN item_types it ON it.id = l.item_type_id
            WHERE t.status = 'open' AND t.type IN ('delivery', 'swap')
            GROUP BY t.assigned_to, u.name, l.item_type_id, it.name
            ORDER BY u.name, it.name");

        $carried = [];
        $onVans = rows("SELECT holder_user_id, item_type_id, COUNT(*) AS n FROM items
            WHERE status = 'out_for_delivery' GROUP BY holder_user_id, item_type_id");
        foreach ($onVans as $van) {
            $carried["{$van['holder_user_id']}:{$van['item_type_id']}"] = (int) $van['n'];
        }

        $drivers = [];
        foreach ($needed as $need) {
            $have = $carried["{$need['driver_id']}:{$need['item_type_id']}"] ?? 0;
            $drivers[$need['driver_id']] ??= ['driver' => ['id' => $need['driver_id'], 'name' => $need['driver_name']], 'needs' => []];
            $drivers[$need['driver_id']]['needs'][] = [
                'item_type_id' => $need['item_type_id'],
                'type' => $need['type_name'],
                'needed' => (int) $need['needed'],   // SUM() comes back as a decimal string
                'carried' => $have,
                'remaining' => max(0, (int) $need['needed'] - $have),
            ];
        }
        return array_values($drivers);
    }

    public function index(Request $request): array
    {
        $user = $request->user();
        if ($user->role === 'inventory' || $user->role === 'outbound') {
            return ['drivers' => self::needs()];
        }
        if ($user->role === 'driver') {
            $tasks = rows(self::SELECT . " WHERE t.assigned_to = ? AND t.status = 'open' ORDER BY t.due_date, t.id", [$user->id]);
            return ['tasks' => array_map(self::stopDetails(...), $tasks)];
        }

        $filter = $request->validate([
            'status' => 'nullable|in:open,done,cancelled',
            'type' => 'nullable|in:delivery,swap,collection',
            'assigned_to' => 'nullable|integer|min:0',
        ]);
        $where = ['1 = 1'];
        $params = [];
        if (isset($filter['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filter['status'];
        }
        if (isset($filter['type'])) {
            $where[] = 't.type = ?';
            $params[] = $filter['type'];
        }
        if (isset($filter['assigned_to'])) {
            $where[] = 't.assigned_to = ?';
            $params[] = (int) $filter['assigned_to'];
        }
        // ponytail: open work first, 200 rows, no paging. Add paging when the office has more than fits one screen.
        return ['tasks' => rows(self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY t.status = 'open' DESC, t.due_date, t.id LIMIT 200", $params)];
    }

    /** The office creates a collection task: pick-up only, when a contract ends. */
    public function store(Request $request): array
    {
        $in = $request->validate([
            'contract_id' => 'required|integer|min:0',
            'building_id' => 'required|integer|min:0',
            'due_date' => 'required|date_format:Y-m-d',
        ]);
        $contractId = (int) $in['contract_id'];
        $buildingId = (int) $in['building_id'];

        $contract = row('SELECT status FROM contracts WHERE id = ?', [$contractId]);
        if ($contract === null) {
            fail(404, 'not_found', 'No such contract.');
        }
        if ($contract['status'] === 'draft') {
            fail(409, 'not_started', 'A draft contract has no mats to collect.');
        }
        $inContract = DB::scalar('SELECT 1 FROM contract_lines l JOIN zones z ON z.id = l.zone_id WHERE l.contract_id = ? AND z.building_id = ? LIMIT 1',
            [$contractId, $buildingId]);
        if ($inContract === null) {
            fail(422, 'invalid_reference', 'This building is not part of that contract.');
        }
        DB::insert("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('collection', ?, ?, ?)",
            [$contractId, $buildingId, $in['due_date']]);
        return ['task' => self::view((int) DB::getPdo()->lastInsertId())];
    }

    /** The office assigns a driver, moves the due date or cancels. Only while the task is open. */
    public function update(Request $request, int $id): array
    {
        $in = $request->validate([
            'assigned_to' => 'nullable|integer|min:0',
            'due_date' => 'sometimes|required|date_format:Y-m-d',
            'status' => 'sometimes|required|in:cancelled',
        ]);
        $assign = $request->has('assigned_to');   // null counts: it takes the task away from the driver
        $driverId = isset($in['assigned_to']) ? (int) $in['assigned_to'] : null;
        return DB::transaction(function () use ($in, $assign, $driverId, $id): array {
            $task = row('SELECT status FROM tasks WHERE id = ? FOR UPDATE', [$id]);
            if ($task === null) {
                fail(404, 'not_found', 'No such task.');
            }
            if ($task['status'] !== 'open') {
                fail(409, 'not_open', "This task is already {$task['status']}.");
            }
            if ($assign) {
                $isDriver = DB::scalar("SELECT 1 FROM users WHERE id = ? AND role = 'driver' AND is_active = 1", [$driverId]);
                if ($driverId !== null && $isDriver === null) {
                    fail(422, 'invalid_reference', 'A task can only be assigned to an active driver.');
                }
                DB::update('UPDATE tasks SET assigned_to = ? WHERE id = ?', [$driverId, $id]);
            }
            if (isset($in['due_date'])) {
                DB::update('UPDATE tasks SET due_date = ? WHERE id = ?', [$in['due_date'], $id]);
            }
            if (isset($in['status'])) {
                DB::update("UPDATE tasks SET status = 'cancelled' WHERE id = ? AND status = 'open'", [$id]);
            }
            return ['task' => self::view($id)];
        });
    }
}
