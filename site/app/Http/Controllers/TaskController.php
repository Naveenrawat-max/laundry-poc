<?php
/** Tasks: one visit to one building. Lists per role, assignment, closing a stop with proof, and the next swap. */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Scan;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TaskController extends Controller
{
    private const SELECT = 'SELECT t.id, t.type, t.status, t.due_date, t.completed_at, t.contract_id, t.building_id, t.previous_task_id,
            t.assigned_to, u.name AS assigned_name, t.signed_name, t.issue_note, t.photo_path IS NOT NULL AS has_proof,
            b.name AS building_name, b.street, b.postcode, b.city, a.name AS area_name,
            c.id AS customer_id, c.name AS customer_name, c.contact_name, c.contact_phone,
            o.id AS order_id, o.number AS order_number
        FROM tasks t
        JOIN buildings b ON b.id = t.building_id
        LEFT JOIN areas a ON a.id = b.area_id
        JOIN customers c ON c.id = b.customer_id
        LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN orders o ON o.task_id = t.id';

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
        $task['in_building'] = rows("SELECT i.id, i.sku, i.epc, i.zone_id, i.delivered_on, i.swap_due_date, it.name AS type
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
              AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.task_id = t.id)
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
            $tasks = rows(self::SELECT . " WHERE t.assigned_to = ? AND t.status = 'open' AND o.id IS NULL ORDER BY t.due_date, t.id", [$user->id]);
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

    /** Office expansion uses the same stop contract as the driver; linked exact mats come from the order. */
    public function show(int $id): array
    {
        return ['task' => self::stopDetails(self::view($id))];
    }

    /**
     * The office creates a task by hand. A collection: pick-up only, when a contract ends.
     * A swap: for a building of a running contract that has no visit planned, because its swap was cancelled.
     * Every other swap is planned by closing a stop.
     */
    public function store(Request $request): array
    {
        $in = $request->validate([
            'type' => 'nullable|in:collection,swap',
            'contract_id' => 'required|integer|min:0',
            'building_id' => 'required|integer|min:0',
            'due_date' => 'required|date_format:Y-m-d',
        ]);
        $type = $in['type'] ?? 'collection';
        $contractId = (int) $in['contract_id'];
        $buildingId = (int) $in['building_id'];

        return DB::transaction(function () use ($type, $contractId, $buildingId, $in): array {
            // The lock makes two clicks on "create" wait for each other, so a building gets one swap and not two.
            $contract = row('SELECT status,building_id FROM contracts WHERE id = ? FOR UPDATE', [$contractId]);
            if ($contract === null) {
                fail(404, 'not_found', 'No such contract.');
            }
            if ($contract['status'] === 'draft') {
                fail(409, 'not_started', 'A draft contract has no tasks yet. Start it first.');
            }
            if ($type === 'swap' && $contract['status'] !== 'active') {
                fail(409, 'not_active', 'An ended contract gets no more swaps, only collections.');
            }
            $inContract = DB::scalar('SELECT 1 FROM contract_lines l JOIN zones z ON z.id = l.zone_id WHERE l.contract_id = ? AND z.building_id = ? LIMIT 1',
                [$contractId, $buildingId]);
            if ($inContract === null || ($contract['building_id'] !== null && (int) $contract['building_id'] !== $buildingId)) {
                fail(422, 'invalid_reference', 'This building is not part of that contract.');
            }
            $planned = $type !== 'swap' ? null : row("SELECT type, due_date FROM tasks
                WHERE contract_id = ? AND building_id = ? AND status = 'open' AND type IN ('delivery', 'swap') LIMIT 1",
                [$contractId, $buildingId]);
            if ($planned !== null) {
                fail(409, 'already_planned', "This building already has an open {$planned['type']}, due {$planned['due_date']}. Move that one.");
            }
            DB::insert('INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES (?, ?, ?, ?)',
                [$type, $contractId, $buildingId, $in['due_date']]);
            return ['task' => self::view((int) DB::getPdo()->lastInsertId())];
        });
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
            if (DB::scalar('SELECT 1 FROM orders WHERE task_id = ?', [$id]) !== null) {
                fail(409, 'order_managed', 'This task is managed by its office order.');
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

    private const MAX_PHOTO_BYTES = 5 * 1024 * 1024;

    private const MAX_SIGNATURE_BYTES = 1024 * 1024;

    /** A list of tag ids from the body of a stop, uppercase, each one once. */
    private static function epcs(array $in, string $key): array
    {
        $list = $in[$key] ?? [];
        if (!is_array($list) || !array_is_list($list)) {
            fail(422, 'invalid_input', "$key must be a list of tag ids.");
        }
        return array_values(array_unique(array_map(fn ($epc) => Scan::hex($epc, $key), $list)));
    }

    /** The drops of a stop as zone id => tag ids. */
    private static function drops(array $in): array
    {
        $drops = $in['drops'] ?? [];
        if (!is_array($drops) || !array_is_list($drops)) {
            fail(422, 'invalid_input', 'drops must be a list.');
        }
        $byZone = [];
        foreach ($drops as $drop) {
            $zoneId = is_array($drop)
                ? filter_var($drop['zone_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
                : false;
            if ($zoneId === false) {
                fail(422, 'invalid_input', 'Every drop needs zone_id and epcs.');
            }
            $byZone[$zoneId] = array_values(array_unique([...($byZone[$zoneId] ?? []), ...self::epcs($drop, 'epcs')]));
        }
        return $byZone;
    }

    /** An uploaded proof file, checked by its content and not by its name. */
    private static function proof(Request $request, string $field, string $mime, int $maxBytes): UploadedFile
    {
        $file = $request->file($field);
        if (!$file instanceof UploadedFile) {
            fail(422, 'proof_required', "A $field is required to close a stop.");
        }
        // A file above PHP's own upload limit arrives broken, with one of these two error codes.
        $refusedByPhp = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        if ($refusedByPhp || $file->getSize() > $maxBytes) {
            fail(422, 'file_too_big', "The $field is larger than " . intdiv($maxBytes, 1024 * 1024) . ' MB.');
        }
        if (!$file->isValid()) {
            fail(422, 'proof_required', "A $field is required to close a stop.");
        }
        $path = $file->getRealPath();
        $image = @getimagesize($path);   // warns on a broken file; "false" is all this code needs
        if ((new \finfo(FILEINFO_MIME_TYPE))->file($path) !== $mime || !$image || $image['mime'] !== $mime) {
            fail(422, 'wrong_file_type', "The $field must be a " . ($mime === 'image/jpeg' ? 'JPEG' : 'PNG') . ' image.');
        }
        return $file;
    }

    /** The driver closes a stop: the mats he dropped, picked up and got refused, a photo and a signature. */
    public function complete(Request $request, int $id): array
    {
        // Sent as a form: the two files, plus the stop itself as JSON in the field "payload".
        $in = json_decode((string) $request->input('payload'), true);
        if (!is_array($in)) {
            fail(422, 'invalid_input', 'The field payload must hold the stop as JSON.');
        }
        $user = $request->user();
        $source = Scan::source($in);
        $text = Validator::make($in, [
            'signed_name' => 'required|string|max:120',
            'issue_note' => 'nullable|string|max:500',
        ])->validate();
        $signedName = trim($text['signed_name']);
        $note = trim((string) ($text['issue_note'] ?? ''));
        $note = $note === '' ? null : $note;   // a note of spaces is no note
        $drops = self::drops($in);
        $pickups = self::epcs($in, 'pickups');
        $refused = self::epcs($in, 'refused');
        $dropped = array_merge(...array_values($drops));
        $all = [...$dropped, ...$pickups, ...$refused];
        if (count($all) > Scan::MAX_READS) {
            fail(422, 'invalid_input', 'A stop takes at most ' . Scan::MAX_READS . ' mats.');
        }
        if (count($all) !== count(array_unique($all))) {
            fail(422, 'duplicate_tag', 'A mat appears in more than one list of this stop.');
        }
        $photo = self::proof($request, 'photo', 'image/jpeg', self::MAX_PHOTO_BYTES);
        $signature = self::proof($request, 'signature', 'image/png', self::MAX_SIGNATURE_BYTES);

        $saved = [];
        try {
            return DB::transaction(function () use ($id, $user, $source, $signedName, $note, $drops, $dropped, $pickups, $refused, $all, $photo, $signature, &$saved): array {
                $task = row('SELECT t.*, c.status AS contract_status, c.swap_interval_days
                    FROM tasks t JOIN contracts c ON c.id = t.contract_id WHERE t.id = ? FOR UPDATE', [$id]);
                if ($task === null) {
                    fail(404, 'not_found', 'No such task.');
                }
                if (DB::scalar('SELECT 1 FROM orders WHERE task_id = ?', [$id]) !== null) {
                    fail(409, 'order_managed', 'Complete this task through its office order.');
                }
                if ($task['assigned_to'] !== $user->id) {
                    fail(403, 'forbidden', 'This task is not assigned to you.');
                }
                if ($task['status'] !== 'open') {
                    fail(409, 'not_open', "This task is already {$task['status']}.");
                }
                if ($task['type'] === 'collection' && $dropped !== []) {
                    fail(422, 'no_drops', 'A collection task only picks mats up.');
                }

                $items = Scan::itemsByEpc($all, true);
                foreach ($all as $epc) {
                    if (!isset($items[$epc])) {
                        fail(422, 'unknown_tag', "No mat has the tag $epc.");
                    }
                    if (Scan::reserved((int) $items[$epc]['id'])) {
                        fail(409, 'reserved', 'This mat is reserved by an office order.');
                    }
                }
                $zoneIds = array_column(rows('SELECT id FROM zones WHERE building_id = ?', [$task['building_id']]), 'id');

                // Hard rules: breaking one refuses the whole stop.
                foreach (array_keys($drops) as $zoneId) {
                    if (!in_array($zoneId, $zoneIds, true)) {
                        fail(422, 'wrong_zone', "Zone $zoneId is not in this building.");
                    }
                }
                foreach ([...$dropped, ...$refused] as $epc) {
                    $item = $items[$epc];
                    if ($item['status'] !== 'out_for_delivery' || $item['holder_user_id'] !== $user->id) {
                        fail(409, 'wrong_status', "{$item['sku']} is not on your van as out_for_delivery.");
                    }
                }
                foreach ($pickups as $epc) {
                    $item = $items[$epc];
                    if ($item['status'] !== 'at_customer' || !in_array($item['zone_id'], $zoneIds, true)) {
                        fail(409, 'wrong_status', "{$item['sku']} is not at this building.");
                    }
                }

                // Soft rule: drops and pick-ups should match the contract. If they do not, the driver must say why.
                $wanted = [];
                if ($task['type'] !== 'collection') {
                    $lines = rows('SELECT l.zone_id, l.item_type_id, l.quantity FROM contract_lines l JOIN zones z ON z.id = l.zone_id
                        WHERE l.contract_id = ? AND z.building_id = ?', [$task['contract_id'], $task['building_id']]);
                    foreach ($lines as $line) {
                        $wanted["{$line['zone_id']}:{$line['item_type_id']}"] = $line['quantity'];
                    }
                }
                $got = [];
                foreach ($drops as $zoneId => $epcs) {
                    foreach ($epcs as $epc) {
                        $key = "$zoneId:{$items[$epc]['item_type_id']}";
                        $got[$key] = ($got[$key] ?? 0) + 1;
                    }
                }
                $present = $task['type'] === 'delivery' ? [] : array_column(rows("SELECT i.epc FROM items i JOIN zones z ON z.id = i.zone_id
                    WHERE i.status = 'at_customer' AND z.building_id = ?", [$task['building_id']]), 'epc');
                $picked = $pickups;
                // Compare as text. As numbers PHP would call the tag ids "1E3" and "1000" equal.
                sort($present, SORT_STRING);
                sort($picked, SORT_STRING);
                ksort($wanted);
                ksort($got);
                if (($got !== $wanted || $picked !== $present) && $note === null) {
                    fail(422, 'note_required', 'The mats do not match the contract. Add a note to close this stop.');
                }

                $move = function (array $item, string $to, ?int $holder, ?int $zone, string $event) use ($id, $user, $source): void {
                    $moved = DB::update('UPDATE items SET status = ?, holder_user_id = ?, zone_id = ? WHERE id = ? AND status = ?',
                        [$to, $holder, $zone, $item['id'], $item['status']]);
                    if ($moved !== 1) {
                        fail(409, 'wrong_status', "{$item['sku']} changed while the stop was being closed.");
                    }
                    // A drop is logged with the zone the mat went to, a pick-up with the zone it came from.
                    Scan::log($item, $user->id, $event, $to, $source, ['task_id' => $id, 'zone_id' => $zone ?? $item['zone_id']]);
                };
                $deliveryDate = today_nl();
                foreach ($drops as $zoneId => $epcs) {
                    foreach ($epcs as $epc) {
                        $move($items[$epc], 'at_customer', null, $zoneId, 'deliver');
                        DB::update('UPDATE items SET delivered_on = ?, swap_due_date = ?, delivered_contract_id = ? WHERE id = ?',
                            [$deliveryDate, days_after($deliveryDate, (int) $task['swap_interval_days']), $task['contract_id'], $items[$epc]['id']]);
                    }
                }
                foreach ($refused as $epc) {
                    $move($items[$epc], 'refused', $user->id, null, 'refuse');
                }
                foreach ($pickups as $epc) {
                    $move($items[$epc], 'collected', $user->id, null, 'collect');
                }

                // Proof files get a server-made name and live on the "local" disk, outside the web root.
                $stem = "task-$id-" . bin2hex(random_bytes(8));
                foreach ([[$photo, "$stem.jpg"], [$signature, "$stem.png"]] as [$file, $name]) {
                    if ($file->storeAs('proofs', $name, 'local') === false) {
                        fail(500, 'storage', 'The proof could not be stored.');
                    }
                    $saved[] = "proofs/$name";
                }
                $nextId = self::finishVisit($task, "$stem.jpg", "$stem.png", $signedName, $note, $deliveryDate, $dropped !== []);
                return ['task' => self::view($id), 'next_task_id' => $nextId];
            });
        } catch (\Throwable $e) {
            // Nothing was saved in the database, so nothing may stay on disk.
            Storage::disk('local')->delete($saved);
            throw $e;
        }
    }

    /** Both legacy stops and exact-mat orders finish the locked visit through this single lifecycle. */
    public static function finishVisit(array $task, string $photo, string $signature, string $signedName, ?string $note, string $visitDate, bool $hasDelivery): ?int
    {
        $changed = DB::update("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP(), photo_path = ?, signature_path = ?,
            signed_name = ?, issue_note = ? WHERE id = ? AND status = 'open'", [$photo, $signature, $signedName, $note, $task['id']]);
        if ($changed !== 1) {
            fail(409, 'not_open', 'This contract visit is no longer open.');
        }
        // Today is D. The next swap is due on D + n; previous_task_id allows exactly one successor.
        if ($hasDelivery && $task['type'] !== 'collection' && $task['contract_status'] === 'active') {
            DB::insert("INSERT INTO tasks (type, contract_id, building_id, previous_task_id, due_date) VALUES ('swap', ?, ?, ?, ?)",
                [$task['contract_id'], $task['building_id'], $task['id'], days_after($visitDate, (int) $task['swap_interval_days'])]);

            return (int) DB::getPdo()->lastInsertId();
        }

        return null;
    }

    public function photo(int $id): StreamedResponse
    {
        return self::proofFile($id, 'photo');
    }

    public function signature(int $id): StreamedResponse
    {
        return self::proofFile($id, 'signature');
    }

    /** A proof file, for the office only. $kind is "photo" or "signature". */
    private static function proofFile(int $id, string $kind): StreamedResponse
    {
        $task = row('SELECT photo_path, signature_path FROM tasks WHERE id = ?', [$id]);
        if ($task === null) {
            fail(404, 'not_found', 'No such task.');
        }
        $name = $task[$kind . '_path'];
        // basename(): even a tampered path in the database cannot leave the proofs folder.
        $path = $name === null ? null : 'proofs/' . basename($name);
        if ($path === null || !Storage::disk('local')->exists($path)) {
            fail(404, 'no_proof', 'This task has no proof file.');
        }
        return Storage::disk('local')->response($path, null, ['Content-Type' => $kind === 'photo' ? 'image/jpeg' : 'image/png']);
    }
}
