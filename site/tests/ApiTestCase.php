<?php
/** Base class for every API test: a seeded test database, each test rolled back at its end, and helpers to call the API. */
declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

abstract class ApiTestCase extends TestCase
{
    // Rebuilds the test database once per run (db/schema.sql, then TestSeeder) and wraps every test in a
    // transaction that is rolled back. So every test starts with the same 40 ready mats and six users.
    use RefreshDatabase;

    public const PASSWORD = 'test-password-1';

    /** The seeder RefreshDatabase runs after it rebuilt the tables. */
    protected $seeder = TestSeeder::class;

    /** @var array<string,int> username => user id */
    protected array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = DB::table('users')->pluck('id', 'username')->all();
        Storage::fake('local');   // proof files of a test never land in the real storage folder
    }

    /**
     * Calls the API in-process. Path is relative to /api/v1, for example "/items?page=2".
     * Returns the status and the JSON body; for a file answer the body is the response itself.
     * With $files the call is sent as a form: the files plus the JSON in the field "payload".
     */
    protected function api(string $method, string $path, array $body = [], array $files = []): array
    {
        // Every call is a new request: the logged-in user is read fresh from the session, as on the server.
        Auth::forgetGuards();
        // The Origin of the web app is what makes Sanctum give the call the session cookie.
        $headers = ['Origin' => config('app.url'), 'Accept' => 'application/json'];
        $response = $files === []
            ? $this->json($method, '/api/v1' . $path, $body, $headers)
            : $this->call($method, '/api/v1' . $path, ['payload' => json_encode($body)], [], $files,
                $this->transformHeadersToServerVars($headers));
        $isJson = str_contains((string) $response->headers->get('Content-Type'), 'json');
        return [$response->status(), $isJson ? $response->json() : $response];
    }

    /** Puts a user in the session, as a login would. */
    protected function loginAs(string $username): void
    {
        $this->withSession([Auth::guard('web')->getName() => $this->users[$username]]);
    }

    protected function logout(): void
    {
        $this->flushSession();
    }

    /** Asserts status 200 and returns the body. */
    protected function ok(array $response): array
    {
        $this->assertSame(200, $response[0], json_encode($response[1]));
        return $response[1];
    }

    protected function assertError(int $status, string $code, array $response): void
    {
        $this->assertSame([$status, $code], [$response[0], $response[1]['error']['code'] ?? null], json_encode($response[1]));
    }

    /** EPC of mock mat n, as db/seed.sql builds it. */
    protected function epc(int $n): string
    {
        return '4D4F434B' . str_pad(strtoupper(dechex($n)), 16, '0', STR_PAD_LEFT);
    }

    /** A scan body for mock mats n, n, n. */
    protected function reads(int ...$mats): array
    {
        return ['source' => 'simulator', 'reads' => array_map(fn (int $n) => ['epc' => $this->epc($n)], $mats)];
    }

    /** The items row of mock mat n. */
    protected function item(int $n): array
    {
        return row('SELECT * FROM items WHERE sku = ?', [sprintf('MOCK-%04d', $n)]);
    }

    /** Puts mock mats straight into a status, to arrange a test. */
    protected function setItems(array $mats, string $status, ?int $holder = null, ?int $zone = null): void
    {
        foreach ($mats as $n) {
            DB::update('UPDATE items SET status = ?, holder_user_id = ?, zone_id = ? WHERE sku = ?',
                [$status, $holder, $zone, sprintf('MOCK-%04d', $n)]);
        }
    }

    /** The history rows of mock mat n, oldest first. */
    protected function events(int $n): array
    {
        return rows('SELECT * FROM item_events WHERE item_id = ? ORDER BY id', [$this->item($n)['id']]);
    }

    /** Logs in as the giver and opens a handover. Returns its id. */
    protected function openHandover(string $giver, string $type, string $receiver): int
    {
        $this->loginAs($giver);
        return $this->ok($this->api('POST', '/handovers',
            ['type' => $type, 'receiver_id' => $this->users[$receiver]]))['handover']['id'];
    }

    /** Logs in as $who and scans mock mats into a handover. Returns the scan answer. */
    protected function scanHandover(string $who, int $handover, int ...$mats): array
    {
        $this->loginAs($who);
        return $this->ok($this->api('POST', "/handovers/$handover/scan", $this->reads(...$mats)));
    }
}
