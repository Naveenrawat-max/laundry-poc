<?php
/** Recovers missing legacy placement dates from accepted delivery history without changing known snapshots. */
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $placements = DB::select("SELECT i.id, i.status, i.holder_user_id, i.zone_id, i.updated_at,
                    i.delivered_on, i.swap_due_date, i.delivered_contract_id,
                    e.id AS delivery_event_id, e.created_at AS delivered_at,
                    c.id AS recovered_contract_id, c.swap_interval_days
                FROM items i
                JOIN item_events e ON e.item_id = i.id AND e.event_type = 'deliver' AND e.to_status = 'at_customer'
                JOIN tasks t ON t.id = e.task_id AND t.status = 'done' AND t.completed_at IS NOT NULL
                JOIN zones z ON z.id = e.zone_id AND z.building_id = t.building_id
                JOIN buildings b ON b.id = z.building_id
                JOIN contracts c ON c.id = t.contract_id AND c.customer_id = b.customer_id
                    AND (c.building_id IS NULL OR c.building_id = b.id)
                WHERE i.delivered_on IS NULL AND i.swap_due_date IS NULL AND i.delivered_contract_id IS NULL
                    AND (i.status <> 'at_customer' OR i.zone_id = e.zone_id)
                    AND NOT EXISTS (SELECT 1 FROM item_events newer
                        WHERE newer.item_id = i.id AND newer.id > e.id AND newer.event_type = 'deliver')
                    AND NOT EXISTS (SELECT 1 FROM item_events correction
                        WHERE correction.item_id = i.id AND correction.id > e.id
                            AND correction.event_type = 'correction' AND correction.to_status = 'at_customer')
                ORDER BY i.id FOR UPDATE");

            if ($placements === []) {
                echo "No missing legacy delivery snapshots to recover.\n";
                return;
            }

            $backup = 'date-recovery/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
            if (! Storage::disk('local')->put($backup, json_encode([
                'database' => DB::connection()->getDatabaseName(),
                'before' => $placements,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Could not save mat date before-images; no dates were changed.');
            }

            $recovered = 0;
            foreach ($placements as $placement) {
                // Legacy history uses real UTC; new simulated deliveries already save their explicit business day.
                $delivered = (new DateTimeImmutable($placement->delivered_at, new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Europe/Amsterdam'))->setTime(0, 0);
                $due = $delivered->modify('+'.(int) $placement->swap_interval_days.' days');
                $recovered += DB::update('UPDATE items SET delivered_on = ?, swap_due_date = ?, delivered_contract_id = ?,
                        updated_at = updated_at WHERE id = ? AND delivered_on IS NULL AND swap_due_date IS NULL
                        AND delivered_contract_id IS NULL AND status = ? AND zone_id <=> ? AND holder_user_id <=> ?',
                    [$delivered->format('Y-m-d'), $due->format('Y-m-d'), $placement->recovered_contract_id,
                        $placement->id, $placement->status, $placement->zone_id, $placement->holder_user_id]);
            }
            echo "Recovered {$recovered} legacy mat delivery snapshots. Private before-images: {$backup}\n";
        });
    }

    public function down(): void
    {
        // Preserve recovered history: a rollback must not erase a later accepted delivery or contract change.
    }
};
