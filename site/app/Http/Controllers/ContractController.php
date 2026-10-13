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

    private const LINE_RULES = [
        'lines' => 'array|list',
        'lines.*.zone_id' => 'required|integer|min:0',
        'lines.*.item_type_id' => 'required|integer|min:0',
        'lines.*.quantity' => 'required|integer|between:1,1000',
    ];

    /** One contract with its lines. 404 when unknown. */
    private static function view(int $id): array
    {
        $contract = row('SELECT c.id, c.customer_id, cu.name AS customer_name, c.swap_interval_days, c.status, c.start_date, c.end_date
            FROM contracts c JOIN customers cu ON cu.id = c.customer_id WHERE c.id = ?', [$id]);
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
    private static function lines(array $lines, int $customerId): array
    {
        $out = [];
        foreach ($lines as $line) {
            $zoneId = (int) $line['zone_id'];
            $ofCustomer = DB::scalar('SELECT 1 FROM zones z JOIN buildings b ON b.id = z.building_id WHERE z.id = ? AND b.customer_id = ?',
                [$zoneId, $customerId]);
            if ($ofCustomer === null) {
                fail(422, 'invalid_reference', "Zone $zoneId does not belong to this customer.");
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

    public function index(): array
    {
        // ponytail: one query per contract. Fine for tens of contracts; join and group when there are hundreds.
        $ids = array_column(rows('SELECT id FROM contracts ORDER BY id DESC'), 'id');
        return ['rows' => array_map(self::view(...), $ids)];
    }

    public function store(Request $request): array
    {
        $in = $request->validate([
            'customer_id' => 'required|integer|min:0',
            'swap_interval_days' => 'required|' . self::INTERVAL_RULE,
            'start_date' => 'required|date_format:Y-m-d',
        ] + self::LINE_RULES);
        $customerId = (int) $in['customer_id'];
        if (DB::scalar('SELECT 1 FROM customers WHERE id = ?', [$customerId]) === null) {
            fail(422, 'invalid_reference', 'Unknown customer.');
        }
        $lines = self::lines($in['lines'] ?? [], $customerId);
        return DB::transaction(function () use ($in, $customerId, $lines): array {
            DB::insert('INSERT INTO contracts (customer_id, swap_interval_days, start_date) VALUES (?, ?, ?)',
                [$customerId, (int) $in['swap_interval_days'], $in['start_date']]);
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
        ] + self::LINE_RULES);
        $hasLines = $request->has('lines');   // an empty list counts: it removes every line
        return DB::transaction(function () use ($in, $hasLines, $id): array {
            $contract = self::locked($id);
            if ($contract['status'] === 'ended') {
                fail(409, 'ended', 'An ended contract cannot be changed.');
            }
            if (($hasLines || isset($in['start_date'])) && $contract['status'] !== 'draft') {
                fail(409, 'not_draft', 'Lines and start date can only be changed while the contract is a draft.');
            }
            if (isset($in['start_date'])) {
                DB::update('UPDATE contracts SET start_date = ? WHERE id = ?', [$in['start_date'], $id]);
            }
            if ($hasLines) {
                self::saveLines($id, self::lines($in['lines'] ?? [], $contract['customer_id']));
            }
            if (isset($in['swap_interval_days'])) {
                $days = (int) $in['swap_interval_days'];
                DB::update('UPDATE contracts SET swap_interval_days = ? WHERE id = ?', [$days, $id]);
                // An open swap task is due on D + old n. Moving it by the difference makes it D + new n.
                DB::update("UPDATE tasks SET due_date = due_date + INTERVAL ? DAY WHERE contract_id = ? AND type = 'swap' AND status = 'open'",
                    [$days - $contract['swap_interval_days'], $id]);
            }
            return ['contract' => self::view($id)];
        });
    }

    /** Starts a draft contract: one delivery task per building that has lines, due on the start date. */
    public function activate(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $contract = self::locked($id);
            if ($contract['status'] !== 'draft') {
                fail(409, 'not_draft', "This contract is already {$contract['status']}.");
            }
            $buildingIds = array_column(rows('SELECT DISTINCT z.building_id FROM contract_lines l JOIN zones z ON z.id = l.zone_id
                WHERE l.contract_id = ? ORDER BY z.building_id', [$id]), 'building_id');
            if ($buildingIds === []) {
                fail(422, 'no_lines', 'A contract needs at least one line before it can start.');
            }
            DB::update("UPDATE contracts SET status = 'active' WHERE id = ? AND status = 'draft'", [$id]);
            $taskIds = [];
            foreach ($buildingIds as $buildingId) {
                DB::insert("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('delivery', ?, ?, ?)",
                    [$id, $buildingId, $contract['start_date']]);
                $taskIds[] = (int) DB::getPdo()->lastInsertId();
            }
            return ['contract' => self::view($id), 'task_ids' => $taskIds];
        });
    }

    /** Ends an active contract and cancels its open swap tasks. */
    public function end(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $contract = self::locked($id);
            if ($contract['status'] !== 'active') {
                fail(409, 'not_active', "Only an active contract can be ended. This one is {$contract['status']}.");
            }
            // The database refuses an end date before the start date, so a contract ended early ends on its start date.
            DB::update("UPDATE contracts SET status = 'ended', end_date = ? WHERE id = ? AND status = 'active'",
                [max(today_nl(), $contract['start_date']), $id]);
            DB::update("UPDATE tasks SET status = 'cancelled' WHERE contract_id = ? AND type = 'swap' AND status = 'open'", [$id]);
            return ['contract' => self::view($id)];
        });
    }
}
