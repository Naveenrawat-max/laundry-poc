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
            'from' => 'refused', 'to' => 'ready', 'two_sided' => true, 'wash' => false],
        'inventory_to_washing' => ['giver' => ['inventory'], 'receiver' => 'washing',
            'from' => 'rewash', 'to' => 'in_washing', 'two_sided' => true, 'wash' => false],
    ];

    /** One handover with its two people and its pile. 404 when unknown. */
    private static function view(int $id): array
    {
        $handover = row('SELECT h.*, g.name AS giver_name, r.name AS receiver_name
            FROM handovers h JOIN users g ON g.id = h.giver_id JOIN users r ON r.id = h.receiver_id
            WHERE h.id = ?', [$id]);
        if ($handover === null) {
            fail(404, 'not_found', 'No such handover.');
        }
        $items = rows('SELECT i.*, t.name AS type_name, hi.given_at, hi.received_at, hi.resolved_at
            FROM handover_items hi JOIN items i ON i.id = hi.item_id JOIN item_types t ON t.id = i.item_type_id
            WHERE hi.handover_id = ? ORDER BY i.sku', [$id]);
        return [
            'id' => $handover['id'],
            'type' => $handover['type'],
            'status' => $handover['status'],
            'created_at' => $handover['created_at'],
            'closed_at' => $handover['closed_at'],
            'giver' => ['id' => $handover['giver_id'], 'name' => $handover['giver_name']],
            'receiver' => ['id' => $handover['receiver_id'], 'name' => $handover['receiver_name']],
            'items' => array_map(fn (array $item) => Scan::view($item)
                + ['given_at' => $item['given_at'], 'received_at' => $item['received_at'], 'resolved_at' => $item['resolved_at']], $items),
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
        return DB::transaction(function () use ($type, $receiverId, $user): array {
            // Opening the same handover twice (a retry on a bad connection) gives the open one back.
            $open = row("SELECT id FROM handovers WHERE type = ? AND giver_id = ? AND receiver_id = ? AND status = 'open' FOR UPDATE",
                [$type, $user->id, $receiverId]);
            if ($open !== null) {
                return ['handover' => self::view($open['id'])];
            }
            DB::insert('INSERT INTO handovers (type, giver_id, receiver_id) VALUES (?, ?, ?)', [$type, $user->id, $receiverId]);
            return ['handover' => self::view((int) DB::getPdo()->lastInsertId())];
        });
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
            if ($handover['status'] !== 'open') {
                fail(409, 'closed', 'This handover is closed.');
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
                $results[] = $side === 'give'
                    ? self::give($handover, $item, $line, $user, $source)
                    : self::receive($handover, $item, $line, $user, $source);
            }

            // The receiver sees which mats the giver scanned and he has not.
            $missing = $side !== 'receive' ? [] : array_map(Scan::view(...), rows(Scan::ITEM_SELECT
                . ' JOIN handover_items hi ON hi.item_id = i.id
                    WHERE hi.handover_id = ? AND hi.given_at IS NOT NULL AND hi.received_at IS NULL ORDER BY i.sku', [$id]));
            return ['results' => $results, 'missing' => $missing];
        });
    }

    /** The giver scans one mat. $line is the mat's row in this handover, or null when it has none yet. */
    private static function give(array $handover, array $item, ?array $line, User $user, string $source): array
    {
        $rule = self::TYPES[$handover['type']];
        $epc = $item['epc'];
        if ($line !== null && $line['given_at'] !== null) {
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
        return ['handovers' => rows("SELECT h.id, h.type, h.status, h.created_at, h.closed_at,
                h.giver_id, g.name AS giver_name, h.receiver_id, r.name AS receiver_name,
                (SELECT COUNT(*) FROM handover_items hi WHERE hi.handover_id = h.id AND hi.given_at IS NOT NULL) AS given,
                (SELECT COUNT(*) FROM handover_items hi WHERE hi.handover_id = h.id AND hi.received_at IS NOT NULL) AS received
            FROM handovers h JOIN users g ON g.id = h.giver_id JOIN users r ON r.id = h.receiver_id
            WHERE $where ORDER BY h.status = 'open' DESC, h.id DESC LIMIT 100", $params)];
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
