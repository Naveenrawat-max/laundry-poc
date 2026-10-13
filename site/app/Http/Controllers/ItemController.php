<?php
/** Mats: registering, releasing, the quality check, office corrections, the mat list and the mat history. */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ItemController extends Controller
{
    /** Registers a new mat with its EPC and its two tags. */
    public function store(Request $request): array
    {
        $source = Scan::source($request->all());
        $epc = Scan::hex($request->input('epc'), 'epc');
        $tidPrimary = Scan::hex($request->input('tid_primary'), 'tid_primary');
        $tidSecondary = Scan::hex($request->input('tid_secondary'), 'tid_secondary');
        $in = $request->validate(['sku' => 'required|string|max:64', 'item_type_id' => 'required|integer|min:0']);
        $sku = $in['sku'];
        $typeId = (int) $in['item_type_id'];
        $userId = $request->user()->id;

        if ($tidPrimary === $tidSecondary) {
            fail(422, 'same_tid', 'The two tags of a mat must have different TIDs.');
        }
        if (DB::scalar('SELECT 1 FROM item_types WHERE id = ? AND is_active = 1', [$typeId]) === null) {
            fail(422, 'invalid_reference', 'Unknown mat type.');
        }

        return DB::transaction(function () use ($source, $epc, $tidPrimary, $tidSecondary, $sku, $typeId, $userId): array {
            // Also catches a TID that is primary on one mat and secondary on another, which the unique keys cannot.
            $clash = row('SELECT sku FROM items WHERE epc = ? OR sku = ? OR tid_primary IN (?, ?) OR tid_secondary IN (?, ?) LIMIT 1',
                [$epc, $sku, $tidPrimary, $tidSecondary, $tidPrimary, $tidSecondary]);
            if ($clash !== null) {
                fail(409, 'duplicate', "The EPC, the SKU or a TID is already in use on {$clash['sku']}.");
            }
            DB::insert('INSERT INTO items (item_type_id, sku, epc, tid_primary, tid_secondary) VALUES (?, ?, ?, ?, ?)',
                [$typeId, $sku, $epc, $tidPrimary, $tidSecondary]);
            $item = Scan::itemById((int) DB::getPdo()->lastInsertId());
            Scan::log(['status' => null] + $item, $userId, 'register', 'registered', $source);
            return ['item' => Scan::view($item)];
        });
    }

    private const QC_RESULTS = ['pass' => 'qc_passed', 'rewash' => 'rewash', 'repair' => 'repair', 'scrap' => 'scrapped'];

    private const PER_PAGE = 50;

    private const OFFICE_SELECT = 'SELECT i.*, t.name AS type_name, u.name AS holder_name, z.name AS zone_name,
            b.name AS building_name, c.name AS customer_name
        FROM items i
        JOIN item_types t ON t.id = i.item_type_id
        LEFT JOIN users u ON u.id = i.holder_user_id
        LEFT JOIN zones z ON z.id = i.zone_id
        LEFT JOIN buildings b ON b.id = z.building_id
        LEFT JOIN customers c ON c.id = b.customer_id';

    /** A mat as the office sees it: with its tags, who carries it and where it lies. */
    private static function officeView(array $item): array
    {
        return Scan::view($item) + [
            'epc' => $item['epc'],
            'tid_primary' => $item['tid_primary'],
            'tid_secondary' => $item['tid_secondary'],
            'item_type_id' => $item['item_type_id'],
            'holder' => $item['holder_user_id'] === null ? null
                : ['id' => $item['holder_user_id'], 'name' => $item['holder_name']],
            'location' => $item['zone_id'] === null ? null
                : ['zone_id' => $item['zone_id'], 'zone' => $item['zone_name'],
                    'building' => $item['building_name'], 'customer' => $item['customer_name']],
        ];
    }

    /** Releases registered and repaired mats for use. */
    public function ready(Request $request): array
    {
        $source = Scan::source($request->all());
        $epcs = Scan::reads($request->all());
        $userId = $request->user()->id;
        return DB::transaction(function () use ($source, $epcs, $userId): array {
            $items = Scan::itemsByEpc($epcs, true);
            $results = [];
            foreach ($epcs as $epc) {
                $item = $items[$epc] ?? null;
                if ($item === null) {
                    $results[] = Scan::result($epc, 'unknown', null);
                } elseif ($item['status'] === 'ready') {
                    $results[] = Scan::result($epc, 'found', $item);   // a second scan: nothing to change
                } elseif (!in_array($item['status'], ['registered', 'repair'], true)) {
                    $results[] = Scan::result($epc, 'rejected', $item, "{$item['sku']} is {$item['status']}, not registered or repair.");
                } else {
                    DB::update("UPDATE items SET status = 'ready' WHERE id = ? AND status IN ('registered', 'repair')", [$item['id']]);
                    Scan::log($item, $userId, 'ready', 'ready', $source);
                    $results[] = Scan::result($epc, 'found', ['status' => 'ready'] + $item);
                }
            }
            return ['results' => $results, 'missing' => []];
        });
    }

    /** The quality check of one mat: pass, rewash, repair or scrap. */
    public function qc(Request $request): array
    {
        $source = Scan::source($request->all());
        $epc = Scan::hex($request->input('epc'), 'epc');
        $in = $request->validate([
            'result' => 'required|in:' . implode(',', array_keys(self::QC_RESULTS)),
            'note' => 'nullable|string|max:500',
        ]);
        $to = self::QC_RESULTS[$in['result']];
        $note = $in['note'] ?? null;
        $userId = $request->user()->id;
        return DB::transaction(function () use ($source, $epc, $to, $note, $userId): array {
            $item = Scan::itemsByEpc([$epc], true)[$epc] ?? null;
            if ($item === null) {
                $result = Scan::result($epc, 'unknown', null);
            } elseif ($item['status'] === $to) {
                $result = Scan::result($epc, 'found', $item);   // a second scan: nothing to change
            } elseif ($item['status'] !== 'ready') {
                $result = Scan::result($epc, 'rejected', $item, "{$item['sku']} is {$item['status']}, not ready.");
            } else {
                DB::update("UPDATE items SET status = ? WHERE id = ? AND status = 'ready'", [$to, $item['id']]);
                Scan::log($item, $userId, 'qc', $to, $source, ['note' => $note]);
                $result = Scan::result($epc, 'found', ['status' => $to] + $item);
            }
            return ['results' => [$result], 'missing' => []];
        });
    }

    /** The office may put a mat into any status, but only with a note, and never into a state the database refuses. */
    public function correct(Request $request, int $id): array
    {
        $in = $request->validate([
            'status' => 'required|in:' . implode(',', Scan::STATUSES),
            'holder_user_id' => 'nullable|integer|min:0',
            'zone_id' => 'nullable|integer|min:0',
            'note' => 'required|string|max:500',
        ]);
        $status = $in['status'];
        $holder = isset($in['holder_user_id']) ? (int) $in['holder_user_id'] : null;
        $zone = isset($in['zone_id']) ? (int) $in['zone_id'] : null;
        $note = $in['note'];
        $userId = $request->user()->id;

        if (($status === 'at_customer') !== ($zone !== null)) {
            fail(422, 'rule_violation', 'A mat has a zone exactly when it is at a customer.');
        }
        if (in_array($status, Scan::HELD, true) !== ($holder !== null)) {
            fail(422, 'rule_violation', 'A mat has a holder exactly when a driver carries it.');
        }
        if ($holder !== null && DB::scalar("SELECT 1 FROM users WHERE id = ? AND role = 'driver' AND is_active = 1", [$holder]) === null) {
            fail(422, 'invalid_reference', 'The holder must be an active driver.');
        }

        return DB::transaction(function () use ($id, $status, $holder, $zone, $note, $userId): array {
            $item = Scan::itemById($id, true);
            DB::update('UPDATE items SET status = ?, holder_user_id = ?, zone_id = ? WHERE id = ?', [$status, $holder, $zone, $id]);
            Scan::log($item, $userId, 'correction', $status, 'web', ['note' => $note, 'zone_id' => $zone]);
            return ['item' => Scan::view(['status' => $status] + $item)];
        });
    }

    /** The mat list. Each scanning role sees only the mats that are with it; the office sees all, with more detail. */
    public function index(Request $request): array
    {
        $filter = $request->validate([
            'status' => 'nullable|string|max:200',   // one status, or several with commas between: a place is a group of statuses
            'type' => 'nullable|integer|min:0',
            'q' => 'nullable|string|max:64',
            'page' => 'nullable|integer|min:0',
        ]);
        $statuses = isset($filter['status']) ? array_unique(explode(',', $filter['status'])) : [];
        if (array_diff($statuses, Scan::STATUSES) !== []) {
            fail(422, 'invalid_input', 'Unknown status. Use: ' . implode(', ', Scan::STATUSES) . '.');
        }
        $user = $request->user();

        $where = ['1 = 1'];
        $params = [];
        if ($user->role === 'inventory') {
            $where[] = "i.status IN ('registered', 'ready', 'qc_passed', 'rewash', 'repair')";
        } elseif ($user->role === 'driver') {
            $where[] = 'i.holder_user_id = ?';
            $params[] = $user->id;
        } elseif ($user->role === 'washing') {
            $where[] = "i.status = 'in_washing'";
        }
        if ($statuses !== []) {
            $where[] = 'i.status IN (' . marks($statuses) . ')';
            array_push($params, ...$statuses);
        }
        if (isset($filter['type'])) {
            $where[] = 'i.item_type_id = ?';
            $params[] = (int) $filter['type'];
        }
        if (isset($filter['q'])) {
            // Escape the LIKE wildcards, so a search for "%" finds a literal "%" and not every mat.
            $like = '%' . addcslashes($filter['q'], '%_\\') . '%';
            $where[] = '(i.sku LIKE ? OR i.epc LIKE ?)';
            array_push($params, $like, $like);
        }
        $page = max(1, (int) ($filter['page'] ?? 1));

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);
        $total = (int) DB::scalar('SELECT COUNT(*) FROM items i' . $sqlWhere, $params);
        $items = rows(self::OFFICE_SELECT . $sqlWhere . ' ORDER BY i.sku LIMIT ? OFFSET ?',
            [...$params, self::PER_PAGE, ($page - 1) * self::PER_PAGE]);

        $view = $user->role === 'office' ? self::officeView(...) : Scan::view(...);
        return ['items' => array_map($view, $items), 'page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total];
    }

    /** One mat with its history. */
    /** How many mats are in each status, for the dashboard. A status no mat has is left out. */
    public function counts(): array
    {
        $counts = array_column(rows('SELECT status, COUNT(*) AS n FROM items GROUP BY status'), 'n', 'status');
        // An object also when there is no mat yet: an empty PHP array would be sent as a list.
        return ['counts' => (object) array_map('intval', $counts)];
    }

    public function show(Request $request, int $id): array
    {
        $item = row(self::OFFICE_SELECT . ' WHERE i.id = ?', [$id]);
        if ($item === null) {
            fail(404, 'not_found', 'No such mat.');
        }
        $events = rows('SELECT e.id, e.created_at, e.event_type, e.from_status, e.to_status, e.source, e.note,
                e.handover_id, e.task_id, u.name AS user_name, z.name AS zone_name
            FROM item_events e
            JOIN users u ON u.id = e.user_id
            LEFT JOIN zones z ON z.id = e.zone_id
            WHERE e.item_id = ? ORDER BY e.id', [$id]);

        if ($request->user()->role === 'office') {
            return ['item' => self::officeView($item), 'events' => $events];
        }
        // Washing sees what happened and who did it, but nothing that names the customer.
        $hidden = array_flip(['note', 'task_id', 'zone_name']);
        return ['item' => Scan::view($item), 'events' => array_map(fn (array $e) => array_diff_key($e, $hidden), $events)];
    }
}
