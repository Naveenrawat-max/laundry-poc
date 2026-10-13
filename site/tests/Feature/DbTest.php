<?php
/** The database as the app meets it: the seeded start, UTC, strict mode, typed values, and the schema's own refusals. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class DbTest extends ApiTestCase
{
    public function test_every_test_starts_with_40_ready_mats_and_six_users(): void
    {
        $this->assertSame(40, (int) DB::scalar("SELECT COUNT(*) FROM items WHERE status = 'ready'"));
        $this->assertEqualsCanonicalizing(['driver', 'driver2', 'inventory', 'office', 'outbound', 'washing'], array_keys($this->users));
    }

    public function test_a_change_made_in_a_test_is_rolled_back_after_it(): void
    {
        // Whatever the order of the tests in this file, the one above still counts 40.
        DB::update("UPDATE items SET status = 'registered' WHERE sku = 'MOCK-0001'");
        $this->assertSame(39, (int) DB::scalar("SELECT COUNT(*) FROM items WHERE status = 'ready'"));
    }

    public function test_connection_runs_in_utc(): void
    {
        $this->assertSame('+00:00', DB::scalar('SELECT @@session.time_zone'));
    }

    public function test_strict_mode_refuses_an_unknown_status(): void
    {
        $this->expectException(QueryException::class);
        DB::update("UPDATE items SET status = 'bogus' WHERE sku = 'MOCK-0001'");
    }

    public function test_database_refuses_a_mat_at_a_customer_without_a_zone(): void
    {
        $this->expectException(QueryException::class);
        DB::update("UPDATE items SET status = 'at_customer' WHERE sku = 'MOCK-0001'");
    }

    public function test_integers_are_bound_as_integers_so_limit_works(): void
    {
        $this->assertCount(2, rows('SELECT id FROM items ORDER BY id LIMIT ? OFFSET ?', [2, 1]));
    }

    public function test_numbers_come_back_as_numbers(): void
    {
        $this->assertSame(0, $this->item(1)['wash_count']);
    }

    public function test_days_after_adds_calendar_days(): void
    {
        $this->assertSame('2026-11-02', days_after('2026-10-19', 14));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', today_nl());
    }
}
