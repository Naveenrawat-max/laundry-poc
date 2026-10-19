<?php
/** Shared demo business date; audit timestamps and the operating system clock stay real. */
declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BusinessClock
{
    public static function enabled(): bool
    {
        if (!config('laundry.simulator') || app()->isProduction()) {
            return false;
        }
        $attributes = request()->attributes;
        if (!$attributes->has('business_clock_ready')) {
            $attributes->set('business_clock_ready', Schema::hasTable('demo_clock'));
        }
        return $attributes->getBoolean('business_clock_ready');
    }

    public static function today(): string
    {
        $real = (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
        $simulated = self::enabled() ? DB::scalar('SELECT business_date FROM demo_clock WHERE id = 1') : null;
        return $simulated === null ? $real : max($real, $simulated);
    }

    public static function state(): array
    {
        $today = self::today();
        return ['enabled' => self::enabled(), 'today' => $today,
            'simulated' => self::enabled() && DB::scalar('SELECT business_date FROM demo_clock WHERE id = 1') !== null,
            'next_due_date' => self::enabled() ? self::nextDue($today) : null];
    }

    private static function nextDue(string $today): ?string
    {
        return DB::scalar("SELECT MIN(due_date) FROM (
            SELECT due_date FROM tasks WHERE status = 'open' AND due_date > ?
            UNION ALL
            SELECT due_date FROM orders WHERE status IN ('pending_qc','ready','in_progress') AND due_date > ?
            UNION ALL
            SELECT i.swap_due_date FROM items i JOIN contracts c ON c.id = i.delivered_contract_id
                WHERE i.status = 'at_customer' AND c.status = 'active' AND i.swap_due_date > ?
        ) future_dates", [$today, $today, $today]);
    }

    public static function skip(): array
    {
        return DB::transaction(function (): array {
            row('SELECT id FROM demo_clock WHERE id = 1 FOR UPDATE');
            $next = self::nextDue(self::today());
            if ($next === null) {
                fail(409, 'no_future_due', 'No future task, order or mat swap is due.');
            }
            DB::update('UPDATE demo_clock SET business_date = ? WHERE id = 1', [$next]);
            return self::state();
        });
    }

    public static function reset(): array
    {
        return DB::transaction(function (): array {
            row('SELECT id FROM demo_clock WHERE id = 1 FOR UPDATE');
            DB::update('UPDATE demo_clock SET business_date = NULL WHERE id = 1');
            return self::state();
        });
    }
}
