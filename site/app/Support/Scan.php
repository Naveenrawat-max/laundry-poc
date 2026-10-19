<?php
/** What every scan shares: reading tag ids, finding the mats, the mat as a scanner sees it, and writing history. */
declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Scan
{
    public const STATUSES = ['registered', 'ready', 'qc_passed', 'rewash', 'repair', 'scrapped',
        'out_for_delivery', 'refused', 'at_customer', 'collected', 'in_washing'];

    // The statuses in which a driver carries the mat. The database demands a holder exactly for these.
    public const HELD = ['out_for_delivery', 'refused', 'collected'];

    public const ITEM_SELECT = 'SELECT i.*, t.name AS type_name FROM items i JOIN item_types t ON t.id = i.item_type_id';

    // A pile read hears every tag many times, so one request may carry far more reads than mats.
    public const MAX_READS = 5000;

    /** A tag id: 8 to 64 hex characters, returned in uppercase. $key names the field in the error message. */
    public static function hex(mixed $value, string $key): string
    {
        $hex = is_string($value) ? strtoupper(trim($value)) : '';
        if (!preg_match('/^[0-9A-F]{8,64}$/', $hex)) {
            fail(422, 'invalid_tag', "$key must be 8 to 64 hex characters.");
        }
        return $hex;
    }

    /** Where a scan came from. The simulator may only speak while it is switched on. */
    public static function source(array $in): string
    {
        $source = $in['source'] ?? null;
        if (!in_array($source, ['simulator', 'c72'], true)) {
            fail(422, 'invalid_input', 'source must be one of: simulator, c72.');
        }
        if ($source === 'simulator' && !config('laundry.simulator')) {
            fail(403, 'simulator_off', 'The scanner simulator is switched off.');
        }
        return $source;
    }

    /** The EPCs of a scan: uppercase, each one once, in the order they were first heard. */
    public static function reads(array $in): array
    {
        $reads = $in['reads'] ?? null;
        if (!is_array($reads) || !array_is_list($reads) || $reads === [] || count($reads) > self::MAX_READS) {
            fail(422, 'invalid_input', 'reads must be a list of 1 to ' . self::MAX_READS . ' tag reads.');
        }
        $epcs = [];
        foreach ($reads as $read) {
            $epcs[] = self::hex(is_array($read) ? ($read['epc'] ?? null) : null, 'epc');
        }
        // Keep the EPCs as values. As array keys PHP would turn an all-digit EPC into a number.
        return array_values(array_unique($epcs));
    }

    /** The mats with these EPCs, keyed by EPC. Read each mat's EPC from $item['epc'], never from the key. */
    public static function itemsByEpc(array $epcs, bool $lock = false): array
    {
        if ($epcs === []) {
            return [];
        }
        $sql = self::ITEM_SELECT . ' WHERE i.epc IN (' . marks($epcs) . ') ORDER BY i.id' . ($lock ? ' FOR UPDATE' : '');
        return array_column(rows($sql, $epcs), null, 'epc');
    }

    public static function itemById(int $id, bool $lock = false): array
    {
        $item = row(self::ITEM_SELECT . ' WHERE i.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if ($item === null) {
            fail(404, 'not_found', 'No such mat.');
        }
        return $item;
    }

    /** Call after locking the item: legacy moves cannot steal an order's exact mat. */
    public static function reserved(int $id): bool
    {
        return DB::scalar('SELECT 1 FROM order_items WHERE reserved_item_id = ? LIMIT 1 FOR UPDATE', [$id]) !== null;
    }

    /** A mat as every scanning role may see it. It never says at which customer the mat lies. */
    public static function view(array $item): array
    {
        return [
            'id' => $item['id'],
            'sku' => $item['sku'],
            'type' => $item['type_name'],
            'status' => $item['status'],
            'wash_count' => $item['wash_count'],
            'current_wash_date' => $item['current_wash_date'],
            'previous_wash_date' => $item['previous_wash_date'],
        ];
    }

    /**
     * Adds one row to the mat history. $item is the mat as it was before the change, so its status is the "from".
     * $extra may hold handover_id, task_id, zone_id and note.
     */
    public static function log(array $item, int $userId, string $type, string $to, string $source, array $extra = []): void
    {
        DB::insert('INSERT INTO item_events (item_id, user_id, event_type, from_status, to_status, handover_id, task_id, zone_id, note, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$item['id'], $userId, $type, $item['status'], $to, $extra['handover_id'] ?? null, $extra['task_id'] ?? null,
                $extra['zone_id'] ?? null, $extra['note'] ?? null, $source]);
    }

    /** One line of a scan answer. */
    public static function result(string $epc, string $result, ?array $item, ?string $reason = null): array
    {
        $line = ['epc' => $epc, 'result' => $result, 'item' => $item === null ? null : self::view($item)];
        if ($reason !== null) {
            $line['reason'] = $reason;
        }
        return $line;
    }
}
