<?php
/** Base of every test. Refuses to start without a test database of its own, because the tests wipe their database. */
declare(strict_types=1);

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        self::refuseWithoutATestDatabase();
        parent::setUp();
    }

    /** The tests only ever use the database of .env.testing, and never the one .env names. */
    private static function refuseWithoutATestDatabase(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $read = fn (string $file): array => is_file($file) ? Dotenv::parse(file_get_contents($file)) : [];
        $test = $read(dirname(__DIR__) . '/.env.testing');
        $real = $read(dirname(__DIR__) . '/.env');
        if (($test['DB_DATABASE'] ?? '') === '') {
            fwrite(STDERR, "Refusing to run: site/.env.testing with the test database is missing.\n");
            exit(1);
        }
        $same = fn (string $key): bool => ($real[$key] ?? null) === ($test[$key] ?? null);
        if ($same('DB_DATABASE') && $same('DB_HOST')) {
            fwrite(STDERR, "Refusing to run: site/.env.testing names the same database as site/.env, and the tests wipe it.\n");
            exit(1);
        }
        $checked = true;
    }
}
