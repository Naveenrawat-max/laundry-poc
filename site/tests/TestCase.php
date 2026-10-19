<?php
/** Base of every test. Refuses to start without a test database of its own, because the tests wipe their database. */
declare(strict_types=1);

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Check loaded configuration before RefreshDatabase can rebuild a disposable workflow database. */
    public function createApplication()
    {
        $app = parent::createApplication();
        if (getenv('LAUNDRY_DISPOSABLE_TEST_DB') === '1') {
            $connection = $app['config']['database.default'];
            $db = $app['config']["database.connections.$connection"];
            if ($connection !== 'mysql' || ($db['host'] ?? null) !== '127.0.0.1'
                || (string) ($db['port'] ?? '') !== '33517' || ($db['database'] ?? null) !== 'laundry_order_test'
                || ($db['username'] ?? null) !== 'root' || ($db['password'] ?? '') !== '' || !empty($db['url'])) {
                throw new \RuntimeException('Refusing to refresh: loaded database config is not the disposable loopback workflow database.');
            }
        }
        return $app;
    }

    protected function setUp(): void
    {
        self::refuseWithoutATestDatabase();
        parent::setUp();
    }

    /** Ordinary runs require a separate .env.testing; workflow runs require the exact disposable loopback target. */
    private static function refuseWithoutATestDatabase(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        if (getenv('LAUNDRY_DISPOSABLE_TEST_DB') === '1') {
            foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '33517', 'DB_DATABASE' => 'laundry_order_test', 'DB_USERNAME' => 'root',
                'DB_PASSWORD' => '', 'DB_URL' => ''] as $key => $expected) {
                if ((getenv($key) ?: '') !== $expected) {
                    throw new \RuntimeException('Refusing to run: disposable database process settings are incomplete.');
                }
            }
            $checked = true;
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
