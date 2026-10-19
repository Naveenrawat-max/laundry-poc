<?php
/** Contracts: how many mats of which type lie in which zone, and n, the days between swaps. Activation creates the delivery tasks. */
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContractController extends Controller
{
    // n: the days between two swaps.
    private const INTERVAL_RULE = 'integer|between:1,365';

    private const LOCATION_SCOPE = '(c.building_id = ? OR (c.building_id IS NULL AND EXISTS (
        SELECT 1 FROM contract_lines cl JOIN zones z ON z.id = cl.zone_id WHERE cl.contract_id = c.id AND z.building_id = ?)))';

    private const LINE_RULES = [
        'lines' => 'array|list',
        'lines.*.zone_id' => 'required|integer|min:0',
        'lines.*.item_type_id' => 'required|integer|min:0',
        'lines.*.quantity' => 'required|integer|between:1,1000',
    ];

    /** One contract with its lines. 404 when unknown. */
    private static function view(int $id): array
    {
        $contract = row("SELECT c.id, c.customer_id, cu.name AS customer_name, c.building_id, b.name AS building_name,
            b.street, b.postcode, b.city, c.swap_interval_days, c.status, c.start_date, c.end_date,
            (SELECT MIN(t.due_date) FROM tasks t WHERE t.contract_id = c.id AND t.status = 'open'
                AND t.type IN ('delivery','swap')) AS next_visit_date,
            (SELECT MIN(t.due_date) FROM tasks t WHERE t.contract_id = c.id AND t.status = 'open'
                AND t.type = 'swap') AS next_swap_date
            FROM contracts c JOIN customers cu ON cu.id = c.customer_id LEFT JOIN buildings b ON b.id = c.building_id WHERE c.id = ?", [$id]);
        if ($contract === null) {
            fail(404, 'not_found', 'No such contract.');
        }
        $contract['lines'] = rows('SELECT l.id, l.zone_id, z.name AS zone_name, z.building_id, b.name AS building_name,
                l.item_type_id, t.name AS type_name, l.quantity
            FROM contract_lines l
            JOIN zones z ON z.id = l.zone_id
            JOIN buildings b ON b.id = z.building_id
            JOIN item_types t ON t.id = l.item_type_id
            WHERE l.contract_id = ? ORDER BY b.name, z.name, t.name', [$id]);
        return $contract;
    }

    /** Locks one contract row for the rest of the transaction. */
    private static function locked(int $id): array
    {
        $contract = row('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [$id]);
        if ($contract === null) {
            fail(404, 'not_found', 'No such contract.');
        }
        return $contract;
    }

    /** The validated lines as [zone id, type id, quantity]. Every zone must belong to the contract's customer. */
    private static function lines(array $lines, int $customerId, ?int $buildingId): array
    {
        $out = [];
        foreach ($lines as $line) {
            $zoneId = (int) $line['zone_id'];
            $ofCustomer = DB::scalar('SELECT 1 FROM zones z JOIN buildings b ON b.id = z.building_id
                WHERE z.id = ? AND b.customer_id = ? AND z.is_active = 1 AND b.is_active = 1'
                .($buildingId !== null ? ' AND b.id = ?' : ''),
                [$zoneId, $customerId, ...($buildingId !== null ? [$buildingId] : [])]);
            if ($ofCustomer === null) {
                fail(422, 'invalid_reference', "Zone $zoneId must be active and belong to this customer location.");
            }
            $out[] = [$zoneId, (int) $line['item_type_id'], (int) $line['quantity']];
        }
        return $out;
    }

    /** Replaces the lines. Only ever called for a draft, when no task and no history depends on them yet. */
    private static function saveLines(int $contractId, array $lines): void
    {
        DB::delete('DELETE FROM contract_lines WHERE contract_id = ?', [$contractId]);
        foreach ($lines as [$zoneId, $typeId, $quantity]) {
            DB::insert('INSERT INTO contract_lines (contract_id, zone_id, item_type_id, quantity) VALUES (?, ?, ?, ?)',
                [$contractId, $zoneId, $typeId, $quantity]);
        }
    }

    public function index(Request $request): array
    {
        $in = $request->validate(['customer_id' => 'nullable|integer|min:1', 'building_id' => 'nullable|integer|min:1',
            'status' => 'nullable|in:draft,active,ended']);
        $where = ['1 = 1'];
        $params = [];
        if (isset($in['customer_id'])) {
            if (DB::scalar('SELECT 1 FROM customers WHERE id = ?', [$in['customer_id']]) === null) {
                fail(422, 'invalid_reference', 'Unknown customer.');
            }
            $where[] = 'c.customer_id = ?';
            $params[] = $in['customer_id'];
        }
        if (isset($in['building_id'])) {
            $location = row('SELECT customer_id FROM buildings WHERE id = ?', [$in['building_id']]);
            if ($location === null || (isset($in['customer_id']) && (int) $location['customer_id'] !== (int) $in['customer_id'])) {
                fail(422, 'invalid_reference', 'This location does not belong to the selected customer.');
            }
            $where[] = self::LOCATION_SCOPE.' AND c.customer_id = ?';
            array_push($params, $in['building_id'], $in['building_id'], $location['customer_id']);
        }
        if (isset($in['status'])) {
            $where[] = 'c.status = ?';
            $params[] = $in['status'];
        }
        // ponytail: one query per contract. Fine for tens of contracts; join and group when there are hundreds.
        $ids = array_column(rows('SELECT c.id FROM contracts c WHERE '.implode(' AND ', $where).' ORDER BY c.id DESC', $params), 'id');
        $contracts = array_map(self::view(...), $ids);
        if (isset($in['building_id'])) {
            foreach ($contracts as &$contract) {
                $dates = row("SELECT MIN(due_date) AS next_visit_date, MIN(IF(type = 'swap',due_date,NULL)) AS next_swap_date
                    FROM tasks WHERE contract_id = ? AND building_id = ? AND status = 'open' AND type IN ('delivery','swap')",
                    [$contract['id'], $in['building_id']]);
                $contract = array_replace($contract, $dates);
            }
            unset($contract);
        }
        return ['rows' => $contracts];
    }

    /** Caller holds the location lock so activation cannot race this delivery selection. */
    public static function deliveryContract(int $customerId, int $buildingId): array
    {
        $contracts = rows("SELECT c.* FROM contracts c WHERE c.customer_id = ? AND c.status = 'active' AND "
            .self::LOCATION_SCOPE.' ORDER BY c.id FOR UPDATE', [$customerId, $buildingId, $buildingId]);
        if ($contracts === []) {
            fail(422, 'invalid_contract', 'This customer location has no active contract. Create and activate one first.');
        }
        if (count($contracts) !== 1) {
            fail(409, 'ambiguous_contract', 'This location has multiple active contracts. End the overlap before creating a delivery.');
        }
        return $contracts[0];
    }

    private static function location(int $buildingId, int $customerId): void
    {
        if (DB::scalar('SELECT 1 FROM buildings b JOIN customers c ON c.id = b.customer_id
            WHERE b.id = ? AND c.id = ? AND b.is_active = 1 AND c.is_active = 1', [$buildingId, $customerId]) === null) {
            fail(422, 'invalid_reference', 'Choose an active location belonging to this active customer.');
        }
    }

    public function store(Request $request): array
    {
        $in = $request->validate([
            'customer_id' => 'required|integer|min:0',
            'building_id' => 'required|integer|min:1',
            'swap_interval_days' => 'required|' . self::INTERVAL_RULE,
            'start_date' => 'required|date_format:Y-m-d',
        ] + self::LINE_RULES);
        $customerId = (int) $in['customer_id'];
        return DB::transaction(function () use ($in, $customerId): array {
            self::location((int) $in['building_id'], $customerId);
            $lines = self::lines($in['lines'] ?? [], $customerId, (int) $in['building_id']);
            DB::insert('INSERT INTO contracts (customer_id, building_id, swap_interval_days, start_date) VALUES (?, ?, ?, ?)',
                [$customerId, $in['building_id'], (int) $in['swap_interval_days'], $in['start_date']]);
            $id = (int) DB::getPdo()->lastInsertId();
            self::saveLines($id, $lines);
            return ['contract' => self::view($id)];
        });
    }

    /** Changes only the fields that were sent. */
    public function update(Request $request, int $id): array
    {
        $in = $request->validate([
            'swap_interval_days' => 'sometimes|required|' . self::INTERVAL_RULE,
            'start_date' => 'sometimes|required|date_format:Y-m-d',
            'building_id' => 'sometimes|required|integer|min:1',
        ] + self::LINE_RULES);
        $hasLines = $request->has('lines');   // an empty list counts: it removes every line
        $moveScope = [];
        if (isset($in['building_id'])) {
            $snapshot = row('SELECT id,building_id FROM contracts WHERE id = ?', [$id]);
            if ($snapshot === null) {
                fail(404, 'not_found', 'No such contract.');
            }
            $moveScope = array_values(array_unique([...self::buildingIds($snapshot), (int) $in['building_id']]));
            sort($moveScope, SORT_NUMERIC);
        }
        return DB::transaction(function () use ($in, $hasLines, $id, $moveScope): array {
            if ($moveScope !== []) {
                rows('SELECT id FROM buildings WHERE id IN ('.marks($moveScope).') ORDER BY id FOR UPDATE', $moveScope);
            }
            $contract = self::locked($id);
            if ($moveScope !== [] && array_diff(self::buildingIds($contract), $moveScope) !== []) {
                fail(409, 'contract_changed', 'This contract location changed. Reload before editing it.');
            }
            if ($contract['status'] === 'ended') {
                fail(409, 'ended', 'An ended contract cannot be changed.');
            }
            if (($hasLines || isset($in['start_date']) || isset($in['building_id'])) && $contract['status'] !== 'draft') {
                fail(409, 'not_draft', 'Location, lines and start date can only be changed while the contract is a draft.');
            }
            $buildingId = isset($in['building_id']) ? (int) $in['building_id']
                : ($contract['building_id'] === null ? null : (int) $contract['building_id']);
            if ($buildingId !== null && ($hasLines || isset($in['building_id']))) {
                self::location($buildingId, (int) $contract['customer_id']);
            }
            if (isset($in['building_id'])) {
                if (!$hasLines) {
                    $existing = rows('SELECT zone_id,item_type_id,quantity FROM contract_lines WHERE contract_id = ?', [$id]);
                    self::lines($existing, (int) $contract['customer_id'], $buildingId);
                }
                DB::update('UPDATE contracts SET building_id = ? WHERE id = ?', [$buildingId, $id]);
            }
            if (isset($in['start_date'])) {
                DB::update('UPDATE contracts SET start_date = ? WHERE id = ?', [$in['start_date'], $id]);
            }
            if ($hasLines) {
                self::saveLines($id, self::lines($in['lines'] ?? [], (int) $contract['customer_id'], $buildingId));
            }
            if (isset($in['swap_interval_days'])) {
                $days = (int) $in['swap_interval_days'];
                if ($days !== (int) $contract['swap_interval_days']
                    && DB::scalar("SELECT 1 FROM orders WHERE contract_id = ? AND status NOT IN ('done','cancelled') LIMIT 1", [$id]) !== null) {
                    fail(409, 'active_order', 'Finish this contract office order before changing its swap interval.');
                }
                DB::update('UPDATE contracts SET swap_interval_days = ? WHERE id = ?', [$days, $id]);
                // An open swap task is due on D + old n. Moving it by the difference makes it D + new n.
                DB::update("UPDATE tasks SET due_date = due_date + INTERVAL ? DAY WHERE contract_id = ? AND type = 'swap' AND status = 'open'",
                    [$days - $contract['swap_interval_days'], $id]);
                DB::update("UPDATE items SET swap_due_date = DATE_ADD(delivered_on, INTERVAL ? DAY)
                    WHERE delivered_contract_id = ? AND status = 'at_customer' AND delivered_on IS NOT NULL", [$days, $id]);
            }
            return ['contract' => self::view($id)];
        }, 3);
    }

    /** Starts a draft contract: one delivery task per building that has lines, due on the start date. */
    public function activate(int $id): array
    {
        $snapshot = row('SELECT id,building_id FROM contracts WHERE id = ?', [$id]);
        if ($snapshot === null) {
            fail(404, 'not_found', 'No such contract.');
        }
        $scope = self::buildingIds($snapshot);
        return DB::transaction(function () use ($id, $scope): array {
            // Every activation and delivery takes location locks before contract locks.
            if ($scope !== []) {
                rows('SELECT id FROM buildings WHERE id IN ('.marks($scope).') ORDER BY id FOR UPDATE', $scope);
            }
            $contract = self::locked($id);
            if ($contract['status'] !== 'draft') {
                fail(409, 'not_draft', "This contract is already {$contract['status']}.");
            }
            if (self::buildingIds($contract) !== $scope) {
                fail(409, 'contract_changed', 'This contract location changed. Reload before activating it.');
            }
            $buildingIds = array_column(rows('SELECT DISTINCT z.building_id FROM contract_lines l JOIN zones z ON z.id = l.zone_id
                WHERE l.contract_id = ? ORDER BY z.building_id', [$id]), 'building_id');
            if ($buildingIds === []) {
                fail(422, 'no_lines', 'A contract needs at least one line before it can start.');
            }
            self::lines(rows('SELECT zone_id,item_type_id,quantity FROM contract_lines WHERE contract_id = ?', [$id]),
                (int) $contract['customer_id'], $contract['building_id'] === null ? null : (int) $contract['building_id']);
            foreach ($buildingIds as $buildingId) {
                self::location((int) $buildingId, (int) $contract['customer_id']);
                if (DB::scalar("SELECT c.id FROM contracts c WHERE c.id <> ? AND c.status = 'active' AND "
                    .self::LOCATION_SCOPE.' LIMIT 1', [$id, $buildingId, $buildingId]) !== null) {
                    fail(409, 'active_contract', 'This location already has an active contract. End it before activating another.');
                }
            }
            DB::update("UPDATE contracts SET status = 'active' WHERE id = ? AND status = 'draft'", [$id]);
            $taskIds = [];
            foreach ($buildingIds as $buildingId) {
                DB::insert("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('delivery', ?, ?, ?)",
                    [$id, $buildingId, $contract['start_date']]);
                $taskIds[] = (int) DB::getPdo()->lastInsertId();
            }
            return ['contract' => self::view($id), 'task_ids' => $taskIds];
        }, 3);
    }

    private static function buildingIds(array $contract): array
    {
        $ids = array_map('intval', array_column(rows('SELECT DISTINCT z.building_id FROM contract_lines l
            JOIN zones z ON z.id = l.zone_id WHERE l.contract_id = ?', [$contract['id']]), 'building_id'));
        if ($contract['building_id'] !== null) {
            $ids[] = (int) $contract['building_id'];
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** Ends an active contract and cancels its open swap tasks. */
    public function end(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $contract = self::locked($id);
            if ($contract['status'] !== 'active') {
                fail(409, 'not_active', "Only an active contract can be ended. This one is {$contract['status']}.");
            }
            if (DB::scalar("SELECT 1 FROM orders WHERE contract_id = ? AND status NOT IN ('done','cancelled') LIMIT 1", [$id]) !== null) {
                fail(409, 'active_order', 'Finish this contract office order and any refused-mat returns before ending it.');
            }
            // The database refuses an end date before the start date, so a contract ended early ends on its start date.
            DB::update("UPDATE contracts SET status = 'ended', end_date = ? WHERE id = ? AND status = 'active'",
                [max(today_nl(), $contract['start_date']), $id]);
            DB::update("UPDATE tasks SET status = 'cancelled' WHERE contract_id = ? AND type = 'swap' AND status = 'open'", [$id]);
            return ['contract' => self::view($id)];
        });
    }

    /** Removes a draft. An active contract has to be ended first. An ended contract stays on record. */
    public function destroy(int $id): array
    {
        DB::transaction(function () use ($id): void {
            $contract = self::locked($id);
            if ($contract['status'] === 'active') {
                fail(409, 'in_use', 'End this contract first.');
            }
            if ($contract['status'] === 'ended') {
                fail(409, 'in_use', 'This contract has ended and stays on record.');
            }
            if (DB::table('orders')->where('contract_id', $id)->exists()) {
                fail(409, 'in_use', 'Finish or remove the orders for this contract first.');
            }
            $taskIds = DB::table('tasks')->where('contract_id', $id)->pluck('id');
            if ($taskIds->isNotEmpty() && (
                DB::table('tasks')->whereIn('id', $taskIds)->where('status', 'done')->exists()
                || DB::table('item_events')->whereIn('task_id', $taskIds)->exists()
            )) {
                fail(409, 'in_use', 'This contract has visits on record, so it stays.');
            }
            if ($taskIds->isNotEmpty()) {
                DB::table('tasks')->whereIn('id', $taskIds)->update(['previous_task_id' => null]);
                DB::table('tasks')->where('contract_id', $id)->delete();
            }
            DB::table('contract_lines')->where('contract_id', $id)->delete();
            DB::table('contracts')->where('id', $id)->delete();
        });
        return ['deleted' => true];
    }
}
