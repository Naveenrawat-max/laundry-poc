# Backend API Implementation Plan

Version: 0.1.0

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the PHP JSON API under `/api/v1` that holds every rule of the Proti mat tracking POC: login, roles, mat lifecycle, handovers, contracts, tasks and proof files.

**Architecture:** Plain PHP 8.3+ with PDO, no framework and no runtime dependency, so it runs on TransIP web hosting as uploaded files. One route table maps method and path to a handler function; `dispatch()` checks login, CSRF token and role before any handler runs. Every status change is a conditional `UPDATE ... WHERE status = <old status>` inside a transaction plus one `item_events` row, which is what makes every scan safe to send twice.

**Tech Stack:** PHP 8.3+ (8.4.19 on the dev machine), PDO MySQL, MariaDB or MySQL, PHPUnit 11 (dev only), Composer (dev only).

**Spec:** [ARCHITECTURE.md](../../../ARCHITECTURE.md), with [POC.md](../../../POC.md), [FEATURES.md](../../../FEATURES.md), [FLOW.md](../../../FLOW.md) and [db/schema.sql](../../../db/schema.sql).

**Checked:** every code block in this plan was put together in a scratch copy and run on PHP 8.4.19 with MariaDB 11.4.13. All 167 tests pass, twice in a row and each test file on its own, and the web entry point was tried with curl: login, CSRF token, closing a stop with an uploaded photo and signature, fetching the photo back. A step that fails during execution therefore points at a typing slip or a difference in the machine, not at the plan's logic. Not checked: Apache with `.htaccess` and `.user.ini`, MySQL, and the newer MariaDB that `winget` installs.

## Scope

This is plan 1 of 3. The whole build is too big for one plan, and the frontend may only be built against a published API contract.

| Plan | Builds | Status |
|---|---|---|
| 1. Backend API (this file) | Everything under `/api/v1`, its tests, the security review, the published API contract | Written |
| 2. Office console | React `/office` area against the contract from plan 1 | To write after plan 1 is built |
| 3. Scanner simulator | React `/scanner` area with the pile panel | To write after plan 1 is built |

The database is already designed (`db/schema.sql`, 13 tables). This plan changes no table and no column.

## Changes to the spec decided while planning

Where this section and the documents disagree, this section wins. Task 14 brings the documents in line.

| # | Decision | Was | Is now |
|---|---|---|---|
| 1 | When a wash is counted | At the washing house manager's scan when he **receives** used mats (Handover B) | At the washing house manager's scan **after washing**, when he scans the washed mats out (Handover C). That scan is required. It confirms the mats are ready to use, moves the wash dates and adds 1 to the wash count. Receiving in Handover B counts nothing. A rewash is counted the same way, when the mat leaves washing |
| 2 | Time zone of stored times | UTC | Stays UTC, now with the reason written down: Dutch time repeats one hour every autumn, so two scans in that night could not be put in order, and a named time zone needs tables a shared database often lacks. Every screen shows Dutch time. Calendar days (due dates, wash dates) are Dutch days already |

## Prerequisites (human, once, before Task 1)

An agent cannot do these: the database installer asks for administrator rights, and the database password must never pass through an agent.

1. Install MariaDB: `winget install MariaDB.Server`
2. Open a database prompt as root (`"C:\Program Files\MariaDB <version>\bin\mariadb.exe" -u root -p`; press Enter if the installer set no root password) and run, with a password of your own choice:

   ```sql
   CREATE DATABASE laundry      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE laundry_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'laundry'@'localhost' IDENTIFIED BY 'CHOOSE-A-PASSWORD';
   GRANT ALL ON laundry.*      TO 'laundry'@'localhost';
   GRANT ALL ON laundry_test.* TO 'laundry'@'localhost';
   ```

3. Create the file `site/.env` (create the `site` folder if it is not there yet). Put the password in double quotes:

   ```ini
   DB_HOST=127.0.0.1
   DB_NAME=laundry
   DB_USER=laundry
   DB_PASS="CHOOSE-A-PASSWORD"
   APP_ENV=local
   SCANNER_SIMULATOR=true
   ```

The tests always use `laundry_test` and wipe it on every run. They refuse to start on a database whose name does not end in `_test`.

## Global Constraints

- **PHP 8.3+**, `declare(strict_types=1);` in every PHP file. No runtime Composer package. PHPUnit `^11.5` is the only dev dependency.
- **Database:** MySQL 8.0.16+ or MariaDB 10.4+. `db/schema.sql` and `db/seed.sql` are fixed. If a task seems to need a schema change, stop and report it; do not edit the schema. (Task 14 changes two comment lines in `db/schema.sql`, nothing else.)
- **Folders:** `site/` is exactly what gets uploaded to TransIP. `site/www/` is the web root. `site/app/`, `site/bin/`, `site/storage/` and `site/.env` are outside the web root. Tests, `composer.json` and `vendor/` live at the repository root and are never uploaded.
- **SQL:** every query goes through `q($sql, $params)` with `?` placeholders. Only constants written in the code may be put into SQL text. No value from a request is ever pasted into a query.
- **Status changes:** always `UPDATE items SET status = <new> ... WHERE id = ? AND status = <old>`, inside `tx()`, followed by one `log_event()` row. A second scan of the same mat must change nothing and write no second event.
- **Roles:** exactly `office`, `inventory`, `driver`, `washing`, `outbound`.
- **Scan source:** `simulator` or `c72` from scanners. The server writes `web` for office actions. `simulator` is refused when `SCANNER_SIMULATOR` is not `true`.
- **Errors:** always `{"error": {"code": "...", "message": "..."}}` with status `401` not logged in, `403` wrong role, not yours, or bad CSRF token, `404` not found, `405` wrong method, `409` already done, closed, duplicate or wrong status, `422` input not valid, `429` too many login tries.
- **Dates:** `DATETIME` columns are UTC and set with `UTC_TIMESTAMP()`. The API sends them as they are stored (`2026-10-12 21:03:11`, UTC); turning them into Dutch time is the screen's job. `DATE` columns (`due_date`, wash dates, contract dates) are calendar days in Europe/Amsterdam and set with `today()`.
- **Wash count:** goes up only in `handover_move()` for `washing_to_inventory`, at the giver's scan. No other code touches `wash_count` or the wash dates.
- **Tag ids** (EPC, TID) are stored and compared as uppercase hex, 8 to 64 characters.
- **Nothing is hard-deleted.** The one exception is the lines of a contract that is still a draft.
- **Every source file starts with a one-line doc comment** saying what the file is for.
- **Secrets:** the database password lives only in `site/.env`, which is never committed, printed or logged.
- **Stay in the backend lane:** no React, no `site/www/index.html`, no schema edits.
- **Branch:** all work happens on `feat/backend-api`. Never commit on `main`.
- **Versions and commits:** every task is one commit and bumps the patch version. The commit step of each task gives the exact version and message. Commit messages follow the repository style: one sentence ending in `at <version>.` **Never add a `Co-Authored-By` line, a "Generated with" line, or any other AI attribution** to a commit, tag, pull request or file. This overrides any tool default.
- **Run the tests** with `php vendor/bin/phpunit` from the repository root. All commands in this plan are for Git Bash.

## Review Focus

Inputs and conditions the spec implies but does not spell out, most likely first. Each has a test in the task that owns the code.

1. **A tag id made of digits only** (for example `123456789012345678901234`). PHP turns such a string into a number when it is used as an array key. The mat must still be found. Test in Task 5.
2. **A phone photo bigger than PHP's 2 MB upload default.** A 3 MB photo must be accepted, a photo above 5 MB refused with a clear message. Test and `.user.ini` in Task 11.
3. **The office corrects a mat while it sits in an open handover.** The receiver's scan must not crash and must not undo the correction. Test in Task 7.
4. **A search text containing `%` or `_`.** It must match those characters literally, not act as a wildcard. Test in Task 6.
5. **A contract ended before its start date.** The database refuses an end date before the start date, so ending must still work. Test in Task 9.

## Decisions that fill gaps in the spec

To confirm at review. Each one is small; Task 14 writes the accepted ones into ARCHITECTURE.md.

| # | Decision | Why |
|---|---|---|
| 1 | `GET /item-types` is also open to the inventory manager | The Register screen needs the list of mat types |
| 2 | New `GET /handover-receivers?type=...` for the scanning roles | The giver must pick a receiver, and scanning roles may not read `/users` |
| 3 | New `GET /handovers/{id}` for the office and the two people in the handover | The receiver must see the pile list before he scans |
| 4 | The office may close any open handover | A handover opened to the wrong person would otherwise block its mats forever |
| 5 | `PATCH` uses the id in the path: `/users/{id}`, `/areas/{id}`, `/customers/{id}`, `/item-types/{id}`, `/buildings/{id}`, `/zones/{id}`, `/contracts/{id}` | The spec lists `PATCH` on the collection path, which cannot name a row |
| 6 | The lines and the start date of a contract can be changed only while it is a draft. n can be changed while it is active | Delivery tasks are created from the lines at activation |
| 7 | Login throttle: 5 failed tries per minute per username and address, then `429`. Kept in small files under `site/storage/throttle/` | The schema has no table for it |
| 8 | `GET /items/{id}` is open to the washing house manager, who sees the events without note, task and zone | ARCHITECTURE.md (uncommitted edit) gives washing the mat history; a lookup must not reveal the customer |
| 9 | Closing a stop needs `signed_name` | A signature without a name proves little |
| 10 | A collection task accepts no drops. A delivery task expects no pick-ups | Follows from what the task types mean |
| 11 | Passwords have 10 to 72 characters. An office user cannot switch off or demote himself | Nobody may lock the last office user out |
| 12 | Optional setting `STORAGE_DIR` | Lets the tests write proofs and throttle files to a temp folder |
| 13 | Ending a contract sets the end date to today, or to the start date when today is earlier | The database refuses an end date before the start date |
| 14 | Opening a handover that is already open between the same two people returns the open one | A retry on a bad connection must not create a second handover |
| 15 | Photo at most 5 MB, signature at most 1 MB. `site/www/.user.ini` raises PHP's upload limit to 6 MB | The spec says "a size limit" without a number |

## File Structure

```
composer.json                 dev dependency (PHPUnit) and the project version
phpunit.xml                   test settings: forces DB_NAME=laundry_test
.gitignore
site/
  .env.example                the six settings, without a password
  app/
    bootstrap.php             cfg(), storage_dir(), loads every file below
    db.php                    db(), q(), tx(), marks(), today(), days_after()
    http.php                  Request, ApiError, FileOut, fail(), input readers
    kernel.php                dispatch(), route(), error mapping
    routes.php                the route table
    auth.php                  login, logout, me, current_user(), login throttle
    refdata.php               areas, customers, buildings, zones, item types
    users.php                 user management
    scan.php                  tag reads, item views, events, lookup, simulator list
    items.php                 register, ready, quality check, correction, list, history
    handovers.php             the five handover types
    contracts.php             contracts, lines, activate, end
    tasks.php                 task lists, assignment, closing a stop, proof files
  bin/
    create-office-user.php    one-time CLI for the first office user
  www/
    index.php                 web entry point
    .htaccess                 HTTPS, /api/* to index.php
    .user.ini                 upload limits
tests/
  bootstrap.php               rebuilds laundry_test from db/schema.sql
  ApiTestCase.php             fresh mock data per test, in-process API calls
  *Test.php                   one file per task
```

One file per subject, functions only, no classes beyond the three small value classes in `http.php`. Handlers are plain functions named `<subject>_<action>` that take `(Request $req, array $user, int ...$ids)` and return an array.

---

### Task 1: Project skeleton and database test harness

**Files:**
- Create: `.gitignore`, `composer.json`, `phpunit.xml`, `site/.env.example`
- Create: `site/app/bootstrap.php`, `site/app/db.php`
- Create: `tests/bootstrap.php`, `tests/ApiTestCase.php`
- Test: `tests/DbTest.php`
- Modify: `README.md:16` (version badge)

**Interfaces:**
- Consumes: `db/schema.sql`, `db/seed.sql`, `site/.env` (made by the human, see Prerequisites).
- Produces:
  - `cfg(string $key, ?string $default = null): ?string`
  - `storage_dir(): string`
  - `db(): PDO`
  - `q(string $sql, array $params = []): PDOStatement` — positional `?` only; binds int as int, null as null
  - `tx(callable $fn): mixed` — commit on return, roll back on any throw
  - `marks(array $xs): string` — `?,?,?` for an `IN (...)` list
  - `today(): string` — `Y-m-d` in Europe/Amsterdam
  - `days_after(string $date, int $days): string`
  - Test base class `ApiTestCase` with `$this->users` (`office`, `inventory`, `driver`, `driver2`, `washing`, `outbound` → id), `PASSWORD`, `api()`, `loginAs()`, `ok()`, `assertError()`, `epc()`, `reads()`, `item()`, `setItems()`, `events()`

- [ ] **Step 1: Check the prerequisites**

Run:

```bash
test -f site/.env && echo "env ok" || echo "site/.env missing"
php -r '$c = parse_ini_file("site/.env", false, INI_SCANNER_RAW); new PDO("mysql:host={$c["DB_HOST"]};dbname=laundry_test", $c["DB_USER"], $c["DB_PASS"]); echo "database ok", PHP_EOL;'
```

Expected: `env ok` and `database ok`. If either fails, stop and report BLOCKED with the exact output: the Prerequisites section is not done. Do not print the content of `site/.env`.

- [ ] **Step 2: Install Composer locally and PHPUnit**

Create `composer.json`:

```json
{
    "name": "proti/laundry-poc",
    "description": "Proti mat tracking POC: PHP API",
    "version": "0.4.0",
    "type": "project",
    "require": {
        "php": ">=8.3",
        "ext-pdo_mysql": "*",
        "ext-fileinfo": "*",
        "ext-mbstring": "*"
    },
    "require-dev": {
        "ext-gd": "*",
        "phpunit/phpunit": "^11.5"
    }
}
```

Create `.gitignore`:

```
/vendor/
/composer.phar
/.phpunit.cache/
/site/.env
/site/storage/
/.superpowers/
```

Run:

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === trim(file_get_contents('https://composer.github.io/installer.sig'))) { echo 'Installer verified', PHP_EOL; } else { echo 'Installer corrupt', PHP_EOL; unlink('composer-setup.php'); exit(1); }"
php composer-setup.php --quiet
php -r "unlink('composer-setup.php');"
php composer.phar install
```

Expected: `Installer verified`, then Composer installs `phpunit/phpunit` and writes `composer.lock`. `php vendor/bin/phpunit --version` prints `PHPUnit 11.`

- [ ] **Step 3: Write the test settings and the test harness**

Create `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true" cacheDirectory=".phpunit.cache"
         failOnWarning="true" failOnRisky="true" displayDetailsOnTestsThatTriggerWarnings="true">
    <testsuites>
        <testsuite name="api">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="test" force="true"/>
        <env name="DB_NAME" value="laundry_test" force="true"/>
    </php>
</phpunit>
```

Create `tests/bootstrap.php`:

```php
<?php
/** Test start-up: rebuilds the test database from db/schema.sql and points storage at a temp folder. */
declare(strict_types=1);

require __DIR__ . '/../site/app/bootstrap.php';

if (!str_ends_with((string) cfg('DB_NAME'), '_test')) {
    fwrite(STDERR, "Refusing to run: DB_NAME must end in _test, because the tests wipe the database.\n");
    exit(1);
}

$storage = sys_get_temp_dir() . '/laundry-test-storage';
if (!is_dir($storage)) {
    mkdir($storage, 0700, true);
}
putenv("STORAGE_DIR=$storage");
// Set here and not in phpunit.xml: PHPUnit turns the text "true" in its config into 1.
putenv('SCANNER_SIMULATOR=true');
// Server errors are logged here instead of into the test output. Read this file when a test gets a 500.
ini_set('error_log', $storage . '/php-error.log');

/** Runs every statement of a .sql file. The files have no ";" at a line end inside a statement. */
function run_sql_file(string $path): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($path));
    foreach (preg_split('/;[ \t]*(?:\r?\n|$)/', $sql) as $statement) {
        if (trim($statement) !== '') {
            db()->exec($statement);
        }
    }
}

$pdo = db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec("DROP TABLE `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
run_sql_file(__DIR__ . '/../db/schema.sql');

require __DIR__ . '/ApiTestCase.php';
```

Create `tests/ApiTestCase.php`:

```php
<?php
/** Base class for every test: fresh mock data and six users before each test, and helpers to call the API in-process. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class ApiTestCase extends TestCase
{
    protected const PASSWORD = 'test-password-1';

    private const TABLES = ['item_events', 'handover_items', 'handovers', 'tasks', 'contract_lines', 'contracts',
        'items', 'item_types', 'zones', 'buildings', 'customers', 'areas', 'users'];

    /** @var array<string,int> username => user id */
    protected array $users = [];

    protected function setUp(): void
    {
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $pdo->exec("TRUNCATE TABLE `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        run_sql_file(__DIR__ . '/../db/seed.sql');

        static $hash = null;
        $hash ??= password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $roles = ['office' => 'office', 'inventory' => 'inventory', 'driver' => 'driver',
            'driver2' => 'driver', 'washing' => 'washing', 'outbound' => 'outbound'];
        foreach ($roles as $username => $role) {
            q('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, ?)',
                [ucfirst($username), $username, $hash, $role]);
            $this->users[$username] = (int) $pdo->lastInsertId();
        }

        $_SESSION = [];
        foreach (['throttle', 'proofs'] as $folder) {
            array_map('unlink', glob(storage_dir() . "/$folder/*") ?: []);
        }
    }

    /** Calls the API without a web server. Path is relative to /api/v1, for example "/items?page=2". */
    protected function api(string $method, string $path, array $body = [], array $files = [], array $headers = []): array
    {
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }
        if (isset($_SESSION['csrf'])) {
            $headers += ['x-csrf-token' => $_SESSION['csrf']];
        }
        return dispatch(new Request($method, $path, $query, $body, $files, $headers, '127.0.0.1'));
    }

    protected function loginAs(string $username): void
    {
        $_SESSION = ['uid' => $this->users[$username], 'csrf' => 'test-csrf'];
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
        return q('SELECT * FROM items WHERE sku = ?', [sprintf('MOCK-%04d', $n)])->fetch();
    }

    /** Puts mock mats straight into a status, to arrange a test. */
    protected function setItems(array $mats, string $status, ?int $holder = null, ?int $zone = null): void
    {
        foreach ($mats as $n) {
            q('UPDATE items SET status = ?, holder_user_id = ?, zone_id = ? WHERE sku = ?',
                [$status, $holder, $zone, sprintf('MOCK-%04d', $n)]);
        }
    }

    /** The history rows of mock mat n, oldest first. */
    protected function events(int $n): array
    {
        return q('SELECT * FROM item_events WHERE item_id = ? ORDER BY id', [$this->item($n)['id']])->fetchAll();
    }
}
```

`api()` and `loginAs()` need `dispatch()` and `Request`, which Task 2 builds. This task's tests do not call them.

- [ ] **Step 4: Write the failing test**

Create `tests/DbTest.php`:

```php
<?php
/** The database layer: connection settings, parameter binding, transactions, and the schema's own refusals. */
declare(strict_types=1);

final class DbTest extends ApiTestCase
{
    public function test_every_test_starts_with_40_ready_mats_and_six_users(): void
    {
        $this->assertSame(40, (int) q("SELECT COUNT(*) FROM items WHERE status = 'ready'")->fetchColumn());
        $this->assertCount(6, $this->users);
    }

    public function test_connection_runs_in_utc(): void
    {
        $this->assertSame('+00:00', q('SELECT @@session.time_zone')->fetchColumn());
    }

    public function test_strict_mode_refuses_an_unknown_status(): void
    {
        $this->expectException(PDOException::class);
        q("UPDATE items SET status = 'bogus' WHERE sku = 'MOCK-0001'");
    }

    public function test_database_refuses_a_mat_at_a_customer_without_a_zone(): void
    {
        $this->expectException(PDOException::class);
        q("UPDATE items SET status = 'at_customer' WHERE sku = 'MOCK-0001'");
    }

    public function test_q_binds_integers_so_limit_works(): void
    {
        $this->assertCount(2, q('SELECT id FROM items ORDER BY id LIMIT ? OFFSET ?', [2, 1])->fetchAll());
    }

    public function test_q_returns_numbers_as_numbers(): void
    {
        $this->assertSame(0, $this->item(1)['wash_count']);
    }

    public function test_tx_rolls_back_when_the_callback_throws(): void
    {
        try {
            tx(function (): void {
                q("UPDATE items SET status = 'registered' WHERE sku = 'MOCK-0001'");
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame('ready', $this->item(1)['status']);
    }

    public function test_days_after_adds_calendar_days(): void
    {
        $this->assertSame('2026-11-02', days_after('2026-10-19', 14));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', today());
    }
}
```

- [ ] **Step 5: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/DbTest.php`
Expected: FAIL at start-up with `Failed opening required '.../site/app/bootstrap.php'`.

- [ ] **Step 6: Write the settings loader and the database layer**

Create `site/.env.example`:

```ini
; Copy to site/.env and fill in. Never commit site/.env.
DB_HOST=127.0.0.1
DB_NAME=laundry
DB_USER=laundry
DB_PASS=""
; local or production
APP_ENV=local
; true while the handheld is simulated. false switches the simulator and GET /sim/items off.
SCANNER_SIMULATOR=true
; Optional: where proofs, sessions and login throttle files go. Default: site/storage
; STORAGE_DIR=
```

Create `site/app/bootstrap.php`:

```php
<?php
/** Loads the settings and every backend file. Required first by www/index.php, the bin scripts and the tests. */
declare(strict_types=1);

/** One setting. The process environment wins, then site/.env, then the default. */
function cfg(string $key, ?string $default = null): ?string
{
    static $file = null;
    $env = getenv($key);
    if ($env !== false) {
        return $env;
    }
    $path = __DIR__ . '/../.env';
    $file ??= is_file($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : [];
    return $file[$key] ?? $default;
}

/** Folder for proof files, sessions and login throttle files. Outside the web root. */
function storage_dir(): string
{
    return cfg('STORAGE_DIR', __DIR__ . '/../storage');
}

foreach (['db'] as $file) {
    require __DIR__ . "/$file.php";
}
```

Create `site/app/db.php`:

```php
<?php
/** Database access: one PDO connection in UTC and strict mode, prepared statements only, transactions, date helpers. */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', cfg('DB_HOST', '127.0.0.1'), cfg('DB_NAME')),
            cfg('DB_USER'),
            cfg('DB_PASS'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        // Without strict mode MySQL silently accepts an unknown status.
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
    return $pdo;
}

/** Runs one prepared statement with positional "?" parameters. The only way the app talks SQL. */
function q(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    foreach (array_values($params) as $i => $value) {
        $type = match (true) {
            $value === null => PDO::PARAM_NULL,
            is_int($value), is_bool($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
        $statement->bindValue($i + 1, is_bool($value) ? (int) $value : $value, $type);
    }
    $statement->execute();
    return $statement;
}

/** Runs $fn in one transaction: commit when it returns, roll back when it throws. */
function tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** "?,?,?" for an IN (...) list with one mark per element. */
function marks(array $xs): string
{
    return implode(',', array_fill(0, count($xs), '?'));
}

/** Today as a calendar day in the Netherlands. DATE columns hold these, DATETIME columns hold UTC. */
function today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
}

function days_after(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify(sprintf('%+d days', $days))->format('Y-m-d');
}
```

- [ ] **Step 7: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/DbTest.php`
Expected: `OK (8 tests, ...)`.

If `test_q_returns_numbers_as_numbers` fails because `wash_count` comes back as the string `"0"`, the PDO driver is not `mysqlnd`: report it, do not cast around it.

- [ ] **Step 8: Commit**

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.0-2563eb/' README.md
git add .gitignore composer.json composer.lock phpunit.xml site/.env.example site/app/bootstrap.php site/app/db.php tests/bootstrap.php tests/ApiTestCase.php tests/DbTest.php README.md
git commit -m "Add project skeleton and database test harness at 0.4.0."
```

---

### Task 2: HTTP kernel and login

**Files:**
- Create: `site/app/http.php`, `site/app/kernel.php`, `site/app/routes.php`, `site/app/auth.php`
- Create: `site/www/index.php`, `site/www/.htaccess`, `site/www/.user.ini`
- Modify: `site/app/bootstrap.php` (file list)
- Test: `tests/AuthTest.php`

**Interfaces:**
- Consumes: `cfg()`, `storage_dir()`, `q()` from Task 1.
- Produces:
  - `final class Request { string $method; string $path; array $query; array $body; array $files; array $headers; string $ip; }` — `path` is relative to `/api/v1`; `files` maps a field name to a temp file path; `headers` has lowercase keys
  - `final class ApiError extends RuntimeException { int $status; string $errorCode; }`
  - `final class FileOut { string $path; string $mime; }`
  - `fail(int $status, string $code, string $message): never`
  - `str_in(array $in, string $key, int $max, bool $required = true): ?string` — trims; empty counts as absent
  - `int_in(array $in, string $key, bool $required = true): ?int` — whole number, zero or more
  - `bool_in(array $in, string $key): ?bool` — null when the key is absent
  - `date_in(array $in, string $key, bool $required = true): ?string` — `Y-m-d`
  - `enum_in(array $in, string $key, array $allowed, bool $required = true): ?string`
  - `dispatch(Request $req): array` — `[int $status, array|FileOut $body]`
  - `const ROLES`
  - `current_user(): array` — `id, name, username, role, is_active`; throws 401
  - `user_view(array $u): array`
  - Route table entries are `[method, pattern, roles|null, handler]`; `{name}` in a pattern is a numeric id passed to the handler after `$req` and `$user`
  - Routes: `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`

- [ ] **Step 1: Write the failing test**

Create `tests/AuthTest.php`:

```php
<?php
/** Login, logout, session, CSRF token, login throttle, and the kernel's 404 and 405. */
declare(strict_types=1);

final class AuthTest extends ApiTestCase
{
    private function login(string $username, string $password = self::PASSWORD): array
    {
        return $this->api('POST', '/auth/login', ['username' => $username, 'password' => $password]);
    }

    public function test_login_returns_the_user_and_a_csrf_token(): void
    {
        $out = $this->ok($this->login('Driver'));   // usernames are not case sensitive
        $this->assertSame(['id', 'name', 'username', 'role', 'is_active'], array_keys($out['user']));
        $this->assertSame('driver', $out['user']['role']);
        $this->assertSame($this->users['driver'], $_SESSION['uid']);
        $this->assertSame($_SESSION['csrf'], $out['csrf_token']);
        $this->assertSame(64, strlen($out['csrf_token']));
    }

    public function test_wrong_password_and_unknown_user_get_the_same_answer(): void
    {
        $this->assertError(401, 'bad_login', $this->login('driver', 'wrong-password'));
        $this->assertError(401, 'bad_login', $this->login('nobody', 'wrong-password'));
        $this->assertSame([], $_SESSION);
    }

    public function test_a_switched_off_user_cannot_log_in(): void
    {
        q('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver']]);
        $this->assertError(401, 'bad_login', $this->login('driver'));
    }

    public function test_a_user_switched_off_while_logged_in_is_logged_out_at_his_next_call(): void
    {
        $this->loginAs('driver');
        q('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver']]);
        $this->assertError(401, 'not_logged_in', $this->api('GET', '/auth/me'));
        $this->assertSame([], $_SESSION);
    }

    public function test_five_failed_tries_block_the_sixth_even_with_the_right_password(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertError(401, 'bad_login', $this->login('driver', 'wrong-password'));
        }
        $this->assertError(429, 'too_many_tries', $this->login('driver'));
        $this->ok($this->login('washing'));   // another username is not affected
    }

    public function test_a_good_login_clears_the_failed_tries(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->login('driver', 'wrong-password');
        }
        $this->ok($this->login('driver'));
        $_SESSION = [];
        for ($i = 0; $i < 4; $i++) {
            $this->assertError(401, 'bad_login', $this->login('driver', 'wrong-password'));
        }
    }

    public function test_login_needs_username_and_password(): void
    {
        $this->assertError(422, 'invalid_input', $this->api('POST', '/auth/login', ['username' => 'driver']));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/auth/login', ['password' => 'x']));
    }

    public function test_me_needs_a_login(): void
    {
        $this->assertError(401, 'not_logged_in', $this->api('GET', '/auth/me'));
        $this->loginAs('office');
        $out = $this->ok($this->api('GET', '/auth/me'));
        $this->assertSame('office', $out['user']['role']);
        $this->assertSame('test-csrf', $out['csrf_token']);
    }

    public function test_a_changing_request_needs_the_csrf_token(): void
    {
        $this->loginAs('office');
        $this->assertError(403, 'csrf', dispatch(new Request('POST', '/auth/logout')));
        $this->assertError(403, 'csrf', dispatch(new Request('POST', '/auth/logout', headers: ['x-csrf-token' => 'wrong'])));
        $this->assertNotSame([], $_SESSION);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->loginAs('office');
        $this->ok($this->api('POST', '/auth/logout'));
        $this->assertSame([], $_SESSION);
        $this->assertError(401, 'not_logged_in', $this->api('GET', '/auth/me'));
    }

    public function test_unknown_path_is_404_and_wrong_method_is_405(): void
    {
        $this->assertError(404, 'not_found', $this->api('GET', '/nothing-here'));
        $this->assertError(405, 'method_not_allowed', $this->api('DELETE', '/auth/me'));
    }

    public function test_the_dummy_hash_is_a_real_bcrypt_hash(): void
    {
        $this->assertSame('2y', password_get_info(DUMMY_HASH)['algo']);
    }

    public function test_a_server_error_hides_its_details_in_production(): void
    {
        putenv('APP_ENV=production');
        try {
            [$status, $body] = server_error(new RuntimeException('secret detail'));
        } finally {
            putenv('APP_ENV=test');
        }
        $this->assertSame(500, $status);
        $this->assertSame('Something went wrong.', $body['error']['message']);
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/AuthTest.php`
Expected: FAIL with `Class "Request" not found`.

- [ ] **Step 3: Write the request and input helpers**

Create `site/app/http.php`:

```php
<?php
/** The request as the handlers see it, the one way to fail, and the readers that validate input at the API boundary. */
declare(strict_types=1);

final class Request
{
    /**
     * @param string $path    relative to /api/v1, for example "/items/3"
     * @param array  $files   field name => path of the uploaded temp file
     * @param array  $headers lowercase header name => value
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $files = [],
        public readonly array $headers = [],
        public readonly string $ip = '',
    ) {
    }
}

final class ApiError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

/** A handler returns this to send a stored file instead of JSON. */
final class FileOut
{
    public function __construct(
        public readonly string $path,
        public readonly string $mime,
    ) {
    }
}

function fail(int $status, string $code, string $message): never
{
    throw new ApiError($status, $code, $message);
}

/** Text of at most $max characters, trimmed. Empty text counts as absent. */
function str_in(array $in, string $key, int $max, bool $required = true): ?string
{
    $value = $in[$key] ?? null;
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === null || $value === '') {
        if ($required) {
            fail(422, 'invalid_input', "$key is required.");
        }
        return null;
    }
    if (!is_string($value) || mb_strlen($value) > $max) {
        fail(422, 'invalid_input', "$key must be text of at most $max characters.");
    }
    return $value;
}

/** A whole number of zero or more. Accepts 12 and "12". */
function int_in(array $in, string $key, bool $required = true): ?int
{
    $value = $in[$key] ?? null;
    if ($value === null) {
        if ($required) {
            fail(422, 'invalid_input', "$key is required.");
        }
        return null;
    }
    $isNumber = (is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value) && strlen($value) <= 18);
    if (!$isNumber) {
        fail(422, 'invalid_input', "$key must be a whole number.");
    }
    return (int) $value;
}

/** true or false. Null when the key is absent. */
function bool_in(array $in, string $key): ?bool
{
    if (!array_key_exists($key, $in)) {
        return null;
    }
    if (!is_bool($in[$key])) {
        fail(422, 'invalid_input', "$key must be true or false.");
    }
    return $in[$key];
}

/** A calendar day written as 2026-10-19. */
function date_in(array $in, string $key, bool $required = true): ?string
{
    $value = $in[$key] ?? null;
    if ($value === null && !$required) {
        return null;
    }
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
    if (!$date || $date->format('Y-m-d') !== $value) {
        fail(422, 'invalid_input', "$key must be a date like 2026-10-19.");
    }
    return $value;
}

/** One of a fixed list of words. */
function enum_in(array $in, string $key, array $allowed, bool $required = true): ?string
{
    $value = $in[$key] ?? null;
    if ($value === null && !$required) {
        return null;
    }
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        fail(422, 'invalid_input', "$key must be one of: " . implode(', ', $allowed) . '.');
    }
    return $value;
}
```

- [ ] **Step 4: Write the kernel and the route table**

Create `site/app/kernel.php`:

```php
<?php
/** Request routing: finds the route, checks login, CSRF token and role, and turns every failure into the one error shape. */
declare(strict_types=1);

const ROLES = ['office', 'inventory', 'driver', 'washing', 'outbound'];

/** @return array{0:int,1:array|FileOut} HTTP status and body */
function dispatch(Request $req): array
{
    try {
        return [200, route($req)];
    } catch (ApiError $e) {
        return error_response($e->status, $e->errorCode, $e->getMessage());
    } catch (PDOException $e) {
        // The database is the last line of defence. Map its refusals to the API's error codes.
        return match ($e->errorInfo[1] ?? 0) {
            1062 => error_response(409, 'duplicate', 'That value is already in use.'),
            1452 => error_response(422, 'invalid_reference', 'A referenced record does not exist.'),
            3819, 4025 => error_response(422, 'rule_violation', 'The database refused an impossible state.'),
            default => server_error($e),
        };
    } catch (Throwable $e) {
        return server_error($e);
    }
}

function error_response(int $status, string $code, string $message): array
{
    return [$status, ['error' => ['code' => $code, 'message' => $message]]];
}

function server_error(Throwable $e): array
{
    error_log((string) $e);
    // In production the details stay in the server log.
    $message = cfg('APP_ENV') === 'production' ? 'Something went wrong.' : $e->getMessage();
    return error_response(500, 'server_error', $message);
}

function route(Request $req): array|FileOut
{
    static $routes = null;
    $routes ??= require __DIR__ . '/routes.php';

    $pathKnown = false;
    foreach ($routes as [$method, $pattern, $roles, $handler]) {
        $regex = '#^' . preg_replace('/\{\w+\}/', '(\d{1,18})', $pattern) . '$#';
        if (!preg_match($regex, $req->path, $match)) {
            continue;
        }
        $pathKnown = true;
        if ($method !== $req->method) {
            continue;
        }
        $ids = array_map('intval', array_slice($match, 1));
        if ($roles === null) {
            return $handler($req, null, ...$ids);
        }
        $user = current_user();
        $token = $_SESSION['csrf'] ?? '';
        $sent = $req->headers['x-csrf-token'] ?? '';
        if ($req->method !== 'GET' && ($token === '' || !is_string($sent) || !hash_equals($token, $sent))) {
            fail(403, 'csrf', 'Missing or wrong CSRF token.');
        }
        if (!in_array($user['role'], $roles, true)) {
            fail(403, 'forbidden', 'Your role may not do this.');
        }
        return $handler($req, $user, ...$ids);
    }
    if ($pathKnown) {
        fail(405, 'method_not_allowed', 'This path does not accept that method.');
    }
    fail(404, 'not_found', 'No such path.');
}
```

Create `site/app/routes.php`:

```php
<?php
/** The route table: method, path, roles that may call it (null = no login needed), handler. */
declare(strict_types=1);

$O = 'office';
$I = 'inventory';
$D = 'driver';
$W = 'washing';
$X = 'outbound';
$all = [$O, $I, $D, $W, $X];
$scanners = [$I, $D, $W, $X];

return [
    ['POST', '/auth/login',  null, 'auth_login'],
    ['POST', '/auth/logout', $all, 'auth_logout'],
    ['GET',  '/auth/me',     $all, 'auth_me'],
];
```

- [ ] **Step 5: Write login, logout and the throttle**

First make the dummy hash. Run:

```bash
php -r "echo password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), PHP_EOL;"
```

It prints one line starting with `$2y$`. Use that line as the value of `DUMMY_HASH` below, in single quotes.

Create `site/app/auth.php`:

```php
<?php
/** Login, logout and the logged-in user. The session holds the user id and the CSRF token; failed logins are throttled. */
declare(strict_types=1);

// A real bcrypt hash of a random string nobody knows. Checking a wrong username against it
// makes "unknown user" take as long as "wrong password", so timing does not reveal usernames.
const DUMMY_HASH = 'PUT THE LINE FROM THE COMMAND ABOVE HERE';

const LOGIN_MAX_TRIES = 5;
const LOGIN_WINDOW_SECONDS = 60;

function user_view(array $u): array
{
    return ['id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'],
        'role' => $u['role'], 'is_active' => (bool) $u['is_active']];
}

/** The logged-in user, read fresh on every call so a switched-off user is out at his next call. */
function current_user(): array
{
    $id = $_SESSION['uid'] ?? null;
    $user = $id === null ? false
        : q('SELECT id, name, username, role, is_active FROM users WHERE id = ?', [$id])->fetch();
    if (!$user || !$user['is_active']) {
        $_SESSION = [];
        fail(401, 'not_logged_in', 'Log in first.');
    }
    return $user;
}

// ponytail: one small file per username and address. A failed try for a made-up username leaves a file
// until someone cleans storage/throttle. Move to a database table if that folder ever grows.
function throttle_file(string $key): string
{
    $dir = storage_dir() . '/throttle';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir . '/' . hash('sha256', $key);
}

/** Times of the failed tries inside the window. */
function throttle_recent(string $key): array
{
    $file = throttle_file($key);
    $times = is_file($file) ? array_map('intval', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    return array_values(array_filter($times, fn (int $t) => $t > time() - LOGIN_WINDOW_SECONDS));
}

function auth_login(Request $req): array
{
    $username = strtolower(str_in($req->body, 'username', 60));
    $password = $req->body['password'] ?? null;
    if (!is_string($password) || $password === '') {
        fail(422, 'invalid_input', 'password is required.');
    }

    $key = $username . '|' . $req->ip;
    if (count(throttle_recent($key)) >= LOGIN_MAX_TRIES) {
        fail(429, 'too_many_tries', 'Too many tries. Wait a minute and try again.');
    }

    $user = q('SELECT id, name, username, role, is_active, password_hash FROM users WHERE username = ?', [$username])->fetch();
    $passwordOk = password_verify($password, $user ? $user['password_hash'] : DUMMY_HASH);
    if (!$passwordOk || !$user || !$user['is_active']) {
        file_put_contents(throttle_file($key), implode("\n", [...throttle_recent($key), time()]) . "\n", LOCK_EX);
        fail(401, 'bad_login', 'Wrong username or password.');
    }

    if (is_file(throttle_file($key))) {
        unlink(throttle_file($key));
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION = ['uid' => $user['id'], 'csrf' => bin2hex(random_bytes(32))];
    return ['user' => user_view($user), 'csrf_token' => $_SESSION['csrf']];
}

function auth_logout(Request $req, array $user): array
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    return ['ok' => true];
}

function auth_me(Request $req, array $user): array
{
    return ['user' => user_view($user), 'csrf_token' => $_SESSION['csrf']];
}
```

In `site/app/bootstrap.php` replace the file list:

```php
foreach (['db', 'http', 'kernel', 'auth'] as $file) {
    require __DIR__ . "/$file.php";
}
```

- [ ] **Step 6: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/AuthTest.php`
Expected: `OK (13 tests, ...)`. If only `test_the_dummy_hash_is_a_real_bcrypt_hash` fails, `DUMMY_HASH` still holds the placeholder text: paste the generated line.

- [ ] **Step 7: Write the web entry point**

`index.php` is the only code that touches PHP's request globals. It is checked by hand in Task 4, when a user can be created.

Create `site/www/index.php`:

```php
<?php
/** Web entry point: turns the PHP request into a Request, runs dispatch(), and sends JSON or a proof file. */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$production = cfg('APP_ENV') === 'production';

$sessions = storage_dir() . '/sessions';
if (!is_dir($sessions)) {
    mkdir($sessions, 0700, true);
}
session_save_path($sessions);
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '43200');   // 12 hours: one working day
session_name('laundry');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $production, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if ($production) {
    header('Strict-Transport-Security: max-age=31536000');
}

function send_json(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$prefix = '/api/v1';
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!str_starts_with($path, $prefix . '/')) {
    send_json(404, ['error' => ['code' => 'not_found', 'message' => 'No such path.']]);
}

// A stop is closed with a form (files plus the JSON in the field "payload"); every other call sends JSON.
$method = $_SERVER['REQUEST_METHOD'];
$isForm = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$raw = $isForm ? ($_POST['payload'] ?? '') : (string) file_get_contents('php://input');
$body = $method === 'GET' || $raw === '' ? [] : json_decode($raw, true);
if (!is_array($body)) {
    send_json(422, ['error' => ['code' => 'invalid_input', 'message' => 'The request body is not valid JSON.']]);
}

$files = [];
foreach ($_FILES as $field => $file) {
    if (is_string($file['tmp_name']) && $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
        $files[$field] = $file['tmp_name'];
    }
}

[$status, $out] = dispatch(new Request(
    $method,
    substr($path, strlen($prefix)),
    $_GET,
    $body,
    $files,
    ['x-csrf-token' => $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''],
    $_SERVER['REMOTE_ADDR'] ?? '',
));

if ($out instanceof FileOut) {
    http_response_code($status);
    header('Content-Type: ' . $out->mime);
    header('Content-Length: ' . filesize($out->path));
    readfile($out->path);
    exit;
}
send_json($status, $out);
```

Create `site/www/.htaccess`:

```apache
# Web root rules: HTTPS only, /api/* to the PHP entry point, everything else to the React app.
RewriteEngine On

RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !=https
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

RewriteRule ^api/ index.php [L]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.html [L]
```

Create `site/www/.user.ini`:

```ini
; A proof photo from a phone is bigger than PHP's 2 MB default.
upload_max_filesize = 6M
post_max_size = 8M
```

Run: `php -l site/www/index.php`
Expected: `No syntax errors detected in site/www/index.php`.

- [ ] **Step 8: Run all tests**

Run: `php vendor/bin/phpunit`
Expected: `OK (21 tests, ...)`.

- [ ] **Step 9: Commit**

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.1-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.1"/' composer.json
git add site/app/http.php site/app/kernel.php site/app/routes.php site/app/auth.php site/app/bootstrap.php site/www/index.php site/www/.htaccess site/www/.user.ini tests/AuthTest.php README.md composer.json
git commit -m "Add HTTP kernel and login at 0.4.1."
```

---

### Task 3: Areas, customers, buildings, zones and mat types

**Files:**
- Create: `site/app/refdata.php`
- Modify: `site/app/bootstrap.php` (file list), `site/app/routes.php`
- Test: `tests/RefdataTest.php`

**Interfaces:**
- Consumes: `q()`, `marks()`, `fail()`, `str_in()`, `int_in()`, `bool_in()`, the route table format from Task 2.
- Produces routes, all answering `{"rows": [...]}` for a list and `{"row": {...}}` for one record, with `is_active` as true or false:
  - `GET, POST /areas`, `PATCH /areas/{id}` — office
  - `GET, POST /customers`, `PATCH /customers/{id}` — office
  - `GET /item-types` — office and inventory; `POST /item-types`, `PATCH /item-types/{id}` — office
  - `GET, POST /customers/{id}/buildings`, `PATCH /buildings/{id}` — office
  - `GET, POST /buildings/{id}/zones`, `PATCH /zones/{id}` — office

- [ ] **Step 1: Write the failing test**

Create `tests/RefdataTest.php`:

```php
<?php
/** Office management of areas, customers, buildings, zones and mat types. */
declare(strict_types=1);

final class RefdataTest extends ApiTestCase
{
    public function test_office_builds_a_customer_with_a_building_and_a_zone(): void
    {
        $this->loginAs('office');
        $area = $this->ok($this->api('POST', '/areas', ['name' => 'East']))['row'];
        $customer = $this->ok($this->api('POST', '/customers',
            ['name' => 'Acme', 'contact_name' => 'Ann', 'contact_phone' => '06-1']))['row'];
        $building = $this->ok($this->api('POST', "/customers/{$customer['id']}/buildings",
            ['area_id' => $area['id'], 'name' => 'HQ', 'street' => 'Main 1', 'postcode' => '1234 AB', 'city' => 'Town']))['row'];
        $zone = $this->ok($this->api('POST', "/buildings/{$building['id']}/zones", ['name' => 'Lobby']))['row'];

        $this->assertSame($customer['id'], $building['customer_id']);
        $this->assertTrue($zone['is_active']);
        $this->assertSame(['HQ'],
            array_column($this->ok($this->api('GET', "/customers/{$customer['id']}/buildings"))['rows'], 'name'));
        $this->assertSame(['Lobby'],
            array_column($this->ok($this->api('GET', "/buildings/{$building['id']}/zones"))['rows'], 'name'));
    }

    public function test_lists_come_back_sorted_by_name(): void
    {
        $this->loginAs('office');
        $this->assertSame(['Mock North', 'Mock South'], array_column($this->ok($this->api('GET', '/areas'))['rows'], 'name'));
        $this->assertCount(2, $this->ok($this->api('GET', '/customers'))['rows']);
        $this->assertCount(3, $this->ok($this->api('GET', '/item-types'))['rows']);
    }

    public function test_a_name_that_exists_is_refused(): void
    {
        $this->loginAs('office');
        $this->assertError(409, 'duplicate', $this->api('POST', '/areas', ['name' => 'Mock North']));
        $this->assertError(409, 'duplicate', $this->api('POST', '/buildings/1/zones', ['name' => 'Front door']));
    }

    public function test_a_building_needs_an_area_that_exists(): void
    {
        $this->loginAs('office');
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/customers/1/buildings',
            ['area_id' => 999, 'name' => 'X', 'street' => 'Y 1', 'postcode' => '1', 'city' => 'Z']));
    }

    public function test_required_and_too_long_fields_are_refused(): void
    {
        $this->loginAs('office');
        $this->assertError(422, 'invalid_input', $this->api('POST', '/customers', []));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/customers', ['name' => str_repeat('a', 161)]));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/areas', ['name' => '   ']));
        $this->assertError(422, 'invalid_input', $this->api('PATCH', '/customers/1', ['is_active' => 'yes']));
        $this->assertError(422, 'invalid_input', $this->api('PATCH', '/customers/1', ['name' => '']));
    }

    public function test_patch_changes_only_what_is_sent(): void
    {
        $this->loginAs('office');
        $row = $this->ok($this->api('PATCH', '/customers/1', ['is_active' => false, 'contact_name' => '']))['row'];
        $this->assertFalse($row['is_active']);
        $this->assertNull($row['contact_name']);
        $this->assertSame('Mock Bakery BV', $row['name']);
        $this->assertSame('000-0000001', $row['contact_phone']);
    }

    public function test_patch_of_an_unknown_row_is_404(): void
    {
        $this->loginAs('office');
        $this->assertError(404, 'not_found', $this->api('PATCH', '/zones/999', ['name' => 'X']));
    }

    public function test_only_the_office_manages_customers(): void
    {
        $this->loginAs('driver');
        $this->assertError(403, 'forbidden', $this->api('GET', '/customers'));
        $this->assertError(403, 'forbidden', $this->api('POST', '/areas', ['name' => 'East']));
    }

    public function test_inventory_reads_mat_types_but_cannot_change_them(): void
    {
        $this->loginAs('inventory');
        $this->assertCount(3, $this->ok($this->api('GET', '/item-types'))['rows']);
        $this->assertError(403, 'forbidden', $this->api('POST', '/item-types', ['name' => 'New mat']));
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/RefdataTest.php`
Expected: FAIL, every test gets `404 not_found` where it expects another status.

- [ ] **Step 3: Write the implementation**

Create `site/app/refdata.php`:

```php
<?php
/** Areas, customers, buildings, zones and mat types: five small tables the office manages the same way. */
declare(strict_types=1);

// Per resource: its table, the column that points to its parent (or null), and its editable fields
// as name => [type, maximum length, required]. Table and column names come only from this constant.
const REFDATA = [
    'areas' => ['table' => 'areas', 'parent' => null, 'fields' => [
        'name' => ['str', 80, true],
    ]],
    'customers' => ['table' => 'customers', 'parent' => null, 'fields' => [
        'name' => ['str', 160, true],
        'contact_name' => ['str', 120, false],
        'contact_phone' => ['str', 40, false],
        'contact_email' => ['str', 190, false],
        'is_active' => ['bool', 0, false],
    ]],
    'item-types' => ['table' => 'item_types', 'parent' => null, 'fields' => [
        'name' => ['str', 120, true],
        'is_active' => ['bool', 0, false],
    ]],
    'buildings' => ['table' => 'buildings', 'parent' => 'customer_id', 'fields' => [
        'area_id' => ['int', 0, true],
        'name' => ['str', 160, true],
        'street' => ['str', 160, true],
        'postcode' => ['str', 12, true],
        'city' => ['str', 80, true],
        'is_active' => ['bool', 0, false],
    ]],
    'zones' => ['table' => 'zones', 'parent' => 'building_id', 'fields' => [
        'name' => ['str', 120, true],
        'is_active' => ['bool', 0, false],
    ]],
];

/** Reads the fields that were sent. On create every required field must be there. */
function ref_values(string $resource, array $body, bool $creating): array
{
    $values = [];
    foreach (REFDATA[$resource]['fields'] as $name => [$type, $max, $required]) {
        if (!array_key_exists($name, $body)) {
            if ($creating && $required) {
                fail(422, 'invalid_input', "$name is required.");
            }
            continue;
        }
        $values[$name] = match ($type) {
            'str' => str_in($body, $name, $max, $required),
            'int' => int_in($body, $name, $required),
            'bool' => bool_in($body, $name),
        };
    }
    return $values;
}

function ref_row(array $row): array
{
    if (array_key_exists('is_active', $row)) {
        $row['is_active'] = (bool) $row['is_active'];
    }
    return $row;
}

function ref_get(string $resource, int $id): array
{
    $table = REFDATA[$resource]['table'];
    $row = q("SELECT * FROM $table WHERE id = ?", [$id])->fetch();
    if (!$row) {
        fail(404, 'not_found', 'No such record.');
    }
    return ref_row($row);
}

function ref_list(string $resource, ?int $parentId = null): array
{
    ['table' => $table, 'parent' => $parent] = REFDATA[$resource];
    $rows = $parent === null
        ? q("SELECT * FROM $table ORDER BY name")->fetchAll()
        : q("SELECT * FROM $table WHERE $parent = ? ORDER BY name", [$parentId])->fetchAll();
    return ['rows' => array_map('ref_row', $rows)];
}

function ref_create(string $resource, array $body, ?int $parentId = null): array
{
    ['table' => $table, 'parent' => $parent] = REFDATA[$resource];
    $values = ref_values($resource, $body, true);
    if ($parent !== null) {
        $values[$parent] = $parentId;
    }
    $columns = array_keys($values);
    q("INSERT INTO $table (" . implode(', ', $columns) . ') VALUES (' . marks($columns) . ')', array_values($values));
    return ['row' => ref_get($resource, (int) db()->lastInsertId())];
}

function ref_update(string $resource, int $id, array $body): array
{
    $table = REFDATA[$resource]['table'];
    ref_get($resource, $id);
    $values = ref_values($resource, $body, false);
    if ($values) {
        $sets = implode(', ', array_map(fn (string $column) => "$column = ?", array_keys($values)));
        q("UPDATE $table SET $sets WHERE id = ?", [...array_values($values), $id]);
    }
    return ['row' => ref_get($resource, $id)];
}
```

In `site/app/bootstrap.php` replace the file list:

```php
foreach (['db', 'http', 'kernel', 'auth', 'refdata'] as $file) {
    require __DIR__ . "/$file.php";
}
```

In `site/app/routes.php` add these entries at the end of the returned array:

```php
    ['GET',   '/areas',                    [$O],     fn ($r, $u) => ref_list('areas')],
    ['POST',  '/areas',                    [$O],     fn ($r, $u) => ref_create('areas', $r->body)],
    ['PATCH', '/areas/{id}',               [$O],     fn ($r, $u, $id) => ref_update('areas', $id, $r->body)],
    ['GET',   '/customers',                [$O],     fn ($r, $u) => ref_list('customers')],
    ['POST',  '/customers',                [$O],     fn ($r, $u) => ref_create('customers', $r->body)],
    ['PATCH', '/customers/{id}',           [$O],     fn ($r, $u, $id) => ref_update('customers', $id, $r->body)],
    ['GET',   '/item-types',               [$O, $I], fn ($r, $u) => ref_list('item-types')],
    ['POST',  '/item-types',               [$O],     fn ($r, $u) => ref_create('item-types', $r->body)],
    ['PATCH', '/item-types/{id}',          [$O],     fn ($r, $u, $id) => ref_update('item-types', $id, $r->body)],
    ['GET',   '/customers/{id}/buildings', [$O],     fn ($r, $u, $id) => ref_list('buildings', $id)],
    ['POST',  '/customers/{id}/buildings', [$O],     fn ($r, $u, $id) => ref_create('buildings', $r->body, $id)],
    ['PATCH', '/buildings/{id}',           [$O],     fn ($r, $u, $id) => ref_update('buildings', $id, $r->body)],
    ['GET',   '/buildings/{id}/zones',     [$O],     fn ($r, $u, $id) => ref_list('zones', $id)],
    ['POST',  '/buildings/{id}/zones',     [$O],     fn ($r, $u, $id) => ref_create('zones', $r->body, $id)],
    ['PATCH', '/zones/{id}',               [$O],     fn ($r, $u, $id) => ref_update('zones', $id, $r->body)],
```

- [ ] **Step 4: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/RefdataTest.php`
Expected: `OK (9 tests, ...)`.

- [ ] **Step 5: Run all tests and commit**

Run: `php vendor/bin/phpunit`
Expected: `OK (30 tests, ...)`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.2-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.2"/' composer.json
git add site/app/refdata.php site/app/bootstrap.php site/app/routes.php tests/RefdataTest.php README.md composer.json
git commit -m "Add office management of customers, buildings, zones and mat types at 0.4.2."
```

---

### Task 4: Users and the first office user

**Files:**
- Create: `site/app/users.php`, `site/bin/create-office-user.php`
- Modify: `site/app/bootstrap.php` (file list), `site/app/routes.php`
- Test: `tests/UsersTest.php`

**Interfaces:**
- Consumes: `q()`, `fail()`, `str_in()`, `enum_in()`, `bool_in()`, `user_view()`, `ROLES`, `ApiError`.
- Produces:
  - `user_insert(array $in): array` — validates `name`, `username`, `password`, `role`; returns `user_view`
  - Routes, office only: `GET /users` → `{"rows": [...]}`, `POST /users` → `{"user": {...}}`, `PATCH /users/{id}` → `{"user": {...}}`
  - CLI `php site/bin/create-office-user.php` — reads name, username and password from standard input

- [ ] **Step 1: Write the failing test**

Create `tests/UsersTest.php`:

```php
<?php
/** User management by the office, and the one-time command that creates the first office user. */
declare(strict_types=1);

final class UsersTest extends ApiTestCase
{
    private function newUser(array $over = []): array
    {
        return $over + ['name' => 'Dana Driver', 'username' => 'Dana.D', 'password' => 'long-password-1', 'role' => 'driver'];
    }

    public function test_office_adds_a_user_who_can_then_log_in(): void
    {
        $this->loginAs('office');
        $user = $this->ok($this->api('POST', '/users', $this->newUser()))['user'];
        $this->assertSame(['id', 'name', 'username', 'role', 'is_active'], array_keys($user));
        $this->assertSame('dana.d', $user['username']);

        $_SESSION = [];
        $out = $this->ok($this->api('POST', '/auth/login', ['username' => 'dana.d', 'password' => 'long-password-1']));
        $this->assertSame('driver', $out['user']['role']);
    }

    public function test_the_list_never_shows_a_password_hash(): void
    {
        $this->loginAs('office');
        $rows = $this->ok($this->api('GET', '/users'))['rows'];
        $this->assertCount(6, $rows);
        $this->assertStringNotContainsString('password', json_encode($rows));
    }

    public function test_bad_input_is_refused(): void
    {
        $this->loginAs('office');
        $this->assertError(422, 'invalid_input', $this->api('POST', '/users', $this->newUser(['password' => 'short'])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/users', $this->newUser(['password' => str_repeat('a', 73)])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/users', $this->newUser(['username' => 'has space'])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/users', $this->newUser(['role' => 'admin'])));
        $this->assertError(409, 'duplicate', $this->api('POST', '/users', $this->newUser(['username' => 'driver'])));
    }

    public function test_a_switched_off_user_cannot_log_in(): void
    {
        $this->loginAs('office');
        $user = $this->ok($this->api('PATCH', "/users/{$this->users['driver']}", ['is_active' => false]))['user'];
        $this->assertFalse($user['is_active']);

        $_SESSION = [];
        $this->assertError(401, 'bad_login',
            $this->api('POST', '/auth/login', ['username' => 'driver', 'password' => self::PASSWORD]));
    }

    public function test_office_changes_role_name_and_password(): void
    {
        $this->loginAs('office');
        $user = $this->ok($this->api('PATCH', "/users/{$this->users['outbound']}",
            ['role' => 'inventory', 'name' => 'Olga', 'password' => 'another-password-2']))['user'];
        $this->assertSame(['inventory', 'Olga'], [$user['role'], $user['name']]);

        $_SESSION = [];
        $this->ok($this->api('POST', '/auth/login', ['username' => 'outbound', 'password' => 'another-password-2']));
    }

    public function test_an_office_user_cannot_lock_himself_out(): void
    {
        $this->loginAs('office');
        $me = $this->users['office'];
        $this->assertError(422, 'self_lockout', $this->api('PATCH', "/users/$me", ['is_active' => false]));
        $this->assertError(422, 'self_lockout', $this->api('PATCH', "/users/$me", ['role' => 'driver']));
        $this->ok($this->api('PATCH', "/users/$me", ['name' => 'Olivia']));
    }

    public function test_unknown_user_is_404_and_other_roles_are_refused(): void
    {
        $this->loginAs('office');
        $this->assertError(404, 'not_found', $this->api('PATCH', '/users/999', ['name' => 'X']));
        $this->loginAs('inventory');
        $this->assertError(403, 'forbidden', $this->api('GET', '/users'));
    }

    public function test_the_command_creates_the_first_office_user(): void
    {
        // The child process gets DB_NAME explicitly, so it can never touch the real database.
        $env = ['DB_NAME' => cfg('DB_NAME'), 'APP_ENV' => 'test'] + getenv();
        $process = proc_open([PHP_BINARY, __DIR__ . '/../site/bin/create-office-user.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        fwrite($pipes[0], "First Office\nfirst.office\nlong-password-1\n");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);

        $out = $this->ok($this->api('POST', '/auth/login', ['username' => 'first.office', 'password' => 'long-password-1']));
        $this->assertSame('office', $out['user']['role']);
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/UsersTest.php`
Expected: FAIL: the API tests get `404 not_found`, the command test fails with `Could not open input file`.

- [ ] **Step 3: Write the implementation**

Create `site/app/users.php`:

```php
<?php
/** User management by the office. Passwords are stored only as a hash and never leave the server. */
declare(strict_types=1);

function username_in(array $in): string
{
    $username = strtolower(str_in($in, 'username', 60));
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
        fail(422, 'invalid_input', 'username must be 3 to 60 letters, digits, dots, dashes or underscores.');
    }
    return $username;
}

/** bcrypt reads only the first 72 bytes, so a longer password would be silently cut. */
function password_in(array $in): string
{
    $password = $in['password'] ?? null;
    if (!is_string($password) || strlen($password) < 10 || strlen($password) > 72) {
        fail(422, 'invalid_input', 'password must have 10 to 72 characters.');
    }
    return $password;
}

function user_by_id(int $id): array
{
    $user = q('SELECT id, name, username, role, is_active FROM users WHERE id = ?', [$id])->fetch();
    if (!$user) {
        fail(404, 'not_found', 'No such user.');
    }
    return user_view($user);
}

/** Validates and stores a new user. Used by POST /users and by bin/create-office-user.php. */
function user_insert(array $in): array
{
    $name = str_in($in, 'name', 120);
    $username = username_in($in);
    $role = enum_in($in, 'role', ROLES);
    $hash = password_hash(password_in($in), PASSWORD_DEFAULT);
    q('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, ?)', [$name, $username, $hash, $role]);
    return user_by_id((int) db()->lastInsertId());
}

function users_list(Request $req, array $user): array
{
    $rows = q('SELECT id, name, username, role, is_active FROM users ORDER BY name')->fetchAll();
    return ['rows' => array_map('user_view', $rows)];
}

function users_create(Request $req, array $user): array
{
    return ['user' => user_insert($req->body)];
}

function users_update(Request $req, array $me, int $id): array
{
    user_by_id($id);
    $in = $req->body;
    $set = [];
    if (array_key_exists('name', $in)) {
        $set['name'] = str_in($in, 'name', 120);
    }
    if (array_key_exists('role', $in)) {
        $set['role'] = enum_in($in, 'role', ROLES);
    }
    if (array_key_exists('is_active', $in)) {
        $set['is_active'] = bool_in($in, 'is_active');
    }
    if (array_key_exists('password', $in)) {
        $set['password_hash'] = password_hash(password_in($in), PASSWORD_DEFAULT);
    }
    $demoted = ($set['role'] ?? 'office') !== 'office' || ($set['is_active'] ?? true) === false;
    if ($id === $me['id'] && $demoted) {
        fail(422, 'self_lockout', 'You cannot switch off or demote yourself.');
    }
    if ($set) {
        $sets = implode(', ', array_map(fn (string $column) => "$column = ?", array_keys($set)));
        q("UPDATE users SET $sets WHERE id = ?", [...array_values($set), $id]);
    }
    return ['user' => user_by_id($id)];
}
```

Create `site/bin/create-office-user.php`:

```php
<?php
/** One-time command that creates the first office user. Run on the server: php site/bin/create-office-user.php */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

$ask = function (string $label): string {
    fwrite(STDOUT, "$label: ");
    return trim((string) fgets(STDIN));
};

try {
    $user = user_insert([
        'name' => $ask('Full name'),
        'username' => $ask('Username'),
        // ponytail: the password is visible while typing. Hide it if this is ever run on a shared screen.
        'password' => $ask('Password (10 to 72 characters, visible while you type)'),
        'role' => 'office',
    ]);
    fwrite(STDOUT, "Created office user {$user['username']}.\n");
} catch (ApiError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (PDOException $e) {
    fwrite(STDERR, "Could not create the user. Does the username already exist?\n");
    exit(1);
}
```

In `site/app/bootstrap.php` replace the file list:

```php
foreach (['db', 'http', 'kernel', 'auth', 'refdata', 'users'] as $file) {
    require __DIR__ . "/$file.php";
}
```

In `site/app/routes.php` add these entries at the end of the returned array:

```php
    ['GET',   '/users',      [$O], 'users_list'],
    ['POST',  '/users',      [$O], 'users_create'],
    ['PATCH', '/users/{id}', [$O], 'users_update'],
```

- [ ] **Step 4: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/UsersTest.php`
Expected: `OK (8 tests, ...)`.

- [ ] **Step 5: Check the web entry point by hand**

This is the first moment a real HTTP login is possible. It runs against the test database.

Start the server in the background (in the Bash tool: `run_in_background: true`):

```bash
DB_NAME=laundry_test php -S 127.0.0.1:8080 site/www/index.php
```

Then run:

```bash
printf 'Smoke Office\nsmoke\nsmoke-password-1\n' | DB_NAME=laundry_test php site/bin/create-office-user.php
JAR=$(mktemp)
curl -s -i -c "$JAR" -H 'Content-Type: application/json' \
  -d '{"username":"smoke","password":"smoke-password-1"}' http://127.0.0.1:8080/api/v1/auth/login
curl -s -b "$JAR" http://127.0.0.1:8080/api/v1/auth/me
curl -s -b "$JAR" -X POST http://127.0.0.1:8080/api/v1/auth/logout
curl -s http://127.0.0.1:8080/api/v1/auth/me
curl -s -H 'Content-Type: application/json' -d '{broken' http://127.0.0.1:8080/api/v1/auth/login
rm "$JAR"
```

Expected, in order:
1. The three prompts on one line, ending in `Created office user smoke.`
2. `HTTP/1.1 200 OK`, a header `Set-Cookie: laundry=...; path=/; HttpOnly; SameSite=Lax`, the headers `X-Content-Type-Options: nosniff` and `Cache-Control: no-store`, and a JSON body with `"user"` and `"csrf_token"`.
3. The same user as JSON.
4. `{"error":{"code":"csrf","message":"Missing or wrong CSRF token."}}` (the logout sent no token).
5. `{"error":{"code":"not_logged_in","message":"Log in first."}}`
6. `{"error":{"code":"invalid_input","message":"The request body is not valid JSON."}}`

Stop the background server. If any answer differs, fix `site/www/index.php` and repeat this step.

- [ ] **Step 6: Run all tests and commit**

Run: `php vendor/bin/phpunit`
Expected: `OK (38 tests, ...)`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.3-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.3"/' composer.json
git add site/app/users.php site/bin/create-office-user.php site/app/bootstrap.php site/app/routes.php tests/UsersTest.php README.md composer.json
git commit -m "Add user management and the first-office-user command at 0.4.3."
```

---

### Task 5: Tag reads, lookup, simulator list and mat registration

**Files:**
- Create: `site/app/scan.php`, `site/app/items.php`
- Modify: `site/app/bootstrap.php` (file list), `site/app/routes.php`
- Test: `tests/ScanTest.php`

**Interfaces:**
- Consumes: `q()`, `tx()`, `marks()`, `cfg()`, `fail()`, `str_in()`, `int_in()`, `enum_in()`.
- Produces:
  - `const ITEM_STATUSES` (the eleven statuses), `const ITEM_SELECT` (`SELECT i.*, t.name AS type_name FROM items i JOIN item_types t ...`)
  - `simulator_on(): bool`
  - `hex_in(array $in, string $key): string` — uppercase hex, 8 to 64 characters, else 422 `invalid_tag`
  - `source_in(array $in): string` — `simulator` or `c72`
  - `reads_in(array $in): array` — unique uppercase EPCs from `{"reads": [{"epc": ...}]}`, in first-heard order
  - `items_by_epc(array $epcs, bool $lock = false): array` — item rows keyed by EPC, each with `type_name`; `$lock` adds `FOR UPDATE`
  - `item_by_id(int $id, bool $lock = false): array` — 404 when unknown
  - `item_view(array $item): array` — `id, sku, type, status, wash_count, current_wash_date, previous_wash_date`
  - `log_event(array $item, int $userId, string $type, string $to, string $source, array $extra = []): void` — `from_status` is `$item['status']`; `$extra` may hold `handover_id`, `task_id`, `zone_id`, `note`
  - `scan_result(string $epc, string $result, ?array $item, ?string $reason = null): array`
  - Every scan endpoint answers `{"results": [{"epc", "result", "item", "reason"?}], "missing": [...]}` with `result` one of `found`, `not_on_list`, `unknown`, `rejected`
  - Routes: `POST /scan/lookup` (inventory, driver, washing, outbound), `GET /sim/items` (same four), `POST /items` (inventory)

- [ ] **Step 1: Write the failing test**

Create `tests/ScanTest.php`:

```php
<?php
/** Tag reads, the lookup that changes nothing, the simulator's mat list, and registering a new mat. */
declare(strict_types=1);

final class ScanTest extends ApiTestCase
{
    private function newMat(array $over = []): array
    {
        return $over + ['source' => 'simulator', 'epc' => '4D4F434B00000000000000FF',
            'tid_primary' => '4D4F434B000A0000000000FF', 'tid_secondary' => '4D4F434B000B0000000000FF',
            'sku' => 'MOCK-0255', 'item_type_id' => 1];
    }

    public function test_lookup_tells_what_each_mat_is_and_changes_nothing(): void
    {
        $this->loginAs('outbound');
        $out = $this->ok($this->api('POST', '/scan/lookup', ['source' => 'simulator', 'reads' => [
            ['epc' => $this->epc(1)],
            ['epc' => strtolower($this->epc(1))],          // the same mat heard again, in lowercase
            ['epc' => $this->epc(2)],
            ['epc' => 'AAAA00000000000000000000'],
        ]]));

        $this->assertCount(3, $out['results']);
        $this->assertSame(['found', 'found', 'unknown'], array_column($out['results'], 'result'));
        // Exactly these fields: a lookup never says at which customer a mat lies.
        $this->assertSame(['id', 'sku', 'type', 'status', 'wash_count', 'current_wash_date', 'previous_wash_date'],
            array_keys($out['results'][0]['item']));
        $this->assertSame(['MOCK-0001', 'Mock mat 85x150 grey', 'ready'],
            [$out['results'][0]['item']['sku'], $out['results'][0]['item']['type'], $out['results'][0]['item']['status']]);
        $this->assertNull($out['results'][2]['item']);
        $this->assertSame([], $out['missing']);
        $this->assertSame(0, (int) q('SELECT COUNT(*) FROM item_events')->fetchColumn());
    }

    public function test_a_tag_id_made_of_digits_only_is_found(): void
    {
        q("INSERT INTO items (item_type_id, sku, epc, tid_primary, tid_secondary)
           VALUES (1, 'DIGITS-8', '12345678', 'A0000001', 'A0000002'),
                  (1, 'DIGITS-24', '123456789012345678901234', 'A0000003', 'A0000004')");
        $this->loginAs('inventory');
        $out = $this->ok($this->api('POST', '/scan/lookup',
            ['source' => 'simulator', 'reads' => [['epc' => '12345678'], ['epc' => '123456789012345678901234']]]));
        $this->assertSame(['12345678', '123456789012345678901234'], array_column($out['results'], 'epc'));
        $this->assertSame(['DIGITS-8', 'DIGITS-24'], array_column(array_column($out['results'], 'item'), 'sku'));
    }

    public function test_a_broken_scan_body_is_refused(): void
    {
        $this->loginAs('driver');
        $this->assertError(422, 'invalid_input', $this->api('POST', '/scan/lookup', ['source' => 'simulator', 'reads' => []]));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/scan/lookup', ['source' => 'simulator']));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/scan/lookup', ['reads' => [['epc' => $this->epc(1)]]]));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/scan/lookup', ['source' => 'web'] + $this->reads(1)));
        $this->assertError(422, 'invalid_tag', $this->api('POST', '/scan/lookup', ['source' => 'simulator', 'reads' => [['epc' => 'NOT-HEX!']]]));
        $this->assertError(422, 'invalid_tag', $this->api('POST', '/scan/lookup', ['source' => 'simulator', 'reads' => ['just a string']]));
    }

    public function test_the_office_has_no_scanner(): void
    {
        $this->loginAs('office');
        $this->assertError(403, 'forbidden', $this->api('POST', '/scan/lookup', $this->reads(1)));
        $this->assertError(403, 'forbidden', $this->api('GET', '/sim/items'));
    }

    public function test_the_simulator_can_hear_every_mat(): void
    {
        $this->loginAs('washing');
        $items = $this->ok($this->api('GET', '/sim/items'))['items'];
        $this->assertCount(40, $items);
        $this->assertSame(['sku' => 'MOCK-0001', 'epc' => $this->epc(1)], $items[0]);
    }

    public function test_switching_the_simulator_off_closes_its_list_and_refuses_its_scans(): void
    {
        $this->loginAs('driver');
        putenv('SCANNER_SIMULATOR=false');
        try {
            $this->assertError(404, 'not_found', $this->api('GET', '/sim/items'));
            $this->assertError(403, 'simulator_off', $this->api('POST', '/scan/lookup', $this->reads(1)));
            $this->ok($this->api('POST', '/scan/lookup', ['source' => 'c72'] + $this->reads(1)));
        } finally {
            putenv('SCANNER_SIMULATOR=true');
        }
    }

    public function test_register_stores_a_new_mat_and_writes_history(): void
    {
        $this->loginAs('inventory');
        $item = $this->ok($this->api('POST', '/items', $this->newMat(['epc' => '4d4f434b00000000000000ff'])))['item'];
        $this->assertSame(['MOCK-0255', 'registered', 0], [$item['sku'], $item['status'], $item['wash_count']]);

        $row = q("SELECT * FROM items WHERE sku = 'MOCK-0255'")->fetch();
        $this->assertSame(['4D4F434B00000000000000FF', '4D4F434B000A0000000000FF', '4D4F434B000B0000000000FF'],
            [$row['epc'], $row['tid_primary'], $row['tid_secondary']]);

        $event = q('SELECT * FROM item_events WHERE item_id = ?', [$row['id']])->fetch();
        $this->assertSame(['register', null, 'registered', 'simulator', $this->users['inventory']],
            [$event['event_type'], $event['from_status'], $event['to_status'], $event['source'], $event['user_id']]);
    }

    public function test_register_refuses_broken_input(): void
    {
        $this->loginAs('inventory');
        $this->assertError(422, 'same_tid', $this->api('POST', '/items',
            $this->newMat(['tid_secondary' => '4D4F434B000A0000000000FF'])));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/items', $this->newMat(['item_type_id' => 999])));
        $this->assertError(422, 'invalid_tag', $this->api('POST', '/items', $this->newMat(['epc' => 'XYZ'])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/items', $this->newMat(['sku' => ''])));
        $this->assertSame(40, (int) q('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function test_register_refuses_a_tag_or_sku_that_is_in_use(): void
    {
        $this->loginAs('inventory');
        $this->assertError(409, 'duplicate', $this->api('POST', '/items', $this->newMat(['epc' => $this->epc(1)])));
        $this->assertError(409, 'duplicate', $this->api('POST', '/items', $this->newMat(['sku' => 'mock-0001'])));
        // Mat 1's secondary TID offered as a primary TID: two unique keys alone would not catch this.
        $this->assertError(409, 'duplicate', $this->api('POST', '/items',
            $this->newMat(['tid_primary' => '4D4F434B000B000000000001'])));
        $this->ok($this->api('POST', '/items', $this->newMat()));
        $this->assertError(409, 'duplicate', $this->api('POST', '/items', $this->newMat()));   // sent twice
    }

    public function test_only_the_inventory_manager_registers(): void
    {
        foreach (['office', 'driver', 'washing', 'outbound'] as $who) {
            $this->loginAs($who);
            $this->assertError(403, 'forbidden', $this->api('POST', '/items', $this->newMat()));
        }
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/ScanTest.php`
Expected: FAIL, the tests get `404 not_found`.

- [ ] **Step 3: Write the shared scan code**

Create `site/app/scan.php`:

```php
<?php
/** What every scan shares: reading tag ids, finding the mats, the mat as a scanner sees it, and writing history. */
declare(strict_types=1);

const ITEM_STATUSES = ['registered', 'ready', 'qc_passed', 'rewash', 'repair', 'scrapped',
    'out_for_delivery', 'refused', 'at_customer', 'collected', 'in_washing'];

const ITEM_SELECT = 'SELECT i.*, t.name AS type_name FROM items i JOIN item_types t ON t.id = i.item_type_id';

// A pile read hears every tag many times, so one request may carry far more reads than mats.
const MAX_READS = 5000;

function simulator_on(): bool
{
    return cfg('SCANNER_SIMULATOR') === 'true';
}

/** A tag id: 8 to 64 hex characters, returned in uppercase. */
function hex_in(array $in, string $key): string
{
    $value = $in[$key] ?? null;
    $hex = is_string($value) ? strtoupper(trim($value)) : '';
    if (!preg_match('/^[0-9A-F]{8,64}$/', $hex)) {
        fail(422, 'invalid_tag', "$key must be 8 to 64 hex characters.");
    }
    return $hex;
}

/** Where a scan came from. The simulator may only speak while it is switched on. */
function source_in(array $in): string
{
    $source = enum_in($in, 'source', ['simulator', 'c72']);
    if ($source === 'simulator' && !simulator_on()) {
        fail(403, 'simulator_off', 'The scanner simulator is switched off.');
    }
    return $source;
}

/** The EPCs of a scan: uppercase, each one once, in the order they were first heard. */
function reads_in(array $in): array
{
    $reads = $in['reads'] ?? null;
    if (!is_array($reads) || !array_is_list($reads) || $reads === [] || count($reads) > MAX_READS) {
        fail(422, 'invalid_input', 'reads must be a list of 1 to ' . MAX_READS . ' tag reads.');
    }
    $epcs = [];
    foreach ($reads as $read) {
        $epcs[] = hex_in(is_array($read) ? $read : [], 'epc');
    }
    // Keep the EPCs as values. As array keys PHP would turn an all-digit EPC into a number.
    return array_values(array_unique($epcs));
}

/** The mats with these EPCs, keyed by EPC. Read each mat's EPC from $item['epc'], never from the key. */
function items_by_epc(array $epcs, bool $lock = false): array
{
    if ($epcs === []) {
        return [];
    }
    $sql = ITEM_SELECT . ' WHERE i.epc IN (' . marks($epcs) . ')' . ($lock ? ' FOR UPDATE' : '');
    return array_column(q($sql, $epcs)->fetchAll(), null, 'epc');
}

function item_by_id(int $id, bool $lock = false): array
{
    $item = q(ITEM_SELECT . ' WHERE i.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch();
    if (!$item) {
        fail(404, 'not_found', 'No such mat.');
    }
    return $item;
}

/** A mat as every scanning role may see it. It never says at which customer the mat lies. */
function item_view(array $item): array
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
function log_event(array $item, int $userId, string $type, string $to, string $source, array $extra = []): void
{
    q('INSERT INTO item_events (item_id, user_id, event_type, from_status, to_status, handover_id, task_id, zone_id, note, source)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$item['id'], $userId, $type, $item['status'], $to, $extra['handover_id'] ?? null, $extra['task_id'] ?? null,
            $extra['zone_id'] ?? null, $extra['note'] ?? null, $source]);
}

/** One line of a scan answer. */
function scan_result(string $epc, string $result, ?array $item, ?string $reason = null): array
{
    $line = ['epc' => $epc, 'result' => $result, 'item' => $item === null ? null : item_view($item)];
    if ($reason !== null) {
        $line['reason'] = $reason;
    }
    return $line;
}

function scan_lookup(Request $req, array $user): array
{
    source_in($req->body);
    $epcs = reads_in($req->body);
    $items = items_by_epc($epcs);
    $results = [];
    foreach ($epcs as $epc) {
        $results[] = isset($items[$epc]) ? scan_result($epc, 'found', $items[$epc]) : scan_result($epc, 'unknown', null);
    }
    return ['results' => $results, 'missing' => []];
}

/** Simulator only: a real scanner hears any mat near it, so the pile panel must be able to pick any mat. */
function sim_items(Request $req, array $user): array
{
    if (!simulator_on()) {
        fail(404, 'not_found', 'No such path.');
    }
    return ['items' => q('SELECT sku, epc FROM items ORDER BY sku')->fetchAll()];
}
```

- [ ] **Step 4: Write mat registration**

Create `site/app/items.php`:

```php
<?php
/** Mats: registering, releasing, the quality check, office corrections, the mat list and the mat history. */
declare(strict_types=1);

function items_register(Request $req, array $user): array
{
    $in = $req->body;
    $source = source_in($in);
    $epc = hex_in($in, 'epc');
    $tidPrimary = hex_in($in, 'tid_primary');
    $tidSecondary = hex_in($in, 'tid_secondary');
    $sku = str_in($in, 'sku', 64);
    $typeId = int_in($in, 'item_type_id');

    if ($tidPrimary === $tidSecondary) {
        fail(422, 'same_tid', 'The two tags of a mat must have different TIDs.');
    }
    if (!q('SELECT 1 FROM item_types WHERE id = ? AND is_active = 1', [$typeId])->fetch()) {
        fail(422, 'invalid_reference', 'Unknown mat type.');
    }

    return tx(function () use ($source, $epc, $tidPrimary, $tidSecondary, $sku, $typeId, $user): array {
        // Also catches a TID that is primary on one mat and secondary on another, which the unique keys cannot.
        $clash = q('SELECT sku FROM items WHERE epc = ? OR sku = ? OR tid_primary IN (?, ?) OR tid_secondary IN (?, ?) LIMIT 1',
            [$epc, $sku, $tidPrimary, $tidSecondary, $tidPrimary, $tidSecondary])->fetch();
        if ($clash) {
            fail(409, 'duplicate', "The EPC, the SKU or a TID is already in use on {$clash['sku']}.");
        }
        q('INSERT INTO items (item_type_id, sku, epc, tid_primary, tid_secondary) VALUES (?, ?, ?, ?, ?)',
            [$typeId, $sku, $epc, $tidPrimary, $tidSecondary]);
        $item = item_by_id((int) db()->lastInsertId());
        log_event(['status' => null] + $item, $user['id'], 'register', 'registered', $source);
        return ['item' => item_view($item)];
    });
}
```

In `site/app/bootstrap.php` replace the file list:

```php
foreach (['db', 'http', 'kernel', 'auth', 'refdata', 'users', 'scan', 'items'] as $file) {
    require __DIR__ . "/$file.php";
}
```

In `site/app/routes.php` add these entries at the end of the returned array:

```php
    ['POST', '/scan/lookup', $scanners, 'scan_lookup'],
    ['GET',  '/sim/items',   $scanners, 'sim_items'],
    ['POST', '/items',       [$I],      'items_register'],
```

- [ ] **Step 5: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/ScanTest.php`
Expected: `OK (10 tests, ...)`.

- [ ] **Step 6: Run all tests and commit**

Run: `php vendor/bin/phpunit`
Expected: `OK (48 tests, ...)`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.4-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.4"/' composer.json
git add site/app/scan.php site/app/items.php site/app/bootstrap.php site/app/routes.php tests/ScanTest.php README.md composer.json
git commit -m "Add tag reads, lookup, simulator list and mat registration at 0.4.4."
```

---

### Task 6: Ready, quality check, correction, mat list and mat history

**Files:**
- Modify: `site/app/items.php` (add functions at the end), `site/app/routes.php`
- Test: `tests/ItemsTest.php`

**Interfaces:**
- Consumes: `source_in()`, `reads_in()`, `hex_in()`, `items_by_epc()`, `item_by_id()`, `item_view()`, `log_event()`, `scan_result()`, `ITEM_STATUSES`, `q()`, `tx()`, `str_in()`, `int_in()`, `enum_in()`.
- Produces:
  - `const HELD_STATUSES = ['out_for_delivery', 'refused', 'collected']` — the statuses in which a driver carries the mat
  - `item_office_view(array $item): array` — `item_view` plus `epc, tid_primary, tid_secondary, item_type_id, holder, location`
  - Routes:
    - `POST /items/ready` (inventory) — `{source, reads}` → scan answer
    - `POST /items/qc` (inventory) — `{source, epc, result: pass|rewash|repair|scrap, note?}` → scan answer with one line
    - `POST /items/{id}/correct` (office) — `{status, holder_user_id, zone_id, note}` → `{"item": {...}}`
    - `GET /items` (office, inventory, driver, washing) — `?status=&type=&q=&page=` → `{"items": [...], "page", "per_page", "total"}`
    - `GET /items/{id}` (office, washing) → `{"item": {...}, "events": [...]}`

- [ ] **Step 1: Write the failing test**

Create `tests/ItemsTest.php`:

```php
<?php
/** Releasing mats, the quality check, office corrections, the mat list per role and the mat history. */
declare(strict_types=1);

final class ItemsTest extends ApiTestCase
{
    private function qc(int $mat, string $result, ?string $note = null): array
    {
        return $this->api('POST', '/items/qc',
            ['source' => 'simulator', 'epc' => $this->epc($mat), 'result' => $result, 'note' => $note]);
    }

    private function correct(int $mat, array $body): array
    {
        return $this->api('POST', "/items/{$this->item($mat)['id']}/correct", $body);
    }

    public function test_ready_releases_registered_and_repaired_mats(): void
    {
        $this->setItems([1], 'registered');
        $this->setItems([2], 'repair');
        $this->loginAs('inventory');
        $out = $this->ok($this->api('POST', '/items/ready', $this->reads(1, 2)));

        $this->assertSame(['found', 'found'], array_column($out['results'], 'result'));
        $this->assertSame('ready', $out['results'][0]['item']['status']);
        $this->assertSame(['ready', 'ready'], [$this->item(1)['status'], $this->item(2)['status']]);
        $event = $this->events(1)[0];
        $this->assertSame(['ready', 'registered', 'ready'], [$event['event_type'], $event['from_status'], $event['to_status']]);
    }

    public function test_ready_scanned_twice_changes_and_logs_once(): void
    {
        $this->setItems([1], 'registered');
        $this->loginAs('inventory');
        $this->ok($this->api('POST', '/items/ready', $this->reads(1)));
        $again = $this->ok($this->api('POST', '/items/ready', $this->reads(1)));
        $this->assertSame('found', $again['results'][0]['result']);
        $this->assertCount(1, $this->events(1));
    }

    public function test_ready_rejects_a_mat_in_another_status_and_an_unknown_tag(): void
    {
        $this->setItems([1], 'qc_passed');
        $this->loginAs('inventory');
        $out = $this->ok($this->api('POST', '/items/ready',
            ['source' => 'simulator', 'reads' => [['epc' => $this->epc(1)], ['epc' => 'AAAA0000']]]));
        $this->assertSame(['rejected', 'unknown'], array_column($out['results'], 'result'));
        $this->assertSame('MOCK-0001 is qc_passed, not registered or repair.', $out['results'][0]['reason']);
        $this->assertSame('qc_passed', $this->item(1)['status']);
        $this->assertCount(0, $this->events(1));
    }

    public function test_quality_check_sends_the_mat_to_one_of_four_statuses(): void
    {
        $this->loginAs('inventory');
        foreach ([1 => ['pass', 'qc_passed'], 2 => ['rewash', 'rewash'], 3 => ['repair', 'repair'], 4 => ['scrap', 'scrapped']] as $mat => [$result, $status]) {
            $out = $this->ok($this->qc($mat, $result, "note for $result"));
            $this->assertSame(['found', $status], [$out['results'][0]['result'], $out['results'][0]['item']['status']]);
            $this->assertSame($status, $this->item($mat)['status']);
        }
        $event = $this->events(4)[0];
        $this->assertSame(['qc', 'ready', 'scrapped', 'note for scrap'],
            [$event['event_type'], $event['from_status'], $event['to_status'], $event['note']]);
    }

    public function test_quality_check_needs_a_ready_mat_and_counts_once(): void
    {
        $this->loginAs('inventory');
        $this->ok($this->qc(1, 'pass'));
        $this->assertSame('found', $this->ok($this->qc(1, 'pass'))['results'][0]['result']);   // sent twice
        $this->assertCount(1, $this->events(1));

        $out = $this->ok($this->qc(1, 'scrap'));
        $this->assertSame(['rejected', 'MOCK-0001 is qc_passed, not ready.'], [$out['results'][0]['result'], $out['results'][0]['reason']]);
        $this->assertSame('qc_passed', $this->item(1)['status']);

        $this->assertSame('unknown', $this->ok($this->api('POST', '/items/qc',
            ['source' => 'simulator', 'epc' => 'AAAA0000', 'result' => 'pass']))['results'][0]['result']);
        $this->assertError(422, 'invalid_input', $this->qc(2, 'maybe'));
    }

    public function test_a_correction_needs_a_note_and_a_possible_state(): void
    {
        $this->loginAs('office');
        $driver = $this->users['driver'];
        $this->assertError(422, 'invalid_input', $this->correct(1, ['status' => 'in_washing']));
        $this->assertError(422, 'invalid_input', $this->correct(1, ['status' => 'bogus', 'note' => 'x']));
        $this->assertError(422, 'rule_violation', $this->correct(1, ['status' => 'at_customer', 'note' => 'x']));
        $this->assertError(422, 'rule_violation', $this->correct(1, ['status' => 'ready', 'zone_id' => 1, 'note' => 'x']));
        $this->assertError(422, 'rule_violation', $this->correct(1, ['status' => 'collected', 'note' => 'x']));
        $this->assertError(422, 'rule_violation', $this->correct(1, ['status' => 'ready', 'holder_user_id' => $driver, 'note' => 'x']));
        $this->assertError(422, 'invalid_reference', $this->correct(1, ['status' => 'at_customer', 'zone_id' => 999, 'note' => 'x']));
        $this->assertError(422, 'invalid_reference',
            $this->correct(1, ['status' => 'collected', 'holder_user_id' => $this->users['washing'], 'note' => 'x']));
        $this->assertError(404, 'not_found', $this->api('POST', '/items/999/correct', ['status' => 'ready', 'note' => 'x']));
        $this->assertSame('ready', $this->item(1)['status']);
        $this->assertCount(0, $this->events(1));
    }

    public function test_a_correction_moves_the_mat_and_leaves_a_trace(): void
    {
        $this->loginAs('office');
        $item = $this->ok($this->correct(1, ['status' => 'at_customer', 'zone_id' => 1, 'note' => 'Found at the bakery']))['item'];
        $this->assertSame('at_customer', $item['status']);
        $this->assertSame(['at_customer', 1, null], [$this->item(1)['status'], $this->item(1)['zone_id'], $this->item(1)['holder_user_id']]);

        $event = $this->events(1)[0];
        $this->assertSame(['correction', 'ready', 'at_customer', 'Found at the bakery', 'web', 1, $this->users['office']],
            [$event['event_type'], $event['from_status'], $event['to_status'], $event['note'], $event['source'], $event['zone_id'], $event['user_id']]);

        $this->ok($this->correct(1, ['status' => 'collected', 'holder_user_id' => $this->users['driver'], 'note' => 'On the van']));
        $this->assertSame(['collected', null, $this->users['driver']],
            [$this->item(1)['status'], $this->item(1)['zone_id'], $this->item(1)['holder_user_id']]);
    }

    public function test_only_the_office_corrects(): void
    {
        $this->loginAs('inventory');
        $this->assertError(403, 'forbidden', $this->correct(1, ['status' => 'repair', 'note' => 'x']));
    }

    public function test_the_office_lists_searches_and_pages_all_mats(): void
    {
        $this->setItems([5], 'at_customer', null, 1);
        $this->setItems([6], 'collected', $this->users['driver']);
        $this->loginAs('office');

        $all = $this->ok($this->api('GET', '/items'));
        $this->assertSame([40, 1, 50, 40], [$all['total'], $all['page'], $all['per_page'], count($all['items'])]);
        $this->assertSame('MOCK-0001', $all['items'][0]['sku']);
        $this->assertSame($this->epc(1), $all['items'][0]['epc']);

        $this->assertSame(10, $this->ok($this->api('GET', '/items?q=MOCK-001'))['total']);
        $this->assertSame(1, $this->ok($this->api('GET', '/items?q=' . substr($this->epc(7), -6)))['total']);
        $this->assertSame(8, $this->ok($this->api('GET', '/items?status=ready&type=3'))['total']);
        $this->assertSame([], $this->ok($this->api('GET', '/items?page=2'))['items']);
        $this->assertError(422, 'invalid_input', $this->api('GET', '/items?status=bogus'));

        $atCustomer = $this->ok($this->api('GET', '/items?status=at_customer'))['items'][0];
        $this->assertSame(['zone_id' => 1, 'zone' => 'Front door', 'building' => 'Mock Bakery shop', 'customer' => 'Mock Bakery BV'],
            $atCustomer['location']);
        $carried = $this->ok($this->api('GET', '/items?status=collected'))['items'][0];
        $this->assertSame(['id' => $this->users['driver'], 'name' => 'Driver'], $carried['holder']);
    }

    public function test_a_search_for_percent_or_underscore_matches_them_literally(): void
    {
        q("UPDATE items SET sku = '50%OFF' WHERE sku = 'MOCK-0001'");
        q("UPDATE items SET sku = 'A_B' WHERE sku = 'MOCK-0002'");
        $this->loginAs('office');
        $this->assertSame(['50%OFF'], array_column($this->ok($this->api('GET', '/items?q=%25'))['items'], 'sku'));
        $this->assertSame(['A_B'], array_column($this->ok($this->api('GET', '/items?q=_'))['items'], 'sku'));
    }

    public function test_each_role_sees_only_its_own_mats(): void
    {
        $this->setItems([1], 'out_for_delivery', $this->users['driver']);
        $this->setItems([2], 'collected', $this->users['driver2']);
        $this->setItems([3], 'in_washing');
        $this->setItems([4], 'at_customer', null, 1);

        $this->loginAs('inventory');
        $inventory = $this->ok($this->api('GET', '/items'));
        $this->assertSame(36, $inventory['total']);
        $this->assertArrayNotHasKey('epc', $inventory['items'][0]);
        $this->assertArrayNotHasKey('location', $inventory['items'][0]);

        $this->loginAs('driver');
        $this->assertSame(['MOCK-0001'], array_column($this->ok($this->api('GET', '/items'))['items'], 'sku'));
        $this->loginAs('washing');
        $this->assertSame(['MOCK-0003'], array_column($this->ok($this->api('GET', '/items'))['items'], 'sku'));
        $this->loginAs('outbound');
        $this->assertError(403, 'forbidden', $this->api('GET', '/items'));
    }

    public function test_the_mat_history_is_full_for_the_office_and_trimmed_for_washing(): void
    {
        $id = $this->item(1)['id'];
        $this->loginAs('office');
        $this->ok($this->correct(1, ['status' => 'at_customer', 'zone_id' => 1, 'note' => 'Found at the bakery']));

        $office = $this->ok($this->api('GET', "/items/$id"));
        $this->assertSame($this->epc(1), $office['item']['epc']);
        $this->assertSame(['Found at the bakery', 'Front door', 'Office'],
            [$office['events'][0]['note'], $office['events'][0]['zone_name'], $office['events'][0]['user_name']]);
        $this->assertError(404, 'not_found', $this->api('GET', '/items/999'));

        $this->loginAs('washing');
        $washing = $this->ok($this->api('GET', "/items/$id"));
        $this->assertArrayNotHasKey('epc', $washing['item']);
        $this->assertSame(['id', 'created_at', 'event_type', 'from_status', 'to_status', 'source', 'handover_id', 'user_name'],
            array_keys($washing['events'][0]));

        $this->loginAs('driver');
        $this->assertError(403, 'forbidden', $this->api('GET', "/items/$id"));
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/ItemsTest.php`
Expected: FAIL: `POST /items/ready` and `POST /items/qc` get `404 not_found`, `GET /items` gets `405 method_not_allowed`.

- [ ] **Step 3: Write the implementation**

Add at the end of `site/app/items.php`:

```php
// The statuses in which a driver carries the mat. The database demands a holder exactly for these.
const HELD_STATUSES = ['out_for_delivery', 'refused', 'collected'];

const QC_RESULTS = ['pass' => 'qc_passed', 'rewash' => 'rewash', 'repair' => 'repair', 'scrap' => 'scrapped'];

const ITEMS_PER_PAGE = 50;

const ITEM_OFFICE_SELECT = 'SELECT i.*, t.name AS type_name, u.name AS holder_name, z.name AS zone_name,
        b.name AS building_name, c.name AS customer_name
    FROM items i
    JOIN item_types t ON t.id = i.item_type_id
    LEFT JOIN users u ON u.id = i.holder_user_id
    LEFT JOIN zones z ON z.id = i.zone_id
    LEFT JOIN buildings b ON b.id = z.building_id
    LEFT JOIN customers c ON c.id = b.customer_id';

/** A mat as the office sees it: with its tags, who carries it and where it lies. */
function item_office_view(array $item): array
{
    return item_view($item) + [
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

function items_ready(Request $req, array $user): array
{
    $source = source_in($req->body);
    $epcs = reads_in($req->body);
    return tx(function () use ($source, $epcs, $user): array {
        $items = items_by_epc($epcs, true);
        $results = [];
        foreach ($epcs as $epc) {
            $item = $items[$epc] ?? null;
            if ($item === null) {
                $results[] = scan_result($epc, 'unknown', null);
            } elseif ($item['status'] === 'ready') {
                $results[] = scan_result($epc, 'found', $item);   // a second scan: nothing to change
            } elseif (!in_array($item['status'], ['registered', 'repair'], true)) {
                $results[] = scan_result($epc, 'rejected', $item, "{$item['sku']} is {$item['status']}, not registered or repair.");
            } else {
                q("UPDATE items SET status = 'ready' WHERE id = ? AND status IN ('registered', 'repair')", [$item['id']]);
                log_event($item, $user['id'], 'ready', 'ready', $source);
                $results[] = scan_result($epc, 'found', ['status' => 'ready'] + $item);
            }
        }
        return ['results' => $results, 'missing' => []];
    });
}

function items_qc(Request $req, array $user): array
{
    $in = $req->body;
    $source = source_in($in);
    $epc = hex_in($in, 'epc');
    $to = QC_RESULTS[enum_in($in, 'result', array_keys(QC_RESULTS))];
    $note = str_in($in, 'note', 500, false);
    return tx(function () use ($source, $epc, $to, $note, $user): array {
        $item = items_by_epc([$epc], true)[$epc] ?? null;
        if ($item === null) {
            $result = scan_result($epc, 'unknown', null);
        } elseif ($item['status'] === $to) {
            $result = scan_result($epc, 'found', $item);   // a second scan: nothing to change
        } elseif ($item['status'] !== 'ready') {
            $result = scan_result($epc, 'rejected', $item, "{$item['sku']} is {$item['status']}, not ready.");
        } else {
            q("UPDATE items SET status = ? WHERE id = ? AND status = 'ready'", [$to, $item['id']]);
            log_event($item, $user['id'], 'qc', $to, $source, ['note' => $note]);
            $result = scan_result($epc, 'found', ['status' => $to] + $item);
        }
        return ['results' => [$result], 'missing' => []];
    });
}

/** The office may put a mat into any status, but only with a note, and never into a state the database refuses. */
function items_correct(Request $req, array $user, int $id): array
{
    $in = $req->body;
    $status = enum_in($in, 'status', ITEM_STATUSES);
    $holder = int_in($in, 'holder_user_id', false);
    $zone = int_in($in, 'zone_id', false);
    $note = str_in($in, 'note', 500);

    if (($status === 'at_customer') !== ($zone !== null)) {
        fail(422, 'rule_violation', 'A mat has a zone exactly when it is at a customer.');
    }
    if (in_array($status, HELD_STATUSES, true) !== ($holder !== null)) {
        fail(422, 'rule_violation', 'A mat has a holder exactly when a driver carries it.');
    }
    if ($holder !== null && !q("SELECT 1 FROM users WHERE id = ? AND role = 'driver' AND is_active = 1", [$holder])->fetch()) {
        fail(422, 'invalid_reference', 'The holder must be an active driver.');
    }

    return tx(function () use ($id, $status, $holder, $zone, $note, $user): array {
        $item = item_by_id($id, true);
        q('UPDATE items SET status = ?, holder_user_id = ?, zone_id = ? WHERE id = ?', [$status, $holder, $zone, $id]);
        log_event($item, $user['id'], 'correction', $status, 'web', ['note' => $note, 'zone_id' => $zone]);
        return ['item' => item_view(['status' => $status] + $item)];
    });
}

function items_list(Request $req, array $user): array
{
    $where = ['1 = 1'];
    $params = [];
    // Each scanning role sees only the mats that are with it.
    if ($user['role'] === 'inventory') {
        $where[] = "i.status IN ('registered', 'ready', 'qc_passed', 'rewash', 'repair')";
    } elseif ($user['role'] === 'driver') {
        $where[] = 'i.holder_user_id = ?';
        $params[] = $user['id'];
    } elseif ($user['role'] === 'washing') {
        $where[] = "i.status = 'in_washing'";
    }

    $filter = $req->query;
    if (($filter['status'] ?? '') !== '') {
        $where[] = 'i.status = ?';
        $params[] = enum_in($filter, 'status', ITEM_STATUSES);
    }
    if (($filter['type'] ?? '') !== '') {
        $where[] = 'i.item_type_id = ?';
        $params[] = int_in($filter, 'type');
    }
    if (($filter['q'] ?? '') !== '') {
        // Escape the LIKE wildcards, so a search for "%" finds a literal "%" and not every mat.
        $like = '%' . addcslashes(str_in($filter, 'q', 64), '%_\\') . '%';
        $where[] = '(i.sku LIKE ? OR i.epc LIKE ?)';
        array_push($params, $like, $like);
    }
    $page = max(1, int_in($filter, 'page', false) ?? 1);

    $sqlWhere = ' WHERE ' . implode(' AND ', $where);
    $total = (int) q('SELECT COUNT(*) FROM items i' . $sqlWhere, $params)->fetchColumn();
    $rows = q(ITEM_OFFICE_SELECT . $sqlWhere . ' ORDER BY i.sku LIMIT ? OFFSET ?',
        [...$params, ITEMS_PER_PAGE, ($page - 1) * ITEMS_PER_PAGE])->fetchAll();

    $view = $user['role'] === 'office' ? 'item_office_view' : 'item_view';
    return ['items' => array_map($view, $rows), 'page' => $page, 'per_page' => ITEMS_PER_PAGE, 'total' => $total];
}

function items_get(Request $req, array $user, int $id): array
{
    $item = q(ITEM_OFFICE_SELECT . ' WHERE i.id = ?', [$id])->fetch();
    if (!$item) {
        fail(404, 'not_found', 'No such mat.');
    }
    $events = q('SELECT e.id, e.created_at, e.event_type, e.from_status, e.to_status, e.source, e.note,
                e.handover_id, e.task_id, u.name AS user_name, z.name AS zone_name
            FROM item_events e
            JOIN users u ON u.id = e.user_id
            LEFT JOIN zones z ON z.id = e.zone_id
            WHERE e.item_id = ? ORDER BY e.id', [$id])->fetchAll();

    if ($user['role'] === 'office') {
        return ['item' => item_office_view($item), 'events' => $events];
    }
    // Washing sees what happened and who did it, but nothing that names the customer.
    $hidden = array_flip(['note', 'task_id', 'zone_name']);
    return ['item' => item_view($item), 'events' => array_map(fn (array $e) => array_diff_key($e, $hidden), $events)];
}
```

In `site/app/routes.php` add these entries at the end of the returned array:

```php
    ['GET',  '/items',              [$O, $I, $D, $W], 'items_list'],
    ['POST', '/items/ready',        [$I],             'items_ready'],
    ['POST', '/items/qc',           [$I],             'items_qc'],
    ['GET',  '/items/{id}',         [$O, $W],         'items_get'],
    ['POST', '/items/{id}/correct', [$O],             'items_correct'],
```

- [ ] **Step 4: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/ItemsTest.php`
Expected: `OK (12 tests, ...)`.

- [ ] **Step 5: Run all tests and commit**

Run: `php vendor/bin/phpunit`
Expected: `OK (60 tests, ...)`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.5-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.5"/' composer.json
git add site/app/items.php site/app/routes.php tests/ItemsTest.php README.md composer.json
git commit -m "Add ready, quality check, correction, mat list and mat history at 0.4.5."
```

---

### Task 7: Handovers: open and scan

**Files:**
- Create: `site/app/handovers.php`
- Modify: `site/app/bootstrap.php` (file list), `site/app/routes.php`, `tests/ApiTestCase.php` (two helpers)
- Test: `tests/HandoverScanTest.php`

**Interfaces:**
- Consumes: `source_in()`, `reads_in()`, `items_by_epc()`, `item_view()`, `log_event()`, `scan_result()`, `ITEM_SELECT`, `q()`, `tx()`, `marks()`, `today()`, `enum_in()`, `int_in()`.
- Produces:
  - `const HANDOVERS` — per type: `giver` (list of roles), `receiver` (role), `from`, `to`, `two_sided`, `wash`
  - `handover_view(int $id): array` — `id, type, status, created_at, closed_at, giver {id, name}, receiver {id, name}, items [item_view + given_at, received_at, resolved_at]`; 404 when unknown
  - Routes: `POST /handovers` (inventory, driver, washing, outbound) — `{type, receiver_id}` → `{"handover": {...}}`; `POST /handovers/{id}/scan` (same four) — `{source, reads}` → scan answer
  - Test helpers in `ApiTestCase`: `openHandover(string $giver, string $type, string $receiver): int` and `scanHandover(string $who, int $handover, int ...$mats): array` (both log in as the named user)

The five types. A wash is counted at Handover C, when the washing house manager scans the washed mats out (see "Changes to the spec decided while planning" at the top of this plan):

| Type | Giver | Receiver | Mat must be | Mat becomes | Receiver must scan | Counts a wash |
|---|---|---|---|---|---|---|
| `inventory_to_driver` | inventory or outbound | driver | `qc_passed` | `out_for_delivery`, holder = receiver | yes | no |
| `driver_to_washing` | driver | washing | `collected` | `in_washing` | yes | no |
| `washing_to_inventory` | washing | inventory | `in_washing` | `ready` | no | yes, at the giver's scan |
| `driver_to_inventory` | driver | inventory | `refused` | `ready` | yes | no |
| `inventory_to_washing` | inventory | washing | `rewash` | `in_washing` | yes | no |

- [ ] **Step 1: Add the test helpers**

Add these two methods at the end of the class in `tests/ApiTestCase.php`:

```php
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
```

- [ ] **Step 2: Write the failing test**

Create `tests/HandoverScanTest.php`:

```php
<?php
/** Opening a handover and the two scans: a mat moves only when both sides scanned it, and only once. */
declare(strict_types=1);

final class HandoverScanTest extends ApiTestCase
{
    public function test_inventory_and_outbound_open_a_handover_to_a_driver(): void
    {
        $this->loginAs('inventory');
        $handover = $this->ok($this->api('POST', '/handovers',
            ['type' => 'inventory_to_driver', 'receiver_id' => $this->users['driver']]))['handover'];
        $this->assertSame(['inventory_to_driver', 'open', []], [$handover['type'], $handover['status'], $handover['items']]);
        $this->assertSame([$this->users['inventory'], $this->users['driver']], [$handover['giver']['id'], $handover['receiver']['id']]);

        // Sent twice on a bad connection: the open handover comes back, no second one is made.
        $this->assertSame($handover['id'], $this->openHandover('inventory', 'inventory_to_driver', 'driver'));
        $this->assertNotSame($handover['id'], $this->openHandover('outbound', 'inventory_to_driver', 'driver'));
        $this->assertSame(2, (int) q('SELECT COUNT(*) FROM handovers')->fetchColumn());
    }

    public function test_a_handover_needs_the_right_giver_and_receiver(): void
    {
        $this->loginAs('driver');
        $this->assertError(403, 'forbidden', $this->api('POST', '/handovers',
            ['type' => 'inventory_to_driver', 'receiver_id' => $this->users['driver2']]));

        $this->loginAs('inventory');
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/handovers',
            ['type' => 'inventory_to_driver', 'receiver_id' => $this->users['washing']]));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/handovers',
            ['type' => 'nonsense', 'receiver_id' => $this->users['driver']]));
        q('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver2']]);
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/handovers',
            ['type' => 'inventory_to_driver', 'receiver_id' => $this->users['driver2']]));
    }

    public function test_a_mat_moves_only_when_both_sides_have_scanned_it(): void
    {
        $this->setItems([1, 2], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');

        $give = $this->scanHandover('inventory', $handover, 1, 2);
        $this->assertSame(['found', 'found'], array_column($give['results'], 'result'));
        $this->assertSame([], $give['missing']);
        $this->assertSame('qc_passed', $this->item(1)['status']);   // one scan is not enough

        $receive = $this->scanHandover('driver', $handover, 1);
        $this->assertSame(['found', 'out_for_delivery'], [$receive['results'][0]['result'], $receive['results'][0]['item']['status']]);
        $this->assertSame(['MOCK-0002'], array_column($receive['missing'], 'sku'));

        $this->assertSame(['out_for_delivery', $this->users['driver']], [$this->item(1)['status'], $this->item(1)['holder_user_id']]);
        $this->assertSame(['qc_passed', null], [$this->item(2)['status'], $this->item(2)['holder_user_id']]);   // stays with the giver

        $events = $this->events(1);
        $this->assertSame(['handover_give', 'handover_receive'], array_column($events, 'event_type'));
        $this->assertSame(['qc_passed', 'qc_passed', $handover], [$events[0]['from_status'], $events[0]['to_status'], $events[0]['handover_id']]);
        $this->assertSame(['qc_passed', 'out_for_delivery'], [$events[1]['from_status'], $events[1]['to_status']]);
    }

    public function test_the_giver_cannot_hand_over_a_mat_in_the_wrong_status(): void
    {
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');   // mat 1 is ready, not qc_passed
        $this->loginAs('inventory');
        $out = $this->ok($this->api('POST', "/handovers/$handover/scan",
            ['source' => 'simulator', 'reads' => [['epc' => $this->epc(1)], ['epc' => 'AAAA0000']]]));
        $this->assertSame(['rejected', 'unknown'], array_column($out['results'], 'result'));
        $this->assertSame('MOCK-0001 is ready, not qc_passed.', $out['results'][0]['reason']);
        $this->assertSame(0, (int) q('SELECT COUNT(*) FROM handover_items')->fetchColumn());
    }

    public function test_a_mat_only_the_receiver_scanned_is_not_on_the_list_and_stays_put(): void
    {
        $this->setItems([1], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1);

        $out = $this->scanHandover('driver', $handover, 1, 2);
        $this->assertSame(['found', 'not_on_list'], array_column($out['results'], 'result'));
        $this->assertSame(['ready', null], [$this->item(2)['status'], $this->item(2)['holder_user_id']]);

        $row = q('SELECT given_at, received_at FROM handover_items WHERE handover_id = ? AND item_id = ?',
            [$handover, $this->item(2)['id']])->fetch();
        $this->assertNull($row['given_at']);
        $this->assertNotNull($row['received_at']);
    }

    public function test_the_order_of_the_two_scans_does_not_matter(): void
    {
        $this->setItems([1], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->assertSame('not_on_list', $this->scanHandover('driver', $handover, 1)['results'][0]['result']);
        $this->assertSame('qc_passed', $this->item(1)['status']);

        $give = $this->scanHandover('inventory', $handover, 1);
        $this->assertSame(['found', 'out_for_delivery'], [$give['results'][0]['result'], $give['results'][0]['item']['status']]);
        $this->assertSame($this->users['driver'], $this->item(1)['holder_user_id']);
    }

    public function test_handover_b_puts_the_mat_in_washing_without_counting_a_wash(): void
    {
        $this->setItems([1], 'collected', $this->users['driver']);
        q("UPDATE items SET current_wash_date = '2026-09-01', previous_wash_date = '2026-08-01', wash_count = 3 WHERE sku = 'MOCK-0001'");
        $handover = $this->openHandover('driver', 'driver_to_washing', 'washing');

        $this->scanHandover('driver', $handover, 1);
        $this->scanHandover('washing', $handover, 1);
        $this->scanHandover('washing', $handover, 1);
        $this->scanHandover('driver', $handover, 1);

        $mat = $this->item(1);
        $this->assertSame(['in_washing', null, 3, '2026-09-01', '2026-08-01'],
            [$mat['status'], $mat['holder_user_id'], $mat['wash_count'], $mat['current_wash_date'], $mat['previous_wash_date']]);
        $this->assertCount(2, $this->events(1));
    }

    public function test_the_scan_after_washing_makes_the_mat_ready_and_counts_one_wash(): void
    {
        $this->setItems([1, 2], 'in_washing');
        q("UPDATE items SET current_wash_date = '2026-09-01', previous_wash_date = '2026-08-01', wash_count = 3 WHERE sku = 'MOCK-0001'");
        $handover = $this->openHandover('washing', 'washing_to_inventory', 'inventory');

        $give = $this->scanHandover('washing', $handover, 1, 2);
        $seen = $give['results'][0]['item'];
        $this->assertSame(['ready', 4, today(), '2026-09-01'],
            [$seen['status'], $seen['wash_count'], $seen['current_wash_date'], $seen['previous_wash_date']]);
        $this->scanHandover('washing', $handover, 1, 2);   // the same pile scanned again: no second wash

        $mat = $this->item(1);
        $this->assertSame(['ready', 4, today(), '2026-09-01'],
            [$mat['status'], $mat['wash_count'], $mat['current_wash_date'], $mat['previous_wash_date']]);
        $this->assertSame([1, today(), null],
            [$this->item(2)['wash_count'], $this->item(2)['current_wash_date'], $this->item(2)['previous_wash_date']]);

        // The inventory scan is optional and changes nothing.
        $receive = $this->scanHandover('inventory', $handover, 1);
        $this->assertSame('found', $receive['results'][0]['result']);
        $this->assertSame(['MOCK-0002'], array_column($receive['missing'], 'sku'));
        $this->assertSame(['ready', 4], [$this->item(1)['status'], $this->item(1)['wash_count']]);
        $this->assertSame([['in_washing', 'ready'], ['ready', 'ready']],
            array_map(fn (array $e) => [$e['from_status'], $e['to_status']], $this->events(1)));
    }

    public function test_refused_mats_return_to_inventory_without_a_wash(): void
    {
        $this->setItems([1], 'refused', $this->users['driver']);
        $handover = $this->openHandover('driver', 'driver_to_inventory', 'inventory');
        $this->scanHandover('driver', $handover, 1);
        $this->scanHandover('inventory', $handover, 1);

        $mat = $this->item(1);
        $this->assertSame(['ready', null, 0, null], [$mat['status'], $mat['holder_user_id'], $mat['wash_count'], $mat['current_wash_date']]);
    }

    public function test_a_rewash_goes_to_washing_and_is_counted_when_it_comes_back(): void
    {
        $this->setItems([1], 'rewash');
        $rewash = $this->openHandover('inventory', 'inventory_to_washing', 'washing');
        $this->scanHandover('inventory', $rewash, 1);
        $this->scanHandover('washing', $rewash, 1);
        $this->assertSame(['in_washing', 0, null],
            [$this->item(1)['status'], $this->item(1)['wash_count'], $this->item(1)['current_wash_date']]);

        // Both scans are in, so the mat is free for the next handover even though nobody tapped Done yet.
        $back = $this->openHandover('washing', 'washing_to_inventory', 'inventory');
        $this->scanHandover('washing', $back, 1);
        $this->assertSame(['ready', 1, today()],
            [$this->item(1)['status'], $this->item(1)['wash_count'], $this->item(1)['current_wash_date']]);
    }

    public function test_a_driver_can_only_give_mats_from_his_own_van(): void
    {
        $this->setItems([1], 'collected', $this->users['driver2']);
        $handover = $this->openHandover('driver', 'driver_to_washing', 'washing');
        $out = $this->scanHandover('driver', $handover, 1);
        $this->assertSame(['rejected', 'MOCK-0001 is not on your van.'], [$out['results'][0]['result'], $out['results'][0]['reason']]);
    }

    public function test_a_mat_can_be_in_only_one_open_handover(): void
    {
        $this->setItems([1], 'qc_passed');
        $first = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $first, 1);

        $second = $this->openHandover('inventory', 'inventory_to_driver', 'driver2');
        $out = $this->scanHandover('inventory', $second, 1);
        $this->assertSame(['rejected', 'MOCK-0001 is already in another open handover.'],
            [$out['results'][0]['result'], $out['results'][0]['reason']]);
    }

    public function test_only_the_two_people_of_a_handover_may_scan_into_it(): void
    {
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        foreach (['driver2', 'washing', 'outbound', 'office'] as $who) {
            $this->loginAs($who);
            $this->assertError(403, 'forbidden', $this->api('POST', "/handovers/$handover/scan", $this->reads(1)));
        }
        $this->loginAs('driver');
        $this->assertError(404, 'not_found', $this->api('POST', '/handovers/999/scan', $this->reads(1)));
    }

    public function test_an_office_correction_during_a_handover_is_not_undone_by_the_receiver_scan(): void
    {
        $this->setItems([1], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1);

        $this->loginAs('office');
        $this->ok($this->api('POST', "/items/{$this->item(1)['id']}/correct", ['status' => 'repair', 'note' => 'Torn edge']));

        $out = $this->scanHandover('driver', $handover, 1);
        $this->assertSame(['found', 'repair'], [$out['results'][0]['result'], $out['results'][0]['item']['status']]);
        $this->assertSame(['repair', null], [$this->item(1)['status'], $this->item(1)['holder_user_id']]);
    }
}
```

- [ ] **Step 3: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/HandoverScanTest.php`
Expected: FAIL, the tests get `404 not_found`.

- [ ] **Step 4: Write the implementation**

Create `site/app/handovers.php`:

```php
<?php
/** Handovers: one person passes a pile to another. A mat moves when both have scanned it (Handover C: when the giver has). */
declare(strict_types=1);

// Per type: who may give, which role receives, the status a mat must have, the status it gets,
// whether the receiver's scan is needed, and whether the move counts as a wash.
// A wash is counted once: when the washing house manager scans the washed mat out (Handover C).
const HANDOVERS = [
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

function handover_view(int $id): array
{
    $handover = q('SELECT h.*, g.name AS giver_name, r.name AS receiver_name
        FROM handovers h JOIN users g ON g.id = h.giver_id JOIN users r ON r.id = h.receiver_id
        WHERE h.id = ?', [$id])->fetch();
    if (!$handover) {
        fail(404, 'not_found', 'No such handover.');
    }
    $items = q('SELECT i.*, t.name AS type_name, hi.given_at, hi.received_at, hi.resolved_at
        FROM handover_items hi JOIN items i ON i.id = hi.item_id JOIN item_types t ON t.id = i.item_type_id
        WHERE hi.handover_id = ? ORDER BY i.sku', [$id])->fetchAll();
    return [
        'id' => $handover['id'],
        'type' => $handover['type'],
        'status' => $handover['status'],
        'created_at' => $handover['created_at'],
        'closed_at' => $handover['closed_at'],
        'giver' => ['id' => $handover['giver_id'], 'name' => $handover['giver_name']],
        'receiver' => ['id' => $handover['receiver_id'], 'name' => $handover['receiver_name']],
        'items' => array_map(fn (array $item) => item_view($item)
            + ['given_at' => $item['given_at'], 'received_at' => $item['received_at'], 'resolved_at' => $item['resolved_at']], $items),
    ];
}

function handovers_open(Request $req, array $user): array
{
    $type = enum_in($req->body, 'type', array_keys(HANDOVERS));
    $receiverId = int_in($req->body, 'receiver_id');
    $rule = HANDOVERS[$type];
    if (!in_array($user['role'], $rule['giver'], true)) {
        fail(403, 'forbidden', "Your role cannot give in a $type handover.");
    }
    if (!q('SELECT 1 FROM users WHERE id = ? AND role = ? AND is_active = 1', [$receiverId, $rule['receiver']])->fetch()) {
        fail(422, 'invalid_reference', "The receiver must be an active {$rule['receiver']} user.");
    }
    return tx(function () use ($type, $receiverId, $user): array {
        // Opening the same handover twice (a retry on a bad connection) gives the open one back.
        $open = q("SELECT id FROM handovers WHERE type = ? AND giver_id = ? AND receiver_id = ? AND status = 'open' FOR UPDATE",
            [$type, $user['id'], $receiverId])->fetch();
        if ($open) {
            return ['handover' => handover_view($open['id'])];
        }
        q('INSERT INTO handovers (type, giver_id, receiver_id) VALUES (?, ?, ?)', [$type, $user['id'], $receiverId]);
        return ['handover' => handover_view((int) db()->lastInsertId())];
    });
}

/** One scan into a handover. Who calls decides whether it counts as giving or as receiving. */
function handovers_scan(Request $req, array $user, int $id): array
{
    $source = source_in($req->body);
    $epcs = reads_in($req->body);
    return tx(function () use ($source, $epcs, $user, $id): array {
        $handover = q('SELECT * FROM handovers WHERE id = ? FOR UPDATE', [$id])->fetch();
        if (!$handover) {
            fail(404, 'not_found', 'No such handover.');
        }
        $side = match ($user['id']) {
            $handover['giver_id'] => 'give',
            $handover['receiver_id'] => 'receive',
            default => fail(403, 'forbidden', 'This is not your handover.'),
        };
        if ($handover['status'] !== 'open') {
            fail(409, 'closed', 'This handover is closed.');
        }

        $items = items_by_epc($epcs, true);
        $rows = $items === [] ? [] : array_column(
            q('SELECT * FROM handover_items WHERE handover_id = ? AND item_id IN (' . marks($items) . ')',
                [$id, ...array_column($items, 'id')])->fetchAll(),
            null, 'item_id');

        $results = [];
        foreach ($epcs as $epc) {
            $item = $items[$epc] ?? null;
            if ($item === null) {
                $results[] = scan_result($epc, 'unknown', null);
                continue;
            }
            $row = $rows[$item['id']] ?? null;
            $results[] = $side === 'give'
                ? handover_give($handover, $item, $row, $user, $source)
                : handover_receive($handover, $item, $row, $user, $source);
        }

        // The receiver sees which mats the giver scanned and he has not.
        $missing = $side !== 'receive' ? [] : array_map('item_view', q(ITEM_SELECT
            . ' JOIN handover_items hi ON hi.item_id = i.id
                WHERE hi.handover_id = ? AND hi.given_at IS NOT NULL AND hi.received_at IS NULL ORDER BY i.sku', [$id])->fetchAll());
        return ['results' => $results, 'missing' => $missing];
    });
}

function handover_give(array $handover, array $item, ?array $row, array $user, string $source): array
{
    $rule = HANDOVERS[$handover['type']];
    $epc = $item['epc'];
    if ($row !== null && $row['given_at'] !== null) {
        return scan_result($epc, 'found', $item);   // a second scan: nothing to change
    }
    if ($item['status'] !== $rule['from']) {
        return scan_result($epc, 'rejected', $item, "{$item['sku']} is {$item['status']}, not {$rule['from']}.");
    }
    if ($user['role'] === 'driver' && $item['holder_user_id'] !== $user['id']) {
        return scan_result($epc, 'rejected', $item, "{$item['sku']} is not on your van.");
    }
    // "In another handover" means: given there and still waiting for that receiver's scan.
    $elsewhere = q("SELECT 1 FROM handover_items hi JOIN handovers h ON h.id = hi.handover_id
        WHERE hi.item_id = ? AND hi.given_at IS NOT NULL AND hi.received_at IS NULL
          AND h.status = 'open' AND h.type <> 'washing_to_inventory' AND h.id <> ?",
        [$item['id'], $handover['id']])->fetch();
    if ($elsewhere) {
        return scan_result($epc, 'rejected', $item, "{$item['sku']} is already in another open handover.");
    }

    q('INSERT INTO handover_items (handover_id, item_id, given_at) VALUES (?, ?, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE given_at = UTC_TIMESTAMP()', [$handover['id'], $item['id']]);
    $receivedAlready = $row !== null && $row['received_at'] !== null;
    $after = !$rule['two_sided'] || $receivedAlready ? handover_move($handover, $item) + $item : $item;
    log_event($item, $user['id'], 'handover_give', $after['status'], $source, ['handover_id' => $handover['id']]);
    return scan_result($epc, 'found', $after);
}

function handover_receive(array $handover, array $item, ?array $row, array $user, string $source): array
{
    $epc = $item['epc'];
    $given = $row !== null && $row['given_at'] !== null;
    $result = $given ? 'found' : 'not_on_list';
    if ($row !== null && $row['received_at'] !== null) {
        return scan_result($epc, $result, $item);   // a second scan: nothing to change
    }

    // A mat the giver did not scan is still recorded: it shows up as a handover issue for the office.
    q('INSERT INTO handover_items (handover_id, item_id, received_at) VALUES (?, ?, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE received_at = UTC_TIMESTAMP()', [$handover['id'], $item['id']]);
    $after = $given && HANDOVERS[$handover['type']]['two_sided'] ? handover_move($handover, $item) + $item : $item;
    log_event($item, $user['id'], 'handover_receive', $after['status'], $source, ['handover_id' => $handover['id']]);
    return scan_result($epc, $result, $after);
}

/**
 * Moves one mat to the status its handover type gives it, only if it still has the status the type expects.
 * Returns the fields that changed, or nothing when the mat did not move.
 * This "set new where still old" is what makes a second scan harmless.
 */
function handover_move(array $handover, array $item): array
{
    $rule = HANDOVERS[$handover['type']];
    $holder = $rule['to'] === 'out_for_delivery' ? $handover['receiver_id'] : null;
    $today = today();
    // Keep this order: MySQL and MariaDB assign left to right, so previous_wash_date gets the old current date.
    $wash = $rule['wash'] ? ', previous_wash_date = current_wash_date, current_wash_date = ?, wash_count = wash_count + 1' : '';
    $params = [$rule['to'], $holder, ...($rule['wash'] ? [$today] : []), $item['id'], $rule['from']];
    $moved = q("UPDATE items SET status = ?, holder_user_id = ?$wash WHERE id = ? AND status = ?", $params)->rowCount();
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
```

In `site/app/bootstrap.php` replace the file list:

```php
foreach (['db', 'http', 'kernel', 'auth', 'refdata', 'users', 'scan', 'items', 'handovers'] as $file) {
    require __DIR__ . "/$file.php";
}
```

In `site/app/routes.php` add these entries at the end of the returned array:

```php
    ['POST', '/handovers',           $scanners, 'handovers_open'],
    ['POST', '/handovers/{id}/scan', $scanners, 'handovers_scan'],
```

- [ ] **Step 5: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/HandoverScanTest.php`
Expected: `OK (14 tests, ...)`.

`test_only_the_two_people_of_a_handover_may_scan_into_it` expects `403 forbidden` for the office too: the route's role check refuses the office before the handler runs.

- [ ] **Step 6: Run all tests and commit**

Run: `php vendor/bin/phpunit`
Expected: `OK (74 tests, ...)`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.6-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.6"/' composer.json
git add site/app/handovers.php site/app/bootstrap.php site/app/routes.php tests/ApiTestCase.php tests/HandoverScanTest.php README.md composer.json
git commit -m "Add handover opening and two-sided scanning at 0.4.6."
```

---

### Task 8: Handovers: close, lists, issues and receivers

**Files:**
- Modify: `site/app/handovers.php` (add functions at the end), `site/app/routes.php`
- Test: `tests/HandoverCloseTest.php`

**Interfaces:**
- Consumes: `HANDOVERS`, `handover_view()`, `q()`, `tx()`, `str_in()`, `enum_in()`, and the test helpers `openHandover()` and `scanHandover()` from Task 7.
- Produces routes:
  - `POST /handovers/{id}/close` (all roles) — the receiver closes; for `washing_to_inventory` the giver or the receiver; the office may close any → `{"handover": {...}}`
  - `GET /handovers` (all roles) — office: all; others: the ones they give or receive → `{"handovers": [{id, type, status, created_at, closed_at, giver_id, giver_name, receiver_id, receiver_name, given, received}]}`
  - `GET /handovers?issues=1` (office) → `{"issues": [{handover_id, type, closed_at, giver_name, receiver_name, given_at, received_at, item_id, sku, type_name, status}]}`
  - `GET /handovers/{id}` (all roles; non-office only for their own) → `{"handover": {...}}`
  - `POST /handovers/{id}/items/{itemId}/resolve` (office) — `{note}` → `{"ok": true}`
  - `GET /handover-receivers?type=...` (inventory, driver, washing, outbound) → `{"rows": [{id, name}]}`

A handover issue is a mat in a **closed** handover with only one of the two scans, not yet resolved. In `washing_to_inventory` a mat without the receiver's scan is normal and is not an issue.

- [ ] **Step 1: Write the failing test**

Create `tests/HandoverCloseTest.php`:

```php
<?php
/** Closing a handover, the handover lists per role, the office's issue list, and the list of possible receivers. */
declare(strict_types=1);

final class HandoverCloseTest extends ApiTestCase
{
    /** A closed Handover A where mat 2 was only given and mat 3 was only received. Returns the handover id. */
    private function handoverWithIssues(): int
    {
        $this->setItems([1, 2], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1, 2);
        $this->scanHandover('driver', $handover, 1, 3);
        $this->ok($this->api('POST', "/handovers/$handover/close"));
        return $handover;
    }

    private function issues(): array
    {
        $this->loginAs('office');
        return $this->ok($this->api('GET', '/handovers?issues=1'))['issues'];
    }

    public function test_the_receiver_closes_and_a_closed_handover_takes_no_more_scans(): void
    {
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->assertError(403, 'forbidden', $this->api('POST', "/handovers/$handover/close"));   // the giver may not

        $this->loginAs('driver');
        $closed = $this->ok($this->api('POST', "/handovers/$handover/close"))['handover'];
        $this->assertSame('closed', $closed['status']);
        $this->assertNotNull($closed['closed_at']);

        $this->assertError(409, 'closed', $this->api('POST', "/handovers/$handover/close"));
        $this->assertError(409, 'closed', $this->api('POST', "/handovers/$handover/scan", $this->reads(1)));
    }

    public function test_handover_c_is_closed_by_the_giver(): void
    {
        $handover = $this->openHandover('washing', 'washing_to_inventory', 'inventory');
        $this->assertSame('closed', $this->ok($this->api('POST', "/handovers/$handover/close"))['handover']['status']);
    }

    public function test_the_office_can_close_any_handover_and_strangers_cannot(): void
    {
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->loginAs('driver2');
        $this->assertError(403, 'forbidden', $this->api('POST', "/handovers/$handover/close"));
        $this->loginAs('office');
        $this->assertSame('closed', $this->ok($this->api('POST', "/handovers/$handover/close"))['handover']['status']);
        $this->assertError(404, 'not_found', $this->api('POST', '/handovers/999/close'));
    }

    public function test_a_closed_handover_frees_its_mats_for_a_new_one(): void
    {
        $this->handoverWithIssues();   // mat 2 was given but never received, so it is still qc_passed
        $second = $this->openHandover('inventory', 'inventory_to_driver', 'driver2');
        $this->assertSame('found', $this->scanHandover('inventory', $second, 2)['results'][0]['result']);
    }

    public function test_one_sided_mats_become_issues_when_the_handover_closes(): void
    {
        $this->setItems([1, 2], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1, 2);
        $this->scanHandover('driver', $handover, 1, 3);
        $this->assertSame([], $this->issues());   // still open: not an issue yet

        $this->loginAs('driver');
        $this->ok($this->api('POST', "/handovers/$handover/close"));
        $issues = $this->issues();
        $this->assertSame(['MOCK-0002', 'MOCK-0003'], array_column($issues, 'sku'));
        $this->assertSame([$handover, 'inventory_to_driver', 'Inventory', 'Driver', 'qc_passed'],
            [$issues[0]['handover_id'], $issues[0]['type'], $issues[0]['giver_name'], $issues[0]['receiver_name'], $issues[0]['status']]);
        $this->assertNull($issues[0]['received_at']);   // given, never received
        $this->assertNull($issues[1]['given_at']);      // received, never given
    }

    public function test_handover_c_without_the_inventory_scan_is_not_an_issue(): void
    {
        $this->setItems([4], 'in_washing');
        $handover = $this->openHandover('washing', 'washing_to_inventory', 'inventory');
        $this->scanHandover('washing', $handover, 4);
        $this->ok($this->api('POST', "/handovers/$handover/close"));
        $this->assertSame([], $this->issues());
    }

    public function test_the_office_resolves_an_issue_with_a_note(): void
    {
        $handover = $this->handoverWithIssues();
        $given = $this->item(2)['id'];
        $moved = $this->item(1)['id'];

        $this->assertError(403, 'forbidden', $this->api('POST', "/handovers/$handover/items/$given/resolve", ['note' => 'x']));
        $this->loginAs('office');
        $this->assertError(422, 'invalid_input', $this->api('POST', "/handovers/$handover/items/$given/resolve", []));
        $this->assertError(409, 'not_an_issue', $this->api('POST', "/handovers/$handover/items/$moved/resolve", ['note' => 'x']));

        $this->ok($this->api('POST', "/handovers/$handover/items/$given/resolve", ['note' => 'Was left on the shelf']));
        $this->assertSame(['MOCK-0003'], array_column($this->issues(), 'sku'));
        $row = q('SELECT resolved_by, resolution_note, resolved_at FROM handover_items WHERE handover_id = ? AND item_id = ?',
            [$handover, $given])->fetch();
        $this->assertSame([$this->users['office'], 'Was left on the shelf'], [$row['resolved_by'], $row['resolution_note']]);
        $this->assertNotNull($row['resolved_at']);

        $this->assertError(409, 'not_an_issue', $this->api('POST', "/handovers/$handover/items/$given/resolve", ['note' => 'again']));
    }

    public function test_everyone_sees_his_own_handovers_and_the_office_sees_all(): void
    {
        $this->setItems([1], 'qc_passed');
        $first = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $first, 1);
        $second = $this->openHandover('outbound', 'inventory_to_driver', 'driver2');

        $this->loginAs('driver');
        $mine = $this->ok($this->api('GET', '/handovers'))['handovers'];
        $this->assertSame([$first], array_column($mine, 'id'));
        $this->assertSame(['Inventory', 'Driver', 1, 0], [$mine[0]['giver_name'], $mine[0]['receiver_name'], $mine[0]['given'], $mine[0]['received']]);

        $this->loginAs('washing');
        $this->assertSame([], $this->ok($this->api('GET', '/handovers'))['handovers']);
        $this->loginAs('office');
        $this->assertSame([$second, $first], array_column($this->ok($this->api('GET', '/handovers'))['handovers'], 'id'));
        $this->loginAs('driver');
        $this->assertArrayNotHasKey('issues', $this->ok($this->api('GET', '/handovers?issues=1')));   // only the office gets issues
    }

    public function test_a_handover_shows_its_pile_to_its_two_people_and_the_office(): void
    {
        $this->setItems([1], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1);

        foreach (['inventory', 'driver', 'office'] as $who) {
            $this->loginAs($who);
            $view = $this->ok($this->api('GET', "/handovers/$handover"))['handover'];
            $this->assertSame('MOCK-0001', $view['items'][0]['sku']);
            $this->assertNotNull($view['items'][0]['given_at']);
            $this->assertNull($view['items'][0]['received_at']);
        }
        $this->loginAs('driver2');
        $this->assertError(403, 'forbidden', $this->api('GET', "/handovers/$handover"));
        $this->assertError(404, 'not_found', $this->api('GET', '/handovers/999'));
    }

    public function test_a_giver_gets_the_list_of_people_he_can_hand_over_to(): void
    {
        $this->loginAs('inventory');
        $this->assertSame(['Driver', 'Driver2'],
            array_column($this->ok($this->api('GET', '/handover-receivers?type=inventory_to_driver'))['rows'], 'name'));
        $this->assertError(403, 'forbidden', $this->api('GET', '/handover-receivers?type=driver_to_washing'));
        $this->assertError(422, 'invalid_input', $this->api('GET', '/handover-receivers'));

        q('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver2']]);
        $rows = $this->ok($this->api('GET', '/handover-receivers?type=inventory_to_driver'))['rows'];
        $this->assertSame([['id' => $this->users['driver'], 'name' => 'Driver']], $rows);
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/HandoverCloseTest.php`
Expected: FAIL: `close` and `handover-receivers` get `404 not_found`, `GET /handovers` gets `405 method_not_allowed`.

- [ ] **Step 3: Write the implementation**

Add at the end of `site/app/handovers.php`:

```php
// A mat in a closed handover with only one of its two scans, not handled yet by the office.
// In Handover C the receiver's scan is optional, so "given only" is normal there.
const HANDOVER_ISSUE = "h.status = 'closed' AND hi.resolved_at IS NULL
    AND (hi.given_at IS NULL OR (hi.received_at IS NULL AND h.type <> 'washing_to_inventory'))";

function handovers_close(Request $req, array $user, int $id): array
{
    return tx(function () use ($user, $id): array {
        $handover = q('SELECT * FROM handovers WHERE id = ? FOR UPDATE', [$id])->fetch();
        if (!$handover) {
            fail(404, 'not_found', 'No such handover.');
        }
        // The receiver says "done". In Handover C the receiver may never show up, so the giver may close too.
        $mayClose = HANDOVERS[$handover['type']]['two_sided']
            ? [$handover['receiver_id']]
            : [$handover['giver_id'], $handover['receiver_id']];
        if ($user['role'] !== 'office' && !in_array($user['id'], $mayClose, true)) {
            fail(403, 'forbidden', 'Only the receiver closes this handover.');
        }
        if ($handover['status'] !== 'open') {
            fail(409, 'closed', 'This handover is already closed.');
        }
        q("UPDATE handovers SET status = 'closed', closed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'open'", [$id]);
        return ['handover' => handover_view($id)];
    });
}

function handovers_get(Request $req, array $user, int $id): array
{
    $handover = handover_view($id);
    $mine = in_array($user['id'], [$handover['giver']['id'], $handover['receiver']['id']], true);
    if ($user['role'] !== 'office' && !$mine) {
        fail(403, 'forbidden', 'This is not your handover.');
    }
    return ['handover' => $handover];
}

function handovers_list(Request $req, array $user): array
{
    $office = $user['role'] === 'office';
    if ($office && ($req->query['issues'] ?? '') === '1') {
        return ['issues' => q('SELECT h.id AS handover_id, h.type, h.closed_at, g.name AS giver_name, r.name AS receiver_name,
                    hi.given_at, hi.received_at, i.id AS item_id, i.sku, t.name AS type_name, i.status
                FROM handover_items hi
                JOIN handovers h ON h.id = hi.handover_id
                JOIN items i ON i.id = hi.item_id
                JOIN item_types t ON t.id = i.item_type_id
                JOIN users g ON g.id = h.giver_id
                JOIN users r ON r.id = h.receiver_id
                WHERE ' . HANDOVER_ISSUE . ' ORDER BY h.closed_at DESC, i.sku')->fetchAll()];
    }
    $where = $office ? '1 = 1' : '(h.giver_id = ? OR h.receiver_id = ?)';
    $params = $office ? [] : [$user['id'], $user['id']];
    // ponytail: the newest 100, no paging. Add paging when the office needs to look further back.
    return ['handovers' => q("SELECT h.id, h.type, h.status, h.created_at, h.closed_at,
                h.giver_id, g.name AS giver_name, h.receiver_id, r.name AS receiver_name,
                (SELECT COUNT(*) FROM handover_items hi WHERE hi.handover_id = h.id AND hi.given_at IS NOT NULL) AS given,
                (SELECT COUNT(*) FROM handover_items hi WHERE hi.handover_id = h.id AND hi.received_at IS NOT NULL) AS received
            FROM handovers h JOIN users g ON g.id = h.giver_id JOIN users r ON r.id = h.receiver_id
            WHERE $where ORDER BY h.status = 'open' DESC, h.id DESC LIMIT 100", $params)->fetchAll()];
}

function handovers_resolve(Request $req, array $user, int $id, int $itemId): array
{
    $note = str_in($req->body, 'note', 500);
    $resolved = q('UPDATE handover_items hi JOIN handovers h ON h.id = hi.handover_id
            SET hi.resolved_at = UTC_TIMESTAMP(), hi.resolved_by = ?, hi.resolution_note = ?
            WHERE hi.handover_id = ? AND hi.item_id = ? AND ' . HANDOVER_ISSUE,
        [$user['id'], $note, $id, $itemId])->rowCount();
    if ($resolved !== 1) {
        fail(409, 'not_an_issue', 'This mat is not an open handover issue.');
    }
    return ['ok' => true];
}

/** The people a giver can pick as receiver for one handover type. */
function handover_receivers(Request $req, array $user): array
{
    $rule = HANDOVERS[enum_in($req->query, 'type', array_keys(HANDOVERS))];
    if (!in_array($user['role'], $rule['giver'], true)) {
        fail(403, 'forbidden', 'Your role cannot give in this handover.');
    }
    return ['rows' => q('SELECT id, name FROM users WHERE role = ? AND is_active = 1 ORDER BY name', [$rule['receiver']])->fetchAll()];
}
```

In `site/app/routes.php` add these entries at the end of the returned array:

```php
    ['GET',  '/handovers',                               $all,      'handovers_list'],
    ['GET',  '/handovers/{id}',                          $all,      'handovers_get'],
    ['POST', '/handovers/{id}/close',                    $all,      'handovers_close'],
    ['POST', '/handovers/{id}/items/{itemId}/resolve',   [$O],      'handovers_resolve'],
    ['GET',  '/handover-receivers',                      $scanners, 'handover_receivers'],
```

- [ ] **Step 4: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/HandoverCloseTest.php`
Expected: `OK (10 tests, ...)`.

- [ ] **Step 5: Run all tests and commit**

Run: `php vendor/bin/phpunit`
Expected: `OK (84 tests, ...)`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.7-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.7"/' composer.json
git add site/app/handovers.php site/app/routes.php tests/HandoverCloseTest.php README.md composer.json
git commit -m "Add handover closing, lists, issues and receivers at 0.4.7."
```

---

### Task 9: Contracts

**Files:**
- Create: `site/app/contracts.php`
- Modify: `site/app/bootstrap.php` (file list), `site/app/routes.php`
- Test: `tests/ContractsTest.php`

**Interfaces:**
- Consumes: `q()`, `tx()`, `today()`, `fail()`, `int_in()`, `date_in()`.
- Produces:
  - `contract_view(int $id): array` — `id, customer_id, customer_name, swap_interval_days, status, start_date, end_date, lines [{id, zone_id, zone_name, building_id, building_name, item_type_id, type_name, quantity}]`
  - Routes, office only:
    - `GET /contracts` → `{"rows": [...]}`
    - `POST /contracts` — `{customer_id, swap_interval_days, start_date, lines?: [{zone_id, item_type_id, quantity}]}` → `{"contract": {...}}`
    - `PATCH /contracts/{id}` — any of `swap_interval_days`, `start_date`, `lines` → `{"contract": {...}}`
    - `POST /contracts/{id}/activate` → `{"contract": {...}, "task_ids": [...]}`
    - `POST /contracts/{id}/end` → `{"contract": {...}}`

Rules: a contract starts as `draft`. Lines and start date change only while it is a draft. Activating creates one `delivery` task per building that has lines, due on the start date. n (`swap_interval_days`) may change while the contract is active; the open swap tasks of that contract then move by the difference. Ending cancels the open swap tasks.

- [ ] **Step 1: Write the failing test**

Create `tests/ContractsTest.php`:

```php
<?php
/** Contracts: lines per zone, activation into delivery tasks, changing n, and ending. */
declare(strict_types=1);

final class ContractsTest extends ApiTestCase
{
    private function newContract(array $over = []): array
    {
        return $over + ['customer_id' => 1, 'swap_interval_days' => 7, 'start_date' => '2026-11-01',
            'lines' => [['zone_id' => 1, 'item_type_id' => 1, 'quantity' => 2], ['zone_id' => 2, 'item_type_id' => 3, 'quantity' => 1]]];
    }

    private function tasksOf(int $contractId): array
    {
        return q('SELECT * FROM tasks WHERE contract_id = ? ORDER BY id', [$contractId])->fetchAll();
    }

    public function test_office_makes_a_draft_contract_with_lines(): void
    {
        $this->loginAs('office');
        $contract = $this->ok($this->api('POST', '/contracts', $this->newContract()))['contract'];
        $this->assertSame(['draft', 7, '2026-11-01', null, 'Mock Bakery BV'],
            [$contract['status'], $contract['swap_interval_days'], $contract['start_date'], $contract['end_date'], $contract['customer_name']]);
        $this->assertSame([['Front door', 'Mock mat 85x150 grey', 2], ['Staff entrance', 'Mock logo mat 85x150', 1]],
            array_map(fn (array $l) => [$l['zone_name'], $l['type_name'], $l['quantity']], $contract['lines']));
        $this->assertSame('Mock Bakery shop', $contract['lines'][0]['building_name']);
        $this->assertCount(3, $this->ok($this->api('GET', '/contracts'))['rows']);
    }

    public function test_a_contract_with_broken_input_is_refused(): void
    {
        $this->loginAs('office');
        $line = fn (array $over) => ['lines' => [$over + ['zone_id' => 1, 'item_type_id' => 1, 'quantity' => 1]]];
        // Zone 3 belongs to customer 2.
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/contracts', $this->newContract($line(['zone_id' => 3]))));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/contracts', $this->newContract($line(['item_type_id' => 999]))));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/contracts', $this->newContract(['customer_id' => 999])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $this->newContract($line(['quantity' => 0]))));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $this->newContract(['swap_interval_days' => 0])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $this->newContract(['swap_interval_days' => 366])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $this->newContract(['start_date' => '19-10-2026'])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $this->newContract(['lines' => 'none'])));
        $same = ['zone_id' => 1, 'item_type_id' => 1, 'quantity' => 1];
        $this->assertError(409, 'duplicate', $this->api('POST', '/contracts', $this->newContract(['lines' => [$same, $same]])));
        $this->assertSame(2, (int) q('SELECT COUNT(*) FROM contracts')->fetchColumn());
    }

    public function test_activating_creates_one_delivery_task_per_building(): void
    {
        $this->loginAs('office');
        $out = $this->ok($this->api('POST', '/contracts/2/activate'));   // contract 2 has lines in buildings 2 and 3
        $this->assertSame('active', $out['contract']['status']);

        $tasks = $this->tasksOf(2);
        $this->assertSame(array_column($tasks, 'id'), $out['task_ids']);
        $this->assertSame([2, 3], array_column($tasks, 'building_id'));
        foreach ($tasks as $task) {
            $this->assertSame(['delivery', 'open', $out['contract']['start_date'], null, null],
                [$task['type'], $task['status'], $task['due_date'], $task['assigned_to'], $task['previous_task_id']]);
        }

        $this->assertError(409, 'not_draft', $this->api('POST', '/contracts/2/activate'));
        $this->assertCount(2, $this->tasksOf(2));
        $this->assertError(404, 'not_found', $this->api('POST', '/contracts/999/activate'));
    }

    public function test_a_contract_without_lines_cannot_start(): void
    {
        $this->loginAs('office');
        $id = $this->ok($this->api('POST', '/contracts', $this->newContract(['lines' => []])))['contract']['id'];
        $this->assertError(422, 'no_lines', $this->api('POST', "/contracts/$id/activate"));
        $this->assertSame('draft', q('SELECT status FROM contracts WHERE id = ?', [$id])->fetchColumn());
    }

    public function test_lines_and_start_date_change_only_while_draft(): void
    {
        $this->loginAs('office');
        $contract = $this->ok($this->api('PATCH', '/contracts/1', ['start_date' => '2026-12-01', 'swap_interval_days' => 21,
            'lines' => [['zone_id' => 2, 'item_type_id' => 1, 'quantity' => 4]]]))['contract'];
        $this->assertSame(['2026-12-01', 21], [$contract['start_date'], $contract['swap_interval_days']]);
        $this->assertSame([['Staff entrance', 4]], array_map(fn (array $l) => [$l['zone_name'], $l['quantity']], $contract['lines']));

        $this->ok($this->api('POST', '/contracts/1/activate'));
        $this->assertError(409, 'not_draft', $this->api('PATCH', '/contracts/1', ['lines' => []]));
        $this->assertError(409, 'not_draft', $this->api('PATCH', '/contracts/1', ['start_date' => '2027-01-01']));
        $this->assertError(404, 'not_found', $this->api('PATCH', '/contracts/999', ['swap_interval_days' => 5]));
    }

    public function test_changing_n_on_an_active_contract_moves_its_open_swap_tasks(): void
    {
        $this->loginAs('office');
        $this->ok($this->api('POST', '/contracts/1/activate'));
        q("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('swap', 1, 1, '2026-11-02')");
        q("INSERT INTO tasks (type, contract_id, building_id, due_date, status, completed_at, photo_path, signature_path)
           VALUES ('swap', 1, 1, '2026-10-20', 'done', UTC_TIMESTAMP(), 'p.jpg', 's.png')");
        $before = $this->tasksOf(1);

        $contract = $this->ok($this->api('PATCH', '/contracts/1', ['swap_interval_days' => 21]))['contract'];   // 14 -> 21
        $this->assertSame(21, $contract['swap_interval_days']);
        $after = $this->tasksOf(1);
        $this->assertSame($before[0]['due_date'], $after[0]['due_date']);   // the delivery task does not move
        $this->assertSame('2026-11-09', $after[1]['due_date']);             // the open swap moves 7 days
        $this->assertSame('2026-10-20', $after[2]['due_date']);             // a closed swap does not move
    }

    public function test_ending_a_contract_cancels_its_open_swap_tasks(): void
    {
        $this->loginAs('office');
        $this->assertError(409, 'not_active', $this->api('POST', '/contracts/1/end'));   // still a draft
        $this->ok($this->api('POST', '/contracts/1/activate'));
        q("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('swap', 1, 1, '2026-11-02')");

        $contract = $this->ok($this->api('POST', '/contracts/1/end'))['contract'];
        $this->assertSame(['ended', max(today(), $contract['start_date'])], [$contract['status'], $contract['end_date']]);
        $this->assertSame(['open', 'cancelled'], array_column($this->tasksOf(1), 'status'));   // delivery stays, swap is cancelled

        $this->assertError(409, 'not_active', $this->api('POST', '/contracts/1/end'));
        $this->assertError(409, 'ended', $this->api('PATCH', '/contracts/1', ['swap_interval_days' => 10]));
    }

    public function test_a_contract_can_be_ended_before_its_start_date(): void
    {
        q("UPDATE contracts SET start_date = '2099-01-01' WHERE id = 1");
        $this->loginAs('office');
        $this->ok($this->api('POST', '/contracts/1/activate'));
        $contract = $this->ok($this->api('POST', '/contracts/1/end'))['contract'];
        $this->assertSame(['ended', '2099-01-01'], [$contract['status'], $contract['end_date']]);
    }

    public function test_only_the_office_sees_contracts(): void
    {
        foreach (['inventory', 'driver', 'washing', 'outbound'] as $who) {
            $this->loginAs($who);
            $this->assertError(403, 'forbidden', $this->api('GET', '/contracts'));
        }
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/ContractsTest.php`
Expected: FAIL, the tests get `404 not_found`.

- [ ] **Step 3: Write the implementation**

Create `site/app/contracts.php`:

```php
<?php
/** Contracts: how many mats of which type lie in which zone, and n, the days between swaps. Activation creates the delivery tasks. */
declare(strict_types=1);

function contract_view(int $id): array
{
    $contract = q('SELECT c.id, c.customer_id, cu.name AS customer_name, c.swap_interval_days, c.status, c.start_date, c.end_date
        FROM contracts c JOIN customers cu ON cu.id = c.customer_id WHERE c.id = ?', [$id])->fetch();
    if (!$contract) {
        fail(404, 'not_found', 'No such contract.');
    }
    $contract['lines'] = q('SELECT l.id, l.zone_id, z.name AS zone_name, z.building_id, b.name AS building_name,
            l.item_type_id, t.name AS type_name, l.quantity
        FROM contract_lines l
        JOIN zones z ON z.id = l.zone_id
        JOIN buildings b ON b.id = z.building_id
        JOIN item_types t ON t.id = l.item_type_id
        WHERE l.contract_id = ? ORDER BY b.name, z.name, t.name', [$id])->fetchAll();
    return $contract;
}

/** Locks one contract row for the rest of the transaction. */
function contract_locked(int $id): array
{
    $contract = q('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [$id])->fetch();
    if (!$contract) {
        fail(404, 'not_found', 'No such contract.');
    }
    return $contract;
}

/** n: the days between two swaps. */
function interval_in(array $in): int
{
    $days = int_in($in, 'swap_interval_days');
    if ($days < 1 || $days > 365) {
        fail(422, 'invalid_input', 'swap_interval_days must be between 1 and 365.');
    }
    return $days;
}

/** The lines of a contract as [zone id, type id, quantity]. Every zone must belong to the contract's customer. */
function contract_lines_in(array $in, int $customerId): array
{
    $lines = $in['lines'] ?? null;
    if (!is_array($lines) || !array_is_list($lines)) {
        fail(422, 'invalid_input', 'lines must be a list.');
    }
    $out = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            fail(422, 'invalid_input', 'Every line needs zone_id, item_type_id and quantity.');
        }
        $zoneId = int_in($line, 'zone_id');
        $typeId = int_in($line, 'item_type_id');
        $quantity = int_in($line, 'quantity');
        if ($quantity < 1 || $quantity > 1000) {
            fail(422, 'invalid_input', 'quantity must be between 1 and 1000.');
        }
        $zoneOfCustomer = q('SELECT 1 FROM zones z JOIN buildings b ON b.id = z.building_id WHERE z.id = ? AND b.customer_id = ?',
            [$zoneId, $customerId])->fetch();
        if (!$zoneOfCustomer) {
            fail(422, 'invalid_reference', "Zone $zoneId does not belong to this customer.");
        }
        $out[] = [$zoneId, $typeId, $quantity];
    }
    return $out;
}

/** Replaces the lines. Only ever called for a draft, when no task and no history depends on them yet. */
function contract_save_lines(int $contractId, array $lines): void
{
    q('DELETE FROM contract_lines WHERE contract_id = ?', [$contractId]);
    foreach ($lines as [$zoneId, $typeId, $quantity]) {
        q('INSERT INTO contract_lines (contract_id, zone_id, item_type_id, quantity) VALUES (?, ?, ?, ?)',
            [$contractId, $zoneId, $typeId, $quantity]);
    }
}

function contracts_list(Request $req, array $user): array
{
    // ponytail: one query per contract. Fine for tens of contracts; join and group when there are hundreds.
    $ids = q('SELECT id FROM contracts ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN);
    return ['rows' => array_map('contract_view', $ids)];
}

function contracts_create(Request $req, array $user): array
{
    $in = $req->body;
    $customerId = int_in($in, 'customer_id');
    $days = interval_in($in);
    $start = date_in($in, 'start_date');
    if (!q('SELECT 1 FROM customers WHERE id = ?', [$customerId])->fetch()) {
        fail(422, 'invalid_reference', 'Unknown customer.');
    }
    $lines = contract_lines_in($in + ['lines' => []], $customerId);
    return tx(function () use ($customerId, $days, $start, $lines): array {
        q('INSERT INTO contracts (customer_id, swap_interval_days, start_date) VALUES (?, ?, ?)', [$customerId, $days, $start]);
        $id = (int) db()->lastInsertId();
        contract_save_lines($id, $lines);
        return ['contract' => contract_view($id)];
    });
}

function contracts_update(Request $req, array $user, int $id): array
{
    $in = $req->body;
    return tx(function () use ($in, $id): array {
        $contract = contract_locked($id);
        if ($contract['status'] === 'ended') {
            fail(409, 'ended', 'An ended contract cannot be changed.');
        }
        $draftOnly = array_key_exists('lines', $in) || array_key_exists('start_date', $in);
        if ($draftOnly && $contract['status'] !== 'draft') {
            fail(409, 'not_draft', 'Lines and start date can only be changed while the contract is a draft.');
        }
        if (array_key_exists('start_date', $in)) {
            q('UPDATE contracts SET start_date = ? WHERE id = ?', [date_in($in, 'start_date'), $id]);
        }
        if (array_key_exists('lines', $in)) {
            contract_save_lines($id, contract_lines_in($in, $contract['customer_id']));
        }
        if (array_key_exists('swap_interval_days', $in)) {
            $days = interval_in($in);
            q('UPDATE contracts SET swap_interval_days = ? WHERE id = ?', [$days, $id]);
            // An open swap task is due on D + old n. Moving it by the difference makes it D + new n.
            q("UPDATE tasks SET due_date = due_date + INTERVAL ? DAY WHERE contract_id = ? AND type = 'swap' AND status = 'open'",
                [$days - $contract['swap_interval_days'], $id]);
        }
        return ['contract' => contract_view($id)];
    });
}

function contracts_activate(Request $req, array $user, int $id): array
{
    return tx(function () use ($id): array {
        $contract = contract_locked($id);
        if ($contract['status'] !== 'draft') {
            fail(409, 'not_draft', "This contract is already {$contract['status']}.");
        }
        $buildingIds = q('SELECT DISTINCT z.building_id FROM contract_lines l JOIN zones z ON z.id = l.zone_id
            WHERE l.contract_id = ? ORDER BY z.building_id', [$id])->fetchAll(PDO::FETCH_COLUMN);
        if ($buildingIds === []) {
            fail(422, 'no_lines', 'A contract needs at least one line before it can start.');
        }
        q("UPDATE contracts SET status = 'active' WHERE id = ? AND status = 'draft'", [$id]);
        $taskIds = [];
        foreach ($buildingIds as $buildingId) {
            q("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('delivery', ?, ?, ?)",
                [$id, $buildingId, $contract['start_date']]);
            $taskIds[] = (int) db()->lastInsertId();
        }
        return ['contract' => contract_view($id), 'task_ids' => $taskIds];
    });
}

function contracts_end(Request $req, array $user, int $id): array
{
    return tx(function () use ($id): array {
        $contract = contract_locked($id);
        if ($contract['status'] !== 'active') {
            fail(409, 'not_active', "Only an active contract can be ended. This one is {$contract['status']}.");
        }
        // The database refuses an end date before the start date, so a contract ended early ends on its start date.
        q("UPDATE contracts SET status = 'ended', end_date = ? WHERE id = ? AND status = 'active'",
            [max(today(), $contract['start_date']), $id]);
        q("UPDATE tasks SET status = 'cancelled' WHERE contract_id = ? AND type = 'swap' AND status = 'open'", [$id]);
        return ['contract' => contract_view($id)];
    });
}
```

In `site/app/bootstrap.php` replace the file list:

```php
foreach (['db', 'http', 'kernel', 'auth', 'refdata', 'users', 'scan', 'items', 'handovers', 'contracts'] as $file) {
    require __DIR__ . "/$file.php";
}
```

In `site/app/routes.php` add these entries at the end of the returned array:

```php
    ['GET',   '/contracts',               [$O], 'contracts_list'],
    ['POST',  '/contracts',               [$O], 'contracts_create'],
    ['PATCH', '/contracts/{id}',          [$O], 'contracts_update'],
    ['POST',  '/contracts/{id}/activate', [$O], 'contracts_activate'],
    ['POST',  '/contracts/{id}/end',      [$O], 'contracts_end'],
```

- [ ] **Step 4: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/ContractsTest.php`
Expected: `OK (9 tests, ...)`.

- [ ] **Step 5: Run all tests and commit**

Run: `php vendor/bin/phpunit`
Expected: `OK (93 tests, ...)`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.8-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.8"/' composer.json
git add site/app/contracts.php site/app/bootstrap.php site/app/routes.php tests/ContractsTest.php README.md composer.json
git commit -m "Add contracts with activation and ending at 0.4.8."
```

---

### Task 10: Tasks: lists per role, assignment, needs per driver, collection

**Files:**
- Create: `site/app/tasks.php`
- Modify: `site/app/bootstrap.php` (file list), `site/app/routes.php`, `tests/ApiTestCase.php` (one helper)
- Test: `tests/TasksTest.php`

**Interfaces:**
- Consumes: `q()`, `tx()`, `fail()`, `int_in()`, `date_in()`, `enum_in()`, and `POST /contracts/{id}/activate` from Task 9.
- Produces:
  - `const TASK_SELECT` — selects `id, type, status, due_date, completed_at, contract_id, building_id, previous_task_id, assigned_to, assigned_name, signed_name, issue_note, has_proof, building_name, street, postcode, city, area_name, customer_id, customer_name, contact_name, contact_phone` with table alias `t` for `tasks`
  - `task_view(int $id): array` — one such row; 404 when unknown
  - `task_stop_details(array $task): array` — adds `zones [{id, name}]`, `expected [{zone_id, zone_name, item_type_id, type_name, quantity}]`, `in_building [{id, sku, epc, zone_id, type}]`
  - Routes:
    - `GET /tasks` — office: `{"tasks": [...]}` with filters `status`, `type`, `assigned_to`; driver: `{"tasks": [...]}` of his own open tasks with the stop details; inventory and outbound: `{"drivers": [{driver {id, name}, needs [{item_type_id, type, needed, carried, remaining}]}]}`
    - `POST /tasks` (office) — `{contract_id, building_id, due_date}` creates a `collection` task → `{"task": {...}}`
    - `PATCH /tasks/{id}` (office) — any of `assigned_to` (driver id or null), `due_date`, `status: "cancelled"` → `{"task": {...}}`
  - Test helper `activateContract(int $contractId, ?string $driver = null): array` — returns the task ids

- [ ] **Step 1: Add the test helper**

Add this method at the end of the class in `tests/ApiTestCase.php`:

```php
    /** Activates a seeded contract as the office and, if a driver is named, assigns its tasks to him. Returns the task ids. */
    protected function activateContract(int $contractId, ?string $driver = null): array
    {
        $this->loginAs('office');
        $taskIds = $this->ok($this->api('POST', "/contracts/$contractId/activate"))['task_ids'];
        if ($driver !== null) {
            foreach ($taskIds as $taskId) {
                $this->ok($this->api('PATCH', "/tasks/$taskId", ['assigned_to' => $this->users[$driver]]));
            }
        }
        return $taskIds;
    }
```

- [ ] **Step 2: Write the failing test**

Create `tests/TasksTest.php`:

```php
<?php
/** Task lists per role, assigning a driver, how many mats each driver still needs, and collection tasks. */
declare(strict_types=1);

final class TasksTest extends ApiTestCase
{
    public function test_the_office_sees_and_filters_all_tasks(): void
    {
        [$bakery] = $this->activateContract(1, 'driver');
        $this->activateContract(2);

        $tasks = $this->ok($this->api('GET', '/tasks'))['tasks'];
        $this->assertCount(3, $tasks);
        $row = array_values(array_filter($tasks, fn (array $t) => $t['id'] === $bakery))[0];
        $this->assertSame(['delivery', 'open', 'Mock Bakery BV', 'Mock Bakery shop', 'Mock North', 'Driver', 0],
            [$row['type'], $row['status'], $row['customer_name'], $row['building_name'], $row['area_name'], $row['assigned_name'], $row['has_proof']]);

        $this->assertCount(3, $this->ok($this->api('GET', '/tasks?type=delivery&status=open'))['tasks']);
        $this->assertCount(0, $this->ok($this->api('GET', '/tasks?status=done'))['tasks']);
        $this->assertSame([$bakery], array_column($this->ok($this->api('GET', "/tasks?assigned_to={$this->users['driver']}"))['tasks'], 'id'));
        $this->assertError(422, 'invalid_input', $this->api('GET', '/tasks?status=bogus'));
    }

    public function test_a_task_can_only_be_assigned_to_an_active_driver(): void
    {
        [$task] = $this->activateContract(1);
        $assign = fn (?int $userId) => $this->api('PATCH', "/tasks/$task", ['assigned_to' => $userId]);

        $this->assertSame($this->users['driver'], $this->ok($assign($this->users['driver']))['task']['assigned_to']);
        $this->assertNull($this->ok($assign(null))['task']['assigned_to']);
        $this->assertError(422, 'invalid_reference', $assign($this->users['washing']));
        q('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver2']]);
        $this->assertError(422, 'invalid_reference', $assign($this->users['driver2']));
    }

    public function test_a_driver_sees_his_open_tasks_with_everything_the_stop_needs(): void
    {
        $this->activateContract(1, 'driver');
        $this->activateContract(2, 'driver2');
        $this->setItems([9], 'at_customer', null, 1);

        $this->loginAs('driver');
        $tasks = $this->ok($this->api('GET', '/tasks'))['tasks'];
        $this->assertCount(1, $tasks);
        $task = $tasks[0];
        $this->assertSame(['delivery', 'Mock Bakery BV', 'Mock Contact One', '000-0000001', 'Mockstraat 1', 'Mockstad'],
            [$task['type'], $task['customer_name'], $task['contact_name'], $task['contact_phone'], $task['street'], $task['city']]);
        $this->assertSame(['Front door', 'Staff entrance'], array_column($task['zones'], 'name'));
        $this->assertSame([['Front door', 'Mock mat 85x150 grey', 2], ['Staff entrance', 'Mock mat 85x150 grey', 1]],
            array_map(fn (array $e) => [$e['zone_name'], $e['type_name'], $e['quantity']], $task['expected']));
        $this->assertSame([['id' => $this->item(9)['id'], 'sku' => 'MOCK-0009', 'epc' => $this->epc(9), 'zone_id' => 1, 'type' => 'Mock mat 85x150 grey']],
            $task['in_building']);
    }

    public function test_a_driver_does_not_see_other_or_closed_tasks(): void
    {
        [$task] = $this->activateContract(1, 'driver');
        $this->loginAs('driver2');
        $this->assertSame([], $this->ok($this->api('GET', '/tasks'))['tasks']);

        $this->loginAs('office');
        $this->ok($this->api('PATCH', "/tasks/$task", ['status' => 'cancelled']));
        $this->loginAs('driver');
        $this->assertSame([], $this->ok($this->api('GET', '/tasks'))['tasks']);
    }

    public function test_inventory_and_outbound_see_how_many_mats_each_driver_still_needs(): void
    {
        $this->activateContract(1, 'driver');
        $this->activateContract(2, 'driver2');

        foreach (['inventory', 'outbound'] as $who) {
            $this->loginAs($who);
            $drivers = $this->ok($this->api('GET', '/tasks'))['drivers'];
            $this->assertSame(['Driver', 'Driver2'], array_column(array_column($drivers, 'driver'), 'name'));
            $this->assertSame([['item_type_id' => 1, 'type' => 'Mock mat 85x150 grey', 'needed' => 3, 'carried' => 0, 'remaining' => 3]],
                $drivers[0]['needs']);
            // Contract 2: type 3 once, type 2 three times over two buildings, type 1 once. Sorted by type name.
            $this->assertSame([3 => 1, 2 => 3, 1 => 1], array_column($drivers[1]['needs'], 'needed', 'item_type_id'));
        }

        $this->setItems([1, 2], 'out_for_delivery', $this->users['driver']);
        $this->assertSame([2, 1], [$this->ok($this->api('GET', '/tasks'))['drivers'][0]['needs'][0]['carried'],
            $this->ok($this->api('GET', '/tasks'))['drivers'][0]['needs'][0]['remaining']]);
        $this->setItems([3, 4], 'out_for_delivery', $this->users['driver']);
        $this->assertSame(0, $this->ok($this->api('GET', '/tasks'))['drivers'][0]['needs'][0]['remaining']);   // never below zero
    }

    public function test_a_task_without_a_driver_needs_no_mats_yet(): void
    {
        $this->activateContract(1);
        $this->loginAs('inventory');
        $this->assertSame([], $this->ok($this->api('GET', '/tasks'))['drivers']);
    }

    public function test_the_office_moves_and_cancels_an_open_task(): void
    {
        [$task] = $this->activateContract(1);
        $this->assertSame('2026-12-01', $this->ok($this->api('PATCH', "/tasks/$task", ['due_date' => '2026-12-01']))['task']['due_date']);
        $this->assertError(422, 'invalid_input', $this->api('PATCH', "/tasks/$task", ['due_date' => 'tomorrow']));
        $this->assertError(422, 'invalid_input', $this->api('PATCH', "/tasks/$task", ['status' => 'done']));
        $this->assertError(404, 'not_found', $this->api('PATCH', '/tasks/999', ['due_date' => '2026-12-01']));

        $this->assertSame('cancelled', $this->ok($this->api('PATCH', "/tasks/$task", ['status' => 'cancelled']))['task']['status']);
        $this->assertError(409, 'not_open', $this->api('PATCH', "/tasks/$task", ['due_date' => '2026-12-02']));
    }

    public function test_the_office_creates_a_collection_task_for_a_building_of_the_contract(): void
    {
        $this->activateContract(1);
        $new = ['contract_id' => 1, 'building_id' => 1, 'due_date' => '2026-12-24'];
        $task = $this->ok($this->api('POST', '/tasks', $new))['task'];
        $this->assertSame(['collection', 'open', '2026-12-24', null], [$task['type'], $task['status'], $task['due_date'], $task['assigned_to']]);

        $this->assertError(422, 'invalid_reference', $this->api('POST', '/tasks', ['building_id' => 2] + $new));   // not in contract 1
        $this->assertError(409, 'not_started', $this->api('POST', '/tasks', ['contract_id' => 2, 'building_id' => 2] + $new));   // still a draft
        $this->assertError(404, 'not_found', $this->api('POST', '/tasks', ['contract_id' => 999] + $new));

        $this->loginAs('driver');
        $this->assertError(403, 'forbidden', $this->api('POST', '/tasks', $new));
        $this->assertError(403, 'forbidden', $this->api('PATCH', "/tasks/{$task['id']}", ['status' => 'cancelled']));
        $this->loginAs('washing');
        $this->assertError(403, 'forbidden', $this->api('GET', '/tasks'));
    }
}
```

- [ ] **Step 3: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/TasksTest.php`
Expected: FAIL: the helper's `PATCH /tasks/{id}` and every `GET /tasks` get `404 not_found`.

- [ ] **Step 4: Write the implementation**

Create `site/app/tasks.php`:

```php
<?php
/** Tasks: one visit to one building. Lists per role, assignment, closing a stop with proof, and the next swap. */
declare(strict_types=1);

const TASK_SELECT = 'SELECT t.id, t.type, t.status, t.due_date, t.completed_at, t.contract_id, t.building_id, t.previous_task_id,
        t.assigned_to, u.name AS assigned_name, t.signed_name, t.issue_note, t.photo_path IS NOT NULL AS has_proof,
        b.name AS building_name, b.street, b.postcode, b.city, a.name AS area_name,
        c.id AS customer_id, c.name AS customer_name, c.contact_name, c.contact_phone
    FROM tasks t
    JOIN buildings b ON b.id = t.building_id
    JOIN areas a ON a.id = b.area_id
    JOIN customers c ON c.id = b.customer_id
    LEFT JOIN users u ON u.id = t.assigned_to';

function task_view(int $id): array
{
    $task = q(TASK_SELECT . ' WHERE t.id = ?', [$id])->fetch();
    if (!$task) {
        fail(404, 'not_found', 'No such task.');
    }
    return $task;
}

/** What the driver needs at the stop: the zones, the mats the contract expects, and the mats lying there now. */
function task_stop_details(array $task): array
{
    $task['zones'] = q('SELECT id, name FROM zones WHERE building_id = ? AND is_active = 1 ORDER BY name',
        [$task['building_id']])->fetchAll();
    $task['expected'] = q('SELECT l.zone_id, z.name AS zone_name, l.item_type_id, it.name AS type_name, l.quantity
        FROM contract_lines l JOIN zones z ON z.id = l.zone_id JOIN item_types it ON it.id = l.item_type_id
        WHERE l.contract_id = ? AND z.building_id = ? ORDER BY z.name, it.name',
        [$task['contract_id'], $task['building_id']])->fetchAll();
    $task['in_building'] = q("SELECT i.id, i.sku, i.epc, i.zone_id, it.name AS type
        FROM items i JOIN item_types it ON it.id = i.item_type_id JOIN zones z ON z.id = i.zone_id
        WHERE i.status = 'at_customer' AND z.building_id = ? ORDER BY i.sku", [$task['building_id']])->fetchAll();
    return $task;
}

/** Per driver and mat type: mats his open delivery and swap tasks need, mats already on his van, and the difference. */
function task_needs(): array
{
    $needed = q("SELECT t.assigned_to AS driver_id, u.name AS driver_name, l.item_type_id, it.name AS type_name,
            SUM(l.quantity) AS needed
        FROM tasks t
        JOIN users u ON u.id = t.assigned_to
        JOIN contract_lines l ON l.contract_id = t.contract_id
        JOIN zones z ON z.id = l.zone_id AND z.building_id = t.building_id
        JOIN item_types it ON it.id = l.item_type_id
        WHERE t.status = 'open' AND t.type IN ('delivery', 'swap')
        GROUP BY t.assigned_to, u.name, l.item_type_id, it.name
        ORDER BY u.name, it.name")->fetchAll();

    $carried = [];
    $onVans = q("SELECT holder_user_id, item_type_id, COUNT(*) AS n FROM items
        WHERE status = 'out_for_delivery' GROUP BY holder_user_id, item_type_id")->fetchAll();
    foreach ($onVans as $row) {
        $carried["{$row['holder_user_id']}:{$row['item_type_id']}"] = (int) $row['n'];
    }

    $drivers = [];
    foreach ($needed as $row) {
        $have = $carried["{$row['driver_id']}:{$row['item_type_id']}"] ?? 0;
        $drivers[$row['driver_id']] ??= ['driver' => ['id' => $row['driver_id'], 'name' => $row['driver_name']], 'needs' => []];
        $drivers[$row['driver_id']]['needs'][] = [
            'item_type_id' => $row['item_type_id'],
            'type' => $row['type_name'],
            'needed' => (int) $row['needed'],   // SUM() comes back as a decimal string
            'carried' => $have,
            'remaining' => max(0, (int) $row['needed'] - $have),
        ];
    }
    return array_values($drivers);
}

function tasks_list(Request $req, array $user): array
{
    if ($user['role'] === 'inventory' || $user['role'] === 'outbound') {
        return ['drivers' => task_needs()];
    }
    if ($user['role'] === 'driver') {
        $rows = q(TASK_SELECT . " WHERE t.assigned_to = ? AND t.status = 'open' ORDER BY t.due_date, t.id", [$user['id']])->fetchAll();
        return ['tasks' => array_map('task_stop_details', $rows)];
    }

    $where = ['1 = 1'];
    $params = [];
    $filter = $req->query;
    if (($filter['status'] ?? '') !== '') {
        $where[] = 't.status = ?';
        $params[] = enum_in($filter, 'status', ['open', 'done', 'cancelled']);
    }
    if (($filter['type'] ?? '') !== '') {
        $where[] = 't.type = ?';
        $params[] = enum_in($filter, 'type', ['delivery', 'swap', 'collection']);
    }
    if (($filter['assigned_to'] ?? '') !== '') {
        $where[] = 't.assigned_to = ?';
        $params[] = int_in($filter, 'assigned_to');
    }
    // ponytail: open work first, 200 rows, no paging. Add paging when the office has more than fits one screen.
    return ['tasks' => q(TASK_SELECT . ' WHERE ' . implode(' AND ', $where)
        . " ORDER BY t.status = 'open' DESC, t.due_date, t.id LIMIT 200", $params)->fetchAll()];
}

/** The office creates a collection task: pick-up only, when a contract ends. */
function tasks_create(Request $req, array $user): array
{
    $in = $req->body;
    $contractId = int_in($in, 'contract_id');
    $buildingId = int_in($in, 'building_id');
    $due = date_in($in, 'due_date');

    $contract = q('SELECT status FROM contracts WHERE id = ?', [$contractId])->fetch();
    if (!$contract) {
        fail(404, 'not_found', 'No such contract.');
    }
    if ($contract['status'] === 'draft') {
        fail(409, 'not_started', 'A draft contract has no mats to collect.');
    }
    $inContract = q('SELECT 1 FROM contract_lines l JOIN zones z ON z.id = l.zone_id WHERE l.contract_id = ? AND z.building_id = ? LIMIT 1',
        [$contractId, $buildingId])->fetch();
    if (!$inContract) {
        fail(422, 'invalid_reference', 'This building is not part of that contract.');
    }
    q("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('collection', ?, ?, ?)", [$contractId, $buildingId, $due]);
    return ['task' => task_view((int) db()->lastInsertId())];
}

function tasks_update(Request $req, array $user, int $id): array
{
    $in = $req->body;
    return tx(function () use ($in, $id): array {
        $task = q('SELECT status FROM tasks WHERE id = ? FOR UPDATE', [$id])->fetch();
        if (!$task) {
            fail(404, 'not_found', 'No such task.');
        }
        if ($task['status'] !== 'open') {
            fail(409, 'not_open', "This task is already {$task['status']}.");
        }
        if (array_key_exists('assigned_to', $in)) {
            $driverId = int_in($in, 'assigned_to', false);
            if ($driverId !== null && !q("SELECT 1 FROM users WHERE id = ? AND role = 'driver' AND is_active = 1", [$driverId])->fetch()) {
                fail(422, 'invalid_reference', 'A task can only be assigned to an active driver.');
            }
            q('UPDATE tasks SET assigned_to = ? WHERE id = ?', [$driverId, $id]);
        }
        if (array_key_exists('due_date', $in)) {
            q('UPDATE tasks SET due_date = ? WHERE id = ?', [date_in($in, 'due_date'), $id]);
        }
        if (array_key_exists('status', $in)) {
            enum_in($in, 'status', ['cancelled']);
            q("UPDATE tasks SET status = 'cancelled' WHERE id = ? AND status = 'open'", [$id]);
        }
        return ['task' => task_view($id)];
    });
}
```

In `site/app/bootstrap.php` replace the file list:

```php
foreach (['db', 'http', 'kernel', 'auth', 'refdata', 'users', 'scan', 'items', 'handovers', 'contracts', 'tasks'] as $file) {
    require __DIR__ . "/$file.php";
}
```

In `site/app/routes.php` add these entries at the end of the returned array:

```php
    ['GET',   '/tasks',      [$O, $D, $I, $X], 'tasks_list'],
    ['POST',  '/tasks',      [$O],             'tasks_create'],
    ['PATCH', '/tasks/{id}', [$O],             'tasks_update'],
```

- [ ] **Step 5: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/TasksTest.php`
Expected: `OK (8 tests, ...)`.

- [ ] **Step 6: Run all tests and commit**

Run: `php vendor/bin/phpunit`
Expected: `OK (101 tests, ...)`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.9-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.9"/' composer.json
git add site/app/tasks.php site/app/bootstrap.php site/app/routes.php tests/ApiTestCase.php tests/TasksTest.php README.md composer.json
git commit -m "Add task lists per role, assignment and collection tasks at 0.4.9."
```

---

### Task 11: Closing a stop with proof, and the next swap

**Files:**
- Modify: `site/app/tasks.php` (add functions at the end), `site/app/routes.php`, `tests/ApiTestCase.php` (two helpers)
- Test: `tests/TaskCompleteTest.php`

**Interfaces:**
- Consumes: `source_in()`, `hex_in()`, `items_by_epc()`, `log_event()`, `task_view()`, `storage_dir()`, `today()`, `days_after()`, `q()`, `tx()`, `str_in()`, `int_in()`, `FileOut`, and the test helper `activateContract()` from Task 10.
- Produces:
  - `POST /tasks/{id}/complete` (driver) — body `{source, drops: [{zone_id, epcs: [...]}], pickups: [...], refused: [...], signed_name, issue_note}` plus the files `photo` (JPEG, at most 5 MB) and `signature` (PNG, at most 1 MB) → `{"task": {...}, "next_task_id": int|null}`
  - `GET /tasks/{id}/photo` and `GET /tasks/{id}/signature` (office) → the file
  - Test helpers `proofFile(string $kind, int $padBytes = 0): string` and `epcs(int ...$mats): array`

Rules for closing a stop:

1. **Refused whole** (nothing is saved): the task is not open (`409`) or not assigned to the caller (`403`); a tag no mat has (`422`); a dropped or refused mat that is not `out_for_delivery` on the caller's van (`409`); a drop zone outside the task's building (`422`); a picked-up mat that is not `at_customer` in this building (`409`); the same mat in two lists (`422`); a drop on a collection task (`422`); a missing or wrong photo or signature (`422`).
2. **Needs a note** (`422 note_required` without one): the drops do not match the contract lines of this building exactly (per zone and mat type), or the pick-ups are not exactly the mats lying in this building. A delivery expects no pick-ups; a collection expects no drops.
3. **On success:** dropped mats become `at_customer` in their zone, refused mats become `refused` and stay with the driver, picked-up mats become `collected` with the driver. The two files are stored under `storage/proofs` with a server-made name. The task becomes `done`. For a delivery or swap of an active contract the next swap task is created, due on today + n, not assigned.

- [ ] **Step 1: Add the test helpers**

Add these two methods at the end of the class in `tests/ApiTestCase.php`:

```php
    /** A real 8x8 image file of the given kind ("jpeg" or "png"), optionally padded to make it big. Returns its path. */
    protected function proofFile(string $kind, int $padBytes = 0): string
    {
        $path = tempnam(sys_get_temp_dir(), 'proof');
        $image = imagecreatetruecolor(8, 8);
        $kind === 'jpeg' ? imagejpeg($image, $path) : imagepng($image, $path);
        if ($padBytes > 0) {
            file_put_contents($path, str_repeat("\0", $padBytes), FILE_APPEND);
        }
        return $path;
    }

    /** The EPCs of mock mats n, n, n. */
    protected function epcs(int ...$mats): array
    {
        return array_map(fn (int $n) => $this->epc($n), $mats);
    }
```

- [ ] **Step 2: Write the failing test**

Create `tests/TaskCompleteTest.php`:

```php
<?php
/** Closing a stop: scan, photo and signature; hard refusals; the note for a mismatch; the next swap task; the proof files. */
declare(strict_types=1);

final class TaskCompleteTest extends ApiTestCase
{
    private function stop(array $over = []): array
    {
        return $over + ['source' => 'simulator', 'drops' => [], 'pickups' => [], 'refused' => [],
            'signed_name' => 'Mock Contact One', 'issue_note' => null];
    }

    /** Contract 1 wants two mats at the front door (zone 1) and one at the staff entrance (zone 2). */
    private function fullDrop(int $a, int $b, int $c): array
    {
        return [['zone_id' => 1, 'epcs' => $this->epcs($a, $b)], ['zone_id' => 2, 'epcs' => $this->epcs($c)]];
    }

    private function complete(int $task, array $stop, ?array $files = null): array
    {
        return $this->api('POST', "/tasks/$task/complete", $stop,
            $files ?? ['photo' => $this->proofFile('jpeg'), 'signature' => $this->proofFile('png')]);
    }

    /** Contract 1 is active, its delivery task belongs to the driver, mats 1-3 are on his van. Logged in as the driver. */
    private function deliveryReady(): int
    {
        [$task] = $this->activateContract(1, 'driver');
        $this->setItems([1, 2, 3], 'out_for_delivery', $this->users['driver']);
        $this->loginAs('driver');
        return $task;
    }

    /** Mats 1-3 lie at the customer, the swap task belongs to the driver, mats 4-6 are on his van. Logged in as the driver. */
    private function swapReady(): int
    {
        $delivery = $this->deliveryReady();
        $swap = $this->ok($this->complete($delivery, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])))['next_task_id'];
        $this->loginAs('office');
        $this->ok($this->api('PATCH', "/tasks/$swap", ['assigned_to' => $this->users['driver']]));
        $this->setItems([4, 5, 6], 'out_for_delivery', $this->users['driver']);
        $this->loginAs('driver');
        return $swap;
    }

    private function taskRow(int $id): array
    {
        return q('SELECT * FROM tasks WHERE id = ?', [$id])->fetch();
    }

    private function storedProofs(): array
    {
        return glob(storage_dir() . '/proofs/*') ?: [];
    }

    public function test_closing_a_delivery_puts_the_mats_in_their_zones_and_plans_the_swap(): void
    {
        $task = $this->deliveryReady();
        $out = $this->ok($this->complete($task, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));

        $this->assertSame(['done', 'Mock Contact One', 1], [$out['task']['status'], $out['task']['signed_name'], $out['task']['has_proof']]);
        $this->assertSame(['at_customer', 1, null], [$this->item(1)['status'], $this->item(1)['zone_id'], $this->item(1)['holder_user_id']]);
        $this->assertSame(2, $this->item(3)['zone_id']);

        $row = $this->taskRow($task);
        $this->assertNotNull($row['completed_at']);
        $this->assertMatchesRegularExpression('/^task-' . $task . '-[0-9a-f]{16}\.jpg$/', $row['photo_path']);
        $this->assertMatchesRegularExpression('/^task-' . $task . '-[0-9a-f]{16}\.png$/', $row['signature_path']);
        $this->assertFileExists(storage_dir() . '/proofs/' . $row['photo_path']);
        $this->assertFileExists(storage_dir() . '/proofs/' . $row['signature_path']);

        $next = $this->taskRow($out['next_task_id']);
        $this->assertSame(['swap', 'open', 1, 1, $task, days_after(today(), 14), null],
            [$next['type'], $next['status'], $next['contract_id'], $next['building_id'], $next['previous_task_id'], $next['due_date'], $next['assigned_to']]);

        $event = $this->events(1)[0];
        $this->assertSame(['deliver', 'out_for_delivery', 'at_customer', $task, 1, 'simulator'],
            [$event['event_type'], $event['from_status'], $event['to_status'], $event['task_id'], $event['zone_id'], $event['source']]);
    }

    public function test_a_stop_needs_a_photo_and_a_signature_of_the_right_kind(): void
    {
        $task = $this->deliveryReady();
        $stop = $this->stop(['drops' => $this->fullDrop(1, 2, 3)]);
        $jpeg = $this->proofFile('jpeg');
        $png = $this->proofFile('png');
        $text = tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($text, 'this is not an image');

        $this->assertError(422, 'proof_required', $this->complete($task, $stop, ['signature' => $png]));
        $this->assertError(422, 'proof_required', $this->complete($task, $stop, ['photo' => $jpeg]));
        $this->assertError(422, 'wrong_file_type', $this->complete($task, $stop, ['photo' => $png, 'signature' => $png]));
        $this->assertError(422, 'wrong_file_type', $this->complete($task, $stop, ['photo' => $jpeg, 'signature' => $jpeg]));
        $this->assertError(422, 'wrong_file_type', $this->complete($task, $stop, ['photo' => $text, 'signature' => $png]));

        $this->assertSame('open', $this->taskRow($task)['status']);
        $this->assertSame('out_for_delivery', $this->item(1)['status']);
        $this->assertSame([], $this->storedProofs());
    }

    public function test_a_phone_sized_photo_is_accepted_and_an_oversized_one_is_refused(): void
    {
        $task = $this->deliveryReady();
        $stop = $this->stop(['drops' => $this->fullDrop(1, 2, 3)]);
        $png = $this->proofFile('png');

        $tooBig = $this->proofFile('jpeg', 5 * 1024 * 1024);
        $this->assertError(422, 'file_too_big', $this->complete($task, $stop, ['photo' => $tooBig, 'signature' => $png]));
        $bigSignature = $this->proofFile('png', 1024 * 1024);
        $this->assertError(422, 'file_too_big', $this->complete($task, $stop, ['photo' => $this->proofFile('jpeg'), 'signature' => $bigSignature]));

        $phonePhoto = $this->proofFile('jpeg', 3 * 1024 * 1024);   // above PHP's 2 MB default
        $this->ok($this->complete($task, $stop, ['photo' => $phonePhoto, 'signature' => $png]));
        // PHP itself must let such a photo through, before this code ever sees it.
        $ini = parse_ini_file(__DIR__ . '/../site/www/.user.ini');
        $this->assertSame(['6M', '8M'], [$ini['upload_max_filesize'], $ini['post_max_size']]);
    }

    public function test_only_the_assigned_driver_closes_a_stop_and_only_once(): void
    {
        $task = $this->deliveryReady();
        $stop = $this->stop(['drops' => $this->fullDrop(1, 2, 3)]);

        $this->loginAs('driver2');
        $this->assertError(403, 'forbidden', $this->complete($task, $stop));
        [$unassigned] = $this->activateContract(2);
        $this->loginAs('driver');
        $this->assertError(403, 'forbidden', $this->complete($unassigned, $stop));
        $this->assertError(404, 'not_found', $this->complete(999, $stop));

        $this->ok($this->complete($task, $stop));
        $this->assertError(409, 'not_open', $this->complete($task, $stop));   // sent twice
        $this->assertSame(1, (int) q("SELECT COUNT(*) FROM tasks WHERE type = 'swap'")->fetchColumn());
        $this->assertCount(2, $this->storedProofs());
    }

    public function test_a_stop_that_does_not_match_the_contract_needs_a_note(): void
    {
        $task = $this->deliveryReady();
        $short = ['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1, 2)]]];   // nothing for the staff entrance

        $this->assertError(422, 'note_required', $this->complete($task, $this->stop($short)));
        $this->assertSame('out_for_delivery', $this->item(1)['status']);
        $this->assertSame('open', $this->taskRow($task)['status']);
        $this->assertSame([], $this->storedProofs());

        // Right number of mats, wrong zone: also a mismatch.
        $wrongZone = ['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1)], ['zone_id' => 2, 'epcs' => $this->epcs(2, 3)]]];
        $this->assertError(422, 'note_required', $this->complete($task, $this->stop($wrongZone)));

        $out = $this->ok($this->complete($task, $this->stop($short + ['issue_note' => 'Staff entrance was locked'])));
        $this->assertSame('Staff entrance was locked', $out['task']['issue_note']);
        $this->assertSame(['out_for_delivery', $this->users['driver']], [$this->item(3)['status'], $this->item(3)['holder_user_id']]);
    }

    public function test_a_refused_mat_stays_on_the_van(): void
    {
        $task = $this->deliveryReady();
        $stop = $this->stop(['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1, 2)]], 'refused' => $this->epcs(3),
            'issue_note' => 'Customer refused the third mat']);
        $this->ok($this->complete($task, $stop));

        $this->assertSame(['refused', $this->users['driver'], null],
            [$this->item(3)['status'], $this->item(3)['holder_user_id'], $this->item(3)['zone_id']]);
        $event = $this->events(3)[0];
        $this->assertSame(['refuse', 'out_for_delivery', 'refused', $task], [$event['event_type'], $event['from_status'], $event['to_status'], $event['task_id']]);
    }

    public function test_an_impossible_stop_is_refused_whole(): void
    {
        $task = $this->deliveryReady();
        $note = ['issue_note' => 'x'];

        // Mat 10 is ready in inventory, not on the van.
        $this->assertError(409, 'wrong_status', $this->complete($task, $this->stop($note + ['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1, 2, 10)]]])));
        // Zone 3 is in another customer's building.
        $this->assertError(422, 'wrong_zone', $this->complete($task, $this->stop($note + ['drops' => [['zone_id' => 3, 'epcs' => $this->epcs(1)]]])));
        $this->assertError(422, 'unknown_tag', $this->complete($task, $this->stop($note + ['refused' => ['AAAA0000']])));
        $this->assertError(422, 'duplicate_tag', $this->complete($task, $this->stop($note + ['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(1)]], 'refused' => $this->epcs(1)])));
        $this->assertError(422, 'invalid_input', $this->complete($task, $this->stop(['drops' => $this->fullDrop(1, 2, 3), 'signed_name' => ''])));
        $this->assertError(422, 'invalid_input', $this->complete($task, ['source' => null] + $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));
        $this->assertError(422, 'invalid_input', $this->complete($task, $this->stop(['drops' => 'everything'])));

        $this->assertSame(['out_for_delivery', 'out_for_delivery', 'out_for_delivery'],
            [$this->item(1)['status'], $this->item(2)['status'], $this->item(3)['status']]);
        $this->assertSame('open', $this->taskRow($task)['status']);
        $this->assertSame(0, (int) q('SELECT COUNT(*) FROM item_events')->fetchColumn());
        $this->assertSame([], $this->storedProofs());
    }

    public function test_a_swap_drops_clean_mats_and_takes_the_used_ones(): void
    {
        $swap = $this->swapReady();
        $out = $this->ok($this->complete($swap, $this->stop(['drops' => $this->fullDrop(4, 5, 6), 'pickups' => $this->epcs(1, 2, 3)])));

        $this->assertSame(['collected', $this->users['driver'], null],
            [$this->item(1)['status'], $this->item(1)['holder_user_id'], $this->item(1)['zone_id']]);
        $this->assertSame(['at_customer', 1], [$this->item(4)['status'], $this->item(4)['zone_id']]);
        $this->assertSame(['swap', $swap], [$this->taskRow($out['next_task_id'])['type'], $this->taskRow($out['next_task_id'])['previous_task_id']]);

        $collect = $this->events(3)[1];
        $this->assertSame(['collect', 'at_customer', 'collected', 2], [$collect['event_type'], $collect['from_status'], $collect['to_status'], $collect['zone_id']]);
    }

    public function test_a_mat_missing_at_pick_up_stays_expected_at_the_customer(): void
    {
        $swap = $this->swapReady();
        $stop = ['drops' => $this->fullDrop(4, 5, 6), 'pickups' => $this->epcs(1, 2)];
        $this->assertError(422, 'note_required', $this->complete($swap, $this->stop($stop)));

        $this->ok($this->complete($swap, $this->stop($stop + ['issue_note' => 'Mat at the staff entrance is gone'])));
        $this->assertSame(['at_customer', 2], [$this->item(3)['status'], $this->item(3)['zone_id']]);
    }

    public function test_a_mat_from_another_building_cannot_be_picked_up(): void
    {
        $swap = $this->swapReady();
        $this->setItems([20], 'at_customer', null, 3);   // zone 3 is in another building
        $stop = $this->stop(['drops' => $this->fullDrop(4, 5, 6), 'pickups' => $this->epcs(1, 2, 3, 20), 'issue_note' => 'x']);
        $this->assertError(409, 'wrong_status', $this->complete($swap, $stop));
        $this->assertSame('at_customer', $this->item(1)['status']);
    }

    public function test_a_collection_only_picks_up_and_plans_nothing(): void
    {
        $delivery = $this->deliveryReady();
        $this->ok($this->complete($delivery, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));

        $this->loginAs('office');
        $this->ok($this->api('POST', '/contracts/1/end'));   // cancels the swap that the delivery planned
        $collection = $this->ok($this->api('POST', '/tasks', ['contract_id' => 1, 'building_id' => 1, 'due_date' => today()]))['task']['id'];
        $this->ok($this->api('PATCH', "/tasks/$collection", ['assigned_to' => $this->users['driver']]));

        $this->loginAs('driver');
        $this->assertError(422, 'no_drops', $this->complete($collection,
            $this->stop(['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(4)]], 'pickups' => $this->epcs(1, 2, 3)])));

        $out = $this->ok($this->complete($collection, $this->stop(['pickups' => $this->epcs(1, 2, 3)])));
        $this->assertNull($out['next_task_id']);
        $this->assertSame('collected', $this->item(2)['status']);
        $this->assertSame(0, (int) q("SELECT COUNT(*) FROM tasks WHERE status = 'open'")->fetchColumn());
    }

    public function test_a_delivery_for_an_ended_contract_plans_no_swap(): void
    {
        $task = $this->deliveryReady();
        $this->loginAs('office');
        $this->ok($this->api('POST', '/contracts/1/end'));

        $this->loginAs('driver');
        $out = $this->ok($this->complete($task, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));
        $this->assertNull($out['next_task_id']);
        $this->assertSame(0, (int) q("SELECT COUNT(*) FROM tasks WHERE type = 'swap'")->fetchColumn());
    }

    public function test_proof_files_are_served_to_the_office_only(): void
    {
        $task = $this->deliveryReady();
        $this->ok($this->complete($task, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));
        $this->assertError(403, 'forbidden', $this->api('GET', "/tasks/$task/photo"));   // not even the driver who took it

        $this->loginAs('office');
        [$status, $photo] = $this->api('GET', "/tasks/$task/photo");
        $this->assertSame(200, $status);
        $this->assertInstanceOf(FileOut::class, $photo);
        $this->assertSame('image/jpeg', $photo->mime);
        $this->assertFileExists($photo->path);
        $this->assertSame('image/png', $this->api('GET', "/tasks/$task/signature")[1]->mime);

        $open = q("SELECT id FROM tasks WHERE status = 'open'")->fetchColumn();   // the planned swap has no proof yet
        $this->assertError(404, 'no_proof', $this->api('GET', "/tasks/$open/photo"));
        $this->assertError(404, 'not_found', $this->api('GET', '/tasks/999/photo'));
    }
}
```

- [ ] **Step 3: Run the test to see it fail**

Run: `php vendor/bin/phpunit tests/TaskCompleteTest.php`
Expected: FAIL, every `complete` call gets `404 not_found`.

- [ ] **Step 4: Write the implementation**

Add at the end of `site/app/tasks.php`:

```php
const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
const MAX_SIGNATURE_BYTES = 1024 * 1024;

/** A list of tag ids from the body of a stop, uppercase, each one once. */
function epcs_in(array $in, string $key): array
{
    $list = $in[$key] ?? [];
    if (!is_array($list) || !array_is_list($list)) {
        fail(422, 'invalid_input', "$key must be a list of tag ids.");
    }
    return array_values(array_unique(array_map(fn ($epc) => hex_in(['epc' => $epc], 'epc'), $list)));
}

/** The drops of a stop as zone id => tag ids. */
function drops_in(array $in): array
{
    $drops = $in['drops'] ?? [];
    if (!is_array($drops) || !array_is_list($drops)) {
        fail(422, 'invalid_input', 'drops must be a list.');
    }
    $byZone = [];
    foreach ($drops as $drop) {
        if (!is_array($drop)) {
            fail(422, 'invalid_input', 'Every drop needs zone_id and epcs.');
        }
        $zoneId = int_in($drop, 'zone_id');
        $byZone[$zoneId] = array_values(array_unique([...($byZone[$zoneId] ?? []), ...epcs_in($drop, 'epcs')]));
    }
    return $byZone;
}

/** An uploaded proof file, checked by its content and not by its name. Returns the path of the temp file. */
function proof_in(Request $req, string $field, string $mime, int $maxBytes): string
{
    $path = $req->files[$field] ?? null;
    if (!is_string($path) || !is_file($path)) {
        fail(422, 'proof_required', "A $field is required to close a stop.");
    }
    if (filesize($path) > $maxBytes) {
        fail(422, 'file_too_big', "The $field is larger than " . intdiv($maxBytes, 1024 * 1024) . ' MB.');
    }
    $image = @getimagesize($path);   // warns on a broken file; "false" is all this code needs
    if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== $mime || !$image || $image['mime'] !== $mime) {
        fail(422, 'wrong_file_type', "The $field must be a " . ($mime === 'image/jpeg' ? 'JPEG' : 'PNG') . ' image.');
    }
    return $path;
}

function tasks_complete(Request $req, array $user, int $id): array
{
    $in = $req->body;
    $source = source_in($in);
    $signedName = str_in($in, 'signed_name', 120);
    $note = str_in($in, 'issue_note', 500, false);
    $drops = drops_in($in);
    $pickups = epcs_in($in, 'pickups');
    $refused = epcs_in($in, 'refused');
    $dropped = array_merge(...array_values($drops));
    $all = [...$dropped, ...$pickups, ...$refused];
    if (count($all) !== count(array_unique($all))) {
        fail(422, 'duplicate_tag', 'A mat appears in more than one list of this stop.');
    }
    $photo = proof_in($req, 'photo', 'image/jpeg', MAX_PHOTO_BYTES);
    $signature = proof_in($req, 'signature', 'image/png', MAX_SIGNATURE_BYTES);

    $saved = [];
    try {
        return tx(function () use ($id, $user, $source, $signedName, $note, $drops, $dropped, $pickups, $refused, $all, $photo, $signature, &$saved): array {
            $task = q('SELECT t.*, c.status AS contract_status, c.swap_interval_days
                FROM tasks t JOIN contracts c ON c.id = t.contract_id WHERE t.id = ? FOR UPDATE', [$id])->fetch();
            if (!$task) {
                fail(404, 'not_found', 'No such task.');
            }
            if ($task['assigned_to'] !== $user['id']) {
                fail(403, 'forbidden', 'This task is not assigned to you.');
            }
            if ($task['status'] !== 'open') {
                fail(409, 'not_open', "This task is already {$task['status']}.");
            }
            if ($task['type'] === 'collection' && $dropped !== []) {
                fail(422, 'no_drops', 'A collection task only picks mats up.');
            }

            $items = items_by_epc($all, true);
            foreach ($all as $epc) {
                if (!isset($items[$epc])) {
                    fail(422, 'unknown_tag', "No mat has the tag $epc.");
                }
            }
            $zoneIds = q('SELECT id FROM zones WHERE building_id = ?', [$task['building_id']])->fetchAll(PDO::FETCH_COLUMN);

            // Hard rules: breaking one refuses the whole stop.
            foreach (array_keys($drops) as $zoneId) {
                if (!in_array($zoneId, $zoneIds, true)) {
                    fail(422, 'wrong_zone', "Zone $zoneId is not in this building.");
                }
            }
            foreach ([...$dropped, ...$refused] as $epc) {
                $item = $items[$epc];
                if ($item['status'] !== 'out_for_delivery' || $item['holder_user_id'] !== $user['id']) {
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
                $lines = q('SELECT l.zone_id, l.item_type_id, l.quantity FROM contract_lines l JOIN zones z ON z.id = l.zone_id
                    WHERE l.contract_id = ? AND z.building_id = ?', [$task['contract_id'], $task['building_id']])->fetchAll();
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
            $present = $task['type'] === 'delivery' ? [] : q("SELECT i.epc FROM items i JOIN zones z ON z.id = i.zone_id
                WHERE i.status = 'at_customer' AND z.building_id = ?", [$task['building_id']])->fetchAll(PDO::FETCH_COLUMN);
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
                $moved = q('UPDATE items SET status = ?, holder_user_id = ?, zone_id = ? WHERE id = ? AND status = ?',
                    [$to, $holder, $zone, $item['id'], $item['status']])->rowCount();
                if ($moved !== 1) {
                    fail(409, 'wrong_status', "{$item['sku']} changed while the stop was being closed.");
                }
                // A drop is logged with the zone the mat went to, a pick-up with the zone it came from.
                log_event($item, $user['id'], $event, $to, $source, ['task_id' => $id, 'zone_id' => $zone ?? $item['zone_id']]);
            };
            foreach ($drops as $zoneId => $epcs) {
                foreach ($epcs as $epc) {
                    $move($items[$epc], 'at_customer', null, $zoneId, 'deliver');
                }
            }
            foreach ($refused as $epc) {
                $move($items[$epc], 'refused', $user['id'], null, 'refuse');
            }
            foreach ($pickups as $epc) {
                $move($items[$epc], 'collected', $user['id'], null, 'collect');
            }

            // Proof files get a server-made name and live outside the web root.
            $dir = storage_dir() . '/proofs';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $stem = "task-$id-" . bin2hex(random_bytes(8));
            foreach ([[$photo, "$stem.jpg"], [$signature, "$stem.png"]] as [$from, $name]) {
                if (!copy($from, "$dir/$name")) {
                    fail(500, 'storage', 'The proof could not be stored.');
                }
                $saved[] = "$dir/$name";
            }
            q("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP(), photo_path = ?, signature_path = ?,
                    signed_name = ?, issue_note = ? WHERE id = ? AND status = 'open'",
                ["$stem.jpg", "$stem.png", $signedName, $note, $id]);

            // Today is D. The next swap is due on D + n. The unique key on previous_task_id allows only one.
            $nextId = null;
            if ($task['type'] !== 'collection' && $task['contract_status'] === 'active') {
                q("INSERT INTO tasks (type, contract_id, building_id, previous_task_id, due_date) VALUES ('swap', ?, ?, ?, ?)",
                    [$task['contract_id'], $task['building_id'], $id, days_after(today(), $task['swap_interval_days'])]);
                $nextId = (int) db()->lastInsertId();
            }
            return ['task' => task_view($id), 'next_task_id' => $nextId];
        });
    } catch (Throwable $e) {
        // Nothing was saved in the database, so nothing may stay on disk.
        foreach ($saved as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        throw $e;
    }
}

/** A proof file, for the office only. $kind is "photo" or "signature". */
function tasks_proof(int $id, string $kind): FileOut
{
    $task = q('SELECT photo_path, signature_path FROM tasks WHERE id = ?', [$id])->fetch();
    if (!$task) {
        fail(404, 'not_found', 'No such task.');
    }
    $name = $task[$kind . '_path'];
    // basename(): even a tampered path in the database cannot leave the proofs folder.
    $path = $name === null ? null : storage_dir() . '/proofs/' . basename($name);
    if ($path === null || !is_file($path)) {
        fail(404, 'no_proof', 'This task has no proof file.');
    }
    return new FileOut($path, $kind === 'photo' ? 'image/jpeg' : 'image/png');
}
```

In `site/app/routes.php` add these entries at the end of the returned array:

```php
    ['POST', '/tasks/{id}/complete',  [$D], 'tasks_complete'],
    ['GET',  '/tasks/{id}/photo',     [$O], fn ($r, $u, $id) => tasks_proof($id, 'photo')],
    ['GET',  '/tasks/{id}/signature', [$O], fn ($r, $u, $id) => tasks_proof($id, 'signature')],
```

- [ ] **Step 5: Run the test to see it pass**

Run: `php vendor/bin/phpunit tests/TaskCompleteTest.php`
Expected: `OK (13 tests, ...)`.

- [ ] **Step 6: Run all tests and commit**

Run: `php vendor/bin/phpunit`
Expected: `OK (114 tests, ...)`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.10-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.10"/' composer.json
git add site/app/tasks.php site/app/routes.php tests/ApiTestCase.php tests/TaskCompleteTest.php README.md composer.json
git commit -m "Add closing a stop with proof and the next swap task at 0.4.10."
```

---

### Task 12: Testing gate: the whole loop

This task is the blocking **testing gate**. It adds no production code. A failing test here is a finding: fix it in the file that owns the rule, never by weakening the test.

**Files:**
- Test: `tests/LifecycleTest.php`

**Interfaces:**
- Consumes: every route built in Tasks 2 to 11; the test helpers `activateContract()`, `openHandover()`, `scanHandover()`, `proofFile()`, `epcs()`; the constants `ITEM_STATUSES`, `HANDOVERS`, `HELD_STATUSES`.
- Produces: nothing new.

- [ ] **Step 1: Write the test**

Create `tests/LifecycleTest.php`:

```php
<?php
/** The testing gate: one mat round the whole loop through the API, and the lifecycle rules as a table. */
declare(strict_types=1);

final class LifecycleTest extends ApiTestCase
{
    private function qcPass(int ...$mats): void
    {
        $this->loginAs('inventory');
        foreach ($mats as $mat) {
            $this->ok($this->api('POST', '/items/qc', ['source' => 'simulator', 'epc' => $this->epc($mat), 'result' => 'pass']));
        }
    }

    private function handoverA(int ...$mats): void
    {
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, ...$mats);
        $this->scanHandover('driver', $handover, ...$mats);
        $this->ok($this->api('POST', "/handovers/$handover/close"));
    }

    private function closeStop(int $task, array $drops, array $pickups): array
    {
        $this->loginAs('driver');
        return $this->ok($this->api('POST', "/tasks/$task/complete",
            ['source' => 'simulator', 'drops' => $drops, 'pickups' => $pickups, 'refused' => [],
                'signed_name' => 'Mock Contact One', 'issue_note' => null],
            ['photo' => $this->proofFile('jpeg'), 'signature' => $this->proofFile('png')]));
    }

    private function remainingForDriver(): int
    {
        $this->loginAs('inventory');
        return $this->ok($this->api('GET', '/tasks'))['drivers'][0]['needs'][0]['remaining'];
    }

    public function test_a_mat_goes_round_the_whole_loop(): void
    {
        $driver = $this->users['driver'];

        // 1. The office starts contract 1 and gives the delivery to the driver.
        [$delivery] = $this->activateContract(1, 'driver');

        // 2. Inventory checks three mats. The scanner says the driver still needs three.
        $this->qcPass(1, 2, 3);
        $this->assertSame(3, $this->remainingForDriver());

        // 3. Handover A: inventory scans out, the driver scans in.
        $this->handoverA(1, 2, 3);
        $this->assertSame(['out_for_delivery', $driver], [$this->item(1)['status'], $this->item(1)['holder_user_id']]);
        $this->assertSame(0, $this->remainingForDriver());

        // 4. The driver delivers: scan, photo, signature. Today is D.
        $delivered = $this->closeStop($delivery,
            [['zone_id' => 1, 'epcs' => $this->epcs(1, 2)], ['zone_id' => 2, 'epcs' => $this->epcs(3)]], []);
        $swap = $delivered['next_task_id'];
        $this->assertSame(days_after(today(), 14), q('SELECT due_date FROM tasks WHERE id = ?', [$swap])->fetchColumn());
        $this->assertSame('at_customer', $this->item(1)['status']);

        // 5. D + n: the office assigns the swap. Three clean mats go out, the three used ones come back.
        $this->loginAs('office');
        $this->ok($this->api('PATCH', "/tasks/$swap", ['assigned_to' => $driver]));
        $this->qcPass(4, 5, 6);
        $this->handoverA(4, 5, 6);
        $this->closeStop($swap,
            [['zone_id' => 1, 'epcs' => $this->epcs(4, 5)], ['zone_id' => 2, 'epcs' => $this->epcs(6)]], $this->epcs(1, 2, 3));
        $this->assertSame(['collected', $driver], [$this->item(1)['status'], $this->item(1)['holder_user_id']]);
        $this->assertSame('at_customer', $this->item(4)['status']);

        // 6. Handover B: the driver scans out, washing scans in. The mat is in washing. No wash is counted yet.
        $b = $this->openHandover('driver', 'driver_to_washing', 'washing');
        $this->scanHandover('driver', $b, 1, 2, 3);
        $this->scanHandover('washing', $b, 1, 2, 3);
        $this->ok($this->api('POST', "/handovers/$b/close"));
        $this->assertSame(['in_washing', null, 0, null],
            [$this->item(1)['status'], $this->item(1)['holder_user_id'], $this->item(1)['wash_count'], $this->item(1)['current_wash_date']]);

        // 7. After washing: the washing house manager scans the mats out, twice by mistake.
        //    That scan confirms they are ready to use and counts one wash. No inventory scan is needed.
        $c = $this->openHandover('washing', 'washing_to_inventory', 'inventory');
        $this->scanHandover('washing', $c, 1, 2, 3);
        $this->scanHandover('washing', $c, 1, 2, 3);
        $this->ok($this->api('POST', "/handovers/$c/close"));
        $this->assertSame(['ready', 1, today(), null],
            [$this->item(1)['status'], $this->item(1)['wash_count'], $this->item(1)['current_wash_date'], $this->item(1)['previous_wash_date']]);

        // 8. A washed mat must pass the quality check again before it can go out.
        $again = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->assertSame('rejected', $this->scanHandover('inventory', $again, 1)['results'][0]['result']);

        // 9. The office sees every step in the mat history, and no handover issue.
        $this->loginAs('office');
        $history = $this->ok($this->api('GET', "/items/{$this->item(1)['id']}"))['events'];
        $this->assertSame(['qc', 'handover_give', 'handover_receive', 'deliver', 'collect', 'handover_give', 'handover_receive', 'handover_give'],
            array_column($history, 'event_type'));
        $this->assertSame(['ready', 'qc_passed', 'qc_passed', 'out_for_delivery', 'at_customer', 'collected', 'collected', 'in_washing'],
            array_column($history, 'from_status'));
        $this->assertSame(['qc_passed', 'qc_passed', 'out_for_delivery', 'at_customer', 'collected', 'collected', 'in_washing', 'ready'],
            array_column($history, 'to_status'));
        $this->assertSame([], $this->ok($this->api('GET', '/handovers?issues=1'))['issues']);
    }

    public function test_no_handover_takes_a_mat_in_a_status_it_is_not_meant_for(): void
    {
        $people = [
            'inventory_to_driver' => ['inventory', 'driver'],
            'driver_to_washing' => ['driver', 'washing'],
            'washing_to_inventory' => ['washing', 'inventory'],
            'driver_to_inventory' => ['driver', 'inventory'],
            'inventory_to_washing' => ['inventory', 'washing'],
        ];
        foreach ($people as $type => [$giver, $receiver]) {
            $handover = $this->openHandover($giver, $type, $receiver);
            foreach (ITEM_STATUSES as $status) {
                if ($status === HANDOVERS[$type]['from']) {
                    continue;
                }
                $this->setItems([1], $status,
                    in_array($status, HELD_STATUSES, true) ? $this->users['driver'] : null,
                    $status === 'at_customer' ? 1 : null);
                $out = $this->scanHandover($giver, $handover, 1);
                $this->assertSame('rejected', $out['results'][0]['result'], "$type must not take a mat that is $status");
                $this->assertSame($status, $this->item(1)['status']);
            }
        }
        $this->assertSame(0, (int) q('SELECT COUNT(*) FROM handover_items')->fetchColumn());
        $this->assertSame(0, (int) q('SELECT COUNT(*) FROM item_events')->fetchColumn());
    }

    public function test_the_database_itself_refuses_a_closed_stop_without_proof(): void
    {
        [$task] = $this->activateContract(1);
        $this->expectException(PDOException::class);
        q("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP() WHERE id = ?", [$task]);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `php vendor/bin/phpunit tests/LifecycleTest.php`
Expected: `OK (3 tests, ...)`.

If a test fails, the failure names a broken rule. Find the owning file (`handovers.php` for a handover rule, `tasks.php` for a stop, `items.php` for the quality check), fix it there with its own failing test first, and run this file again. Do not change an assertion to match the code. If the server answered `500`, the reason is in `php-error.log` inside the folder that `php -r "echo sys_get_temp_dir();"` prints, under `laundry-test-storage`.

- [ ] **Step 3: Run the full suite twice**

Run: `php vendor/bin/phpunit && php vendor/bin/phpunit`
Expected: `OK (117 tests, ...)` both times. Two runs in a row prove that no test depends on what an earlier run left in the database or in the storage folder.

- [ ] **Step 4: Check every changed PHP file for syntax**

Run: `for f in site/app/*.php site/bin/*.php site/www/*.php tests/*.php; do php -l "$f" | grep -v "No syntax errors"; done; echo "lint done"`
Expected: only `lint done`.

- [ ] **Step 5: Commit**

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.11-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.11"/' composer.json
git add tests/LifecycleTest.php README.md composer.json
git commit -m "Add whole-loop lifecycle tests as the testing gate at 0.4.11."
```

---

### Task 13: Security gate

This task is the blocking **security gate**. It reviews the whole branch against the Security section of ARCHITECTURE.md. It builds no feature. A finding is fixed in the file that owns it, with a test.

**Files:**
- Test: `tests/SecurityTest.php`
- Modify: only files in which a finding is fixed

**Interfaces:**
- Consumes: `site/app/routes.php` (read directly as data), every route, `activateContract()`.
- Produces: nothing new.

- [ ] **Step 1: Write the test**

Create `tests/SecurityTest.php`:

```php
<?php
/** The security gate: every route needs a login and the right role, and hostile input stays data. */
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

final class SecurityTest extends ApiTestCase
{
    /** Every route that needs a login, read from the route table, so a new route is covered the day it is added. */
    public static function guardedRoutes(): iterable
    {
        foreach (require __DIR__ . '/../site/app/routes.php' as [$method, $pattern, $roles]) {
            if ($roles !== null) {
                yield "$method $pattern" => [$method, preg_replace('/\{\w+\}/', '1', $pattern), $roles];
            }
        }
    }

    #[DataProvider('guardedRoutes')]
    public function test_a_route_needs_a_login_and_the_right_role(string $method, string $path, array $roles): void
    {
        $this->assertError(401, 'not_logged_in', $this->api($method, $path));
        foreach (['office', 'inventory', 'driver', 'washing', 'outbound'] as $role) {
            if (in_array($role, $roles, true)) {
                continue;
            }
            $this->loginAs($role);
            $this->assertError(403, 'forbidden', $this->api($method, $path));
        }
    }

    public function test_only_login_is_open_without_a_session(): void
    {
        $open = [];
        foreach (require __DIR__ . '/../site/app/routes.php' as [$method, $pattern, $roles]) {
            if ($roles === null) {
                $open[] = "$method $pattern";
            }
        }
        $this->assertSame(['POST /auth/login'], $open);
    }

    public function test_sql_in_a_login_or_a_search_stays_text(): void
    {
        $this->assertError(401, 'bad_login',
            $this->api('POST', '/auth/login', ['username' => "' OR '1'='1", 'password' => "' OR '1'='1"]));
        $this->loginAs('office');
        $this->assertSame(0, $this->ok($this->api('GET', '/items?q=' . urlencode("' OR 1=1 -- ")))['total']);
        $this->assertSame(40, (int) q('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function test_a_tampered_proof_path_cannot_leave_the_proofs_folder(): void
    {
        [$task] = $this->activateContract(1);
        q("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP(), photo_path = '../../../site/.env', signature_path = 'x.png' WHERE id = ?", [$task]);
        $this->loginAs('office');
        $this->assertError(404, 'no_proof', $this->api('GET', "/tasks/$task/photo"));
    }

    public function test_a_password_is_stored_only_as_a_hash(): void
    {
        $this->loginAs('office');
        $out = $this->ok($this->api('POST', '/users',
            ['name' => 'Dana', 'username' => 'dana', 'password' => 'long-password-1', 'role' => 'driver']));
        $this->assertStringNotContainsString('long-password-1', json_encode($out));
        $hash = q("SELECT password_hash FROM users WHERE username = 'dana'")->fetchColumn();
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertTrue(password_verify('long-password-1', $hash));
    }
}
```

- [ ] **Step 2: Run the test**

Run: `php vendor/bin/phpunit tests/SecurityTest.php`
Expected: `OK (50 tests, ...)`: 46 route cases and 4 other tests. If the number of route cases differs, a route was added or left out in an earlier task: compare `site/app/routes.php` with the route lists in Tasks 2 to 11 before going on.

A failing case is a security finding. Fix it in the file that owns the route.

- [ ] **Step 3: Check that no request value reaches SQL text**

Run: `grep -nE '\$[a-zA-Z_]+' site/app/*.php | grep -E 'q\(|SELECT|INSERT|UPDATE|DELETE|WHERE|ORDER BY' | grep -vE '^\S+:\s*(//|\*)'`

Read every line it prints. A PHP variable inside SQL text is allowed only in this list, where it holds a constant from the code or text built from constants:

| File | Variable in SQL text | Why it is safe |
|---|---|---|
| `refdata.php` | `$table`, `$parent`, `$columns`, `$sets` | Names from the `REFDATA` constant |
| `users.php` | `$sets` | Column names written in the function |
| `items.php` | `$sqlWhere` | Fixed text with `?` marks |
| `handovers.php` | `$where`, `$wash` | Fixed text with `?` marks |
| `scan.php` | `$sql` | `ITEM_SELECT` plus `?` marks |
| `tasks.php`, `items.php`, `handovers.php`, `scan.php` | `marks(...)`, `implode(' AND ', $where)` | `?` marks and fixed text |

Anything else that puts a variable into SQL text is a finding: replace it with a `?` parameter.

- [ ] **Step 4: Check secrets and file placement**

Run:

```bash
git ls-files | grep -E '(^|/)\.env$|^site/storage/|composer\.phar|^vendor/' ; echo "tracked-files check done"
git grep -nE 'DB_PASS' -- site tests | grep -v "cfg('DB_PASS')" | grep -v '.env.example' ; echo "password check done"
ls site/www
```

Expected: the first two commands print only their `done` line. `ls site/www` shows exactly `index.php` (plus the dot files `.htaccess` and `.user.ini`, which `ls` hides). No PHP file with business code, no `storage`, no `.env` inside the web root.

- [ ] **Step 5: Check the response headers and the cookie over HTTP**

Start the server in the background (`run_in_background: true`):

```bash
DB_NAME=laundry_test APP_ENV=production php -S 127.0.0.1:8080 site/www/index.php
```

Run:

```bash
printf 'Sec Office\nsec.office\nsec-password-12\n' | DB_NAME=laundry_test php site/bin/create-office-user.php
curl -s -i -H 'Content-Type: application/json' -d '{"username":"sec.office","password":"sec-password-12"}' http://127.0.0.1:8080/api/v1/auth/login | grep -iE '^(HTTP|set-cookie|x-content-type-options|cache-control|strict-transport-security)'
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/app/db.php
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/.env
```

Expected:

```
HTTP/1.1 200 OK
X-Content-Type-Options: nosniff
Cache-Control: no-store
Strict-Transport-Security: max-age=31536000
Set-Cookie: laundry=...; path=/; secure; HttpOnly; SameSite=Lax
404
404
```

Stop the background server.

- [ ] **Step 6: Walk the Security section of ARCHITECTURE.md**

For each line, confirm the place in the code and the test. Write the table, with any finding, into the task report.

| Security rule in ARCHITECTURE.md | Where it is enforced | Test |
|---|---|---|
| HTTPS only | `site/www/.htaccess` redirect, `Strict-Transport-Security` and `secure` cookie in `index.php` when `APP_ENV=production` | Step 5 |
| Passwords stored as a hash | `user_insert()`, `users_update()` | `SecurityTest::test_a_password_is_stored_only_as_a_hash` |
| Login limited per username and address | `auth_login()`, `throttle_recent()` | `AuthTest::test_five_failed_tries_block_the_sixth_even_with_the_right_password` |
| Cookie `HttpOnly`, `Secure`, `SameSite=Lax` | `index.php` | Step 5 |
| CSRF token on every changing request | `route()` | `AuthTest::test_a_changing_request_needs_the_csrf_token` |
| Session id renewed at login | `auth_login()` calls `session_regenerate_id(true)` | Read the code |
| Switched-off user is out at his next call | `current_user()` | `AuthTest::test_a_user_switched_off_while_logged_in_is_logged_out_at_his_next_call` |
| Role checked on the server for every call | `route()` | `SecurityTest::test_a_route_needs_a_login_and_the_right_role` |
| Ownership checked on the server | `tasks_complete()`, `handovers_scan()`, `handovers_get()`, `handovers_close()`, `items_list()` | `TaskCompleteTest::test_only_the_assigned_driver_closes_a_stop_and_only_once`, `HandoverScanTest::test_only_the_two_people_of_a_handover_may_scan_into_it`, `ItemsTest::test_each_role_sees_only_its_own_mats` |
| All SQL uses prepared statements | `q()` | Step 3, `SecurityTest::test_sql_in_a_login_or_a_search_stays_text` |
| Uploads checked by content, size limit, server-made name, outside the web root, served only to the office | `proof_in()`, `tasks_complete()`, `tasks_proof()` | `TaskCompleteTest` (three proof tests), `SecurityTest::test_a_tampered_proof_path_cannot_leave_the_proofs_folder` |
| Secrets in `.env`, never in git | `.gitignore`, `cfg()` | Step 4 |
| Simulator off in production use | `simulator_on()`, `sim_items()`, `source_in()` | `ScanTest::test_switching_the_simulator_off_closes_its_list_and_refuses_its_scans` |
| Corrections leave a trace | `items_correct()` | `ItemsTest::test_a_correction_moves_the_mat_and_leaves_a_trace` |
| A lookup does not show the customer | `item_view()` | `ScanTest::test_lookup_tells_what_each_mat_is_and_changes_nothing` |
| Personal data: how long proofs are kept | Not built: a question for Proti | Report as open |

The gate passes when there is no unresolved finding of critical or high severity. List lower findings in the report.

- [ ] **Step 7: Run all tests and commit**

Run: `php vendor/bin/phpunit`
Expected: `OK (167 tests, ...)`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.12-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.12"/' composer.json
git add tests/SecurityTest.php README.md composer.json
git add -u site
git commit -m "Add role matrix and security tests as the security gate at 0.4.12."
```

---

### Task 14: Publish the API contract

The docs pass runs after both gates, so the documents describe what was built. The updated endpoint table in ARCHITECTURE.md is the **API contract** that plans 2 and 3 (the React app) build against.

**Files:**
- Modify: `ARCHITECTURE.md` (version line, endpoint section, settings table, first login, wash count, time zone)
- Modify: `POC.md`, `FEATURES.md`, `FLOW.md` (wash count at Handover C)
- Modify: `db/schema.sql` (two comment lines only, no table or column)
- Modify: `README.md` (status note, diagrams, new section, version badge)
- Modify: `composer.json` (version)

**Interfaces:**
- Consumes: the routes in `site/app/routes.php` as built.
- Produces: the published API contract.

`ARCHITECTURE.md` had one uncommitted edit before this plan was written (the "Mat history" cell for the washing house manager says `Yes`). If `git status` still shows it as modified at the start of this task, that edit is the user's: keep it and commit it together with this task's changes.

- [ ] **Step 1: Update the endpoint section of ARCHITECTURE.md**

In `ARCHITECTURE.md`, change the version line at the top from `Version: 0.3.0` to `Version: 0.4.0`.

In the section `### Endpoints`, replace the whole table (from the line `| Method and path | Who | Does |` down to the line `| \`GET /sim/items\` | I D W X | Simulator only, see below |`) with:

```markdown
| Method and path | Who | Does |
|---|---|---|
| `POST /auth/login` | all | `{username, password}` → `{user, csrf_token}` |
| `POST /auth/logout` | all | Ends the session |
| `GET /auth/me` | all | `{user, csrf_token}` of the logged-in user |
| `GET, POST /users` · `PATCH /users/{id}` | O | Manage users. No delete, only switch off. A user cannot switch off or demote himself. |
| `GET, POST /areas` · `PATCH /areas/{id}` | O | Manage areas |
| `GET, POST /customers` · `PATCH /customers/{id}` | O | Manage customers |
| `GET, POST /customers/{id}/buildings` · `PATCH /buildings/{id}` | O | Manage buildings |
| `GET, POST /buildings/{id}/zones` · `PATCH /zones/{id}` | O | Manage zones |
| `GET /item-types` | O I | List mat types. Inventory needs it to register a mat. |
| `POST /item-types` · `PATCH /item-types/{id}` | O | Manage mat types |
| `GET, POST /contracts` · `PATCH /contracts/{id}` | O | Manage contracts and their lines. Lines and start date only while draft. |
| `POST /contracts/{id}/activate` | O | Creates the delivery tasks → `{contract, task_ids}` |
| `POST /contracts/{id}/end` | O | Ends the contract, cancels open swap tasks |
| `GET /items` | O I D W | Mat list. Filters: `status`, `type`, `q`, `page`. The server narrows it to what the role may see. |
| `GET /items/{id}` | O W | One mat with its history. Washing gets the events without note, task and zone. |
| `POST /items` | I | Register a mat |
| `POST /items/ready` | I | `{source, reads}` → marks mats ready |
| `POST /items/qc` | I | `{source, epc, result, note}` → pass, rewash, repair or scrap |
| `POST /items/{id}/correct` | O | `{status, holder_user_id, zone_id, note}` → correction, note required |
| `POST /scan/lookup` | I D W X | `{source, reads}` → what each mat is. Changes nothing. |
| `GET /tasks` | O D I X | Office: all, with filters `status`, `type`, `assigned_to`. Driver: his own open tasks with zones, expected mats and mats in the building. Inventory and outbound: mats needed per driver. |
| `POST /tasks` | O | `{contract_id, building_id, due_date}` → creates a collection task |
| `PATCH /tasks/{id}` | O | Assign a driver, move the due date, cancel |
| `POST /tasks/{id}/complete` | D | Close a stop, see above → `{task, next_task_id}` |
| `GET /tasks/{id}/photo`, `/signature` | O | The proof files |
| `GET /handovers` | all | Office: all, `?issues=1` for the issue list. Others: the handovers they give or receive. |
| `GET /handovers/{id}` | all | One handover with its pile. Others than the office: only their own. |
| `GET /handover-receivers?type=...` | I D W X | The people the caller can pick as receiver for that handover type |
| `POST /handovers` | I D W X | `{type, receiver_id}` → opens a handover as giver. An already open one between the same two people comes back. |
| `POST /handovers/{id}/scan` | I D W X | `{source, reads}` → counts as give or receive, depending on who calls |
| `POST /handovers/{id}/close` | all | Done. The receiver closes; for Handover C the giver may too; the office may close any. |
| `POST /handovers/{id}/items/{itemId}/resolve` | O | `{note}` → marks a handover issue as handled |
| `GET /sim/items` | I D W X | Simulator only, see below |

Every changing request (everything except `GET` and the login) must carry the header `X-CSRF-Token` with the `csrf_token` from the login or from `GET /auth/me`.
```

Below the error example in the same section, replace the line that starts with `` `401` not logged in `` with:

```markdown
`401` not logged in · `403` wrong role, not yours, or bad CSRF token · `404` not found · `405` wrong method · `409` already done, closed, duplicate or wrong status · `422` input not valid · `429` too many login tries.
```

- [ ] **Step 2: Update the settings and the first login in ARCHITECTURE.md**

In the table under "Settings the backend reads from `.env`", add this row after the `SCANNER_SIMULATOR` row:

```markdown
| `STORAGE_DIR` | Optional. Folder for proof files, sessions and login throttle files. Default: `site/storage`. |
```

In the section `### First login`, replace the paragraph with:

```markdown
No user is seeded, because a password must not sit in a file in git. After loading the schema, the first office user is created once on the server:

    php site/bin/create-office-user.php

The command asks for the name, the username and the password (10 to 72 characters). That user then adds everyone else in the office console.
```

In the Security section, replace the sentence `Login is limited to a few tries per minute per username and address.` with `Login is limited to 5 failed tries per minute per username and address.`

In the section `## Hosting at TransIP`, add this paragraph directly after the paragraph that starts with `On every database connection the backend runs`:

```markdown
Times are stored and sent in UTC, not in Dutch time. Dutch time repeats one hour every autumn and skips one every spring, so two scans in that night could not be put in order. A named time zone also needs time zone tables that a shared database often does not have. The screens show every time in Dutch time. Calendar days (due dates, wash dates) are Dutch calendar days.
```

- [ ] **Step 3: Move the wash count to Handover C in the documents**

Decided while this plan was reviewed: after washing, the washing house manager's scan is required. It confirms the mats are ready to use and counts one washing cycle. Receiving used mats in Handover B counts nothing. Make these replacements. In each, "Replace" is the exact text now in the file.

`ARCHITECTURE.md`, in the table under "What moves a mat from one status to the next":

Replace:

```markdown
| Handover B complete | Driver gives, washing receives | `collected` | `in_washing` | Previous wash date = old current date, current wash date = today, wash count + 1 |
| Handover C | Washing gives | `in_washing` | `ready` | Inventory's scan is optional and changes nothing |
```

With:

```markdown
| Handover B complete | Driver gives, washing receives | `collected` | `in_washing` | Holder cleared. No wash is counted yet |
| Handover C | Washing gives, after washing | `in_washing` | `ready` | Previous wash date = old current date, current wash date = today, wash count + 1. Inventory's scan is optional and changes nothing |
```

Replace:

```markdown
| Rewash handover complete | Inventory gives, washing receives | `rewash` | `in_washing` | Wash dates and count as in Handover B |
```

With:

```markdown
| Rewash handover complete | Inventory gives, washing receives | `rewash` | `in_washing` | The wash is counted when the mat leaves washing in Handover C |
```

`ARCHITECTURE.md`, section "Handovers". Replace:

```markdown
For Handover C step 2 is optional: the mat becomes `ready` when the washing house manager scans it out.
```

With:

```markdown
For Handover C step 2 is optional: the mat becomes `ready` when the washing house manager scans it out. That scan is required. It confirms the mat is washed and ready to use, and it is the scan that counts the wash.
```

`ARCHITECTURE.md`, section "Assumptions to confirm". Replace:

```markdown
| Rewash mats go to washing through a two-sided handover, and that wash is counted | FLOW shows the arrow but no rule |
```

With:

```markdown
| Rewash mats go to washing through a two-sided handover. The wash is counted when the mat leaves washing | FLOW shows the arrow but no rule |
```

`POC.md`: change `Version: 0.3.0` to `Version: 0.3.1`. Replace:

```markdown
7. **Handover C:** washing scans the washed mats out, inventory scans them in. The second scan is optional. The mat is ready to use again.
```

With:

```markdown
7. **Handover C:** after washing, the washing house manager scans the washed mats out. This scan is required: it confirms the mats are ready to use and counts one washing cycle. Inventory scans them in; that second scan is optional.
```

Replace:

```markdown
The washing house manager's scan in Handover B moves the current washing date to the previous washing date, sets the current washing date to today, and adds 1 to the cycle count. Scanning the same handover again adds nothing.
```

With:

```markdown
The washing house manager's scan after washing, in Handover C, moves the current washing date to the previous washing date, sets the current washing date to today, and adds 1 to the cycle count. Scanning the same handover again adds nothing.
```

`FEATURES.md`: change `Version: 0.2.1` to `Version: 0.2.2`. Replace:

```markdown
- **Receive:** scan in the mats the delivery guy hands over. This scan moves the old "current washing date" to "previous washing date", sets the current washing date to today, and adds 1 to the washing cycle count. Scanning the same handover again adds nothing.
```

With:

```markdown
- **Receive:** scan in the mats the delivery guy hands over.
```

Replace:

```markdown
- **Hand over to inventory:** scan out the washed mats.
```

With:

```markdown
- **Hand over to inventory:** after washing, scan out the washed mats. This scan is required. It confirms the mats are ready to use, moves the old "current washing date" to "previous washing date", sets the current washing date to today, and adds 1 to the washing cycle count. Scanning the same handover again adds nothing.
```

`FLOW.md`: change `Version: 0.1.6` to `Version: 0.1.7`. Replace:

```markdown
- Wash count goes up once, at the washing manager's scan in Handover B. A re-scan adds nothing.
```

With:

```markdown
- Wash count goes up once, at the washing manager's scan after washing, in Handover C. That scan is required. A re-scan adds nothing.
```

In the Mermaid source in `FLOW.md`, replace:

```
        INW["In washing<br/>previous date = old current date<br/>current date = today<br/>cycle +1"]
```

With:

```
        INW["In washing"]
```

And replace:

```
    WASH -- "Handover C<br/>washing scans out<br/>inventory scans in<br/>(optional)" --> BACK
```

With:

```
    WASH -- "Handover C<br/>washing scans out (required)<br/>previous date = old current date<br/>current date = today, cycle +1<br/>inventory scans in (optional)" --> BACK
```

Directly under the line `![Proposed flow](flow-0.1.6.png)` in `FLOW.md`, add:

```markdown
> The picture still shows the wash count at Handover B. It moves to Handover C; the Mermaid source below is right. Render the picture again before it is shown to the client.
```

`README.md`, in the "Mat lifecycle" diagram. Replace:

```
        INW["in_washing<br/>previous date = old current date<br/>current date = today<br/>wash count +1"]
```

With:

```
        INW["in_washing"]
```

Replace:

```
    INW == "HANDOVER C<br/>washing scans out, inventory scan optional" ==> READY
```

With:

```
    INW == "HANDOVER C<br/>washing scans out after washing: wash count +1<br/>inventory scan optional" ==> READY
```

`README.md`, in the "How a handover works" diagram. Replace:

```
    Note over S: A mat scanned by both sides moves at once.<br/>Handover B also adds 1 to the wash count.
```

With:

```
    Note over S: A mat scanned by both sides moves at once.<br/>In Handover C the washing scan alone makes the mat ready and adds 1 to the wash count.
```

`db/schema.sql`, comments above `CREATE TABLE handovers` only. Replace:

```sql
--   driver_to_washing     Handover B (adds 1 to the wash count)
--   washing_to_inventory  Handover C (receiver scan optional)
```

With:

```sql
--   driver_to_washing     Handover B
--   washing_to_inventory  Handover C (the giver's scan adds 1 to the wash count; receiver scan optional)
```

Run: `git diff --stat db/schema.sql`
Expected: `1 file changed, 2 insertions(+), 2 deletions(-)`. Any other change to that file is a mistake: undo it.

- [ ] **Step 4: Update README.md**

Replace the NOTE block under `## What it is` with:

```markdown
> [!NOTE]
> **Backend built, screens not yet.** The PHP API under `/api/v1` is built and tested. The web app (office console and scanner simulator) is the next step. The Chainway C72 handheld is simulated, and all mats are mock mats.
```

Add this section directly before `## Versions`:

````markdown
## Run the API locally

Needs PHP 8.3+ and MariaDB or MySQL. Create two databases, `laundry` and `laundry_test`, and copy `site/.env.example` to `site/.env` with your database password.

```bash
php composer.phar install          # PHPUnit, for the tests only
php vendor/bin/phpunit             # runs against laundry_test and wipes it every time
mysql -u USER -p laundry < db/schema.sql
mysql -u USER -p laundry < db/seed.sql
php site/bin/create-office-user.php
php -S 127.0.0.1:8080 site/www/index.php
```

The API then answers at `http://127.0.0.1:8080/api/v1`. The endpoints are listed in [ARCHITECTURE.md](ARCHITECTURE.md#endpoints).

To put it on the server, upload the `site` folder and point the web root at `site/www`.
````

- [ ] **Step 5: Check the documents against the code**

Run:

```bash
php -r '
$doc = file_get_contents("ARCHITECTURE.md");
foreach (require "site/app/routes.php" as [$method, $pattern]) {
    $last = substr($pattern, strrpos($pattern, "/"));
    if (!str_contains($doc, $pattern) && !str_contains($doc, $last)) echo "NOT IN ARCHITECTURE.md: $method $pattern\n";
}
echo "contract check done\n";'
```

Expected: only `contract check done`. A line `NOT IN ARCHITECTURE.md` names a route the endpoint table misses: add it to the table.

Run: `php vendor/bin/phpunit`
Expected: `OK (167 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.5.0-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.5.0"/' composer.json
git add ARCHITECTURE.md POC.md FEATURES.md FLOW.md README.md db/schema.sql composer.json
git commit -m "Publish the API contract and move the wash count to Handover C in the documents at 0.5.0."
```

Do not tag and do not push. Tagging `v0.5.0` and merging are the user's decision when the branch is finished.

- [ ] **Step 7: Emit the handoff**

Put these blocks in the final report, with the two "Open risks" lines completed from the gate reports of Tasks 12 and 13.

```
### HANDOFF — backend
- Produced:      site/app/*.php, site/www/{index.php,.htaccess,.user.ini}, site/bin/create-office-user.php, site/.env.example
- Contract:      the endpoint table and the "How data flows" section of ARCHITECTURE.md 0.4.0. All paths under /api/v1, JSON in and out, session cookie "laundry", header X-CSRF-Token on every changing request, errors as {"error": {"code", "message"}}.
- Assumptions:   the frontend never decides whether a mat may move; it shows what the API answers. Lists are small enough to load whole, except GET /items, which pages by 50. Every time in an answer is UTC ("2026-10-12 21:03:11"); the screens show it in Dutch time. Dates (due_date, wash dates) are Dutch calendar days and are shown as they are.
- Do NOT change: paths, field names, the result words found / not_on_list / unknown / rejected, the error codes, db/schema.sql.
- Open risks:    <from the Task 12 and Task 13 reports>. The picture flow-0.1.6.png still shows the wash count at Handover B and must be rendered again.

### HANDOFF — testing
- Produced:      tests/*.php, phpunit.xml. 167 tests, all against a real MariaDB.
- Contract:      php vendor/bin/phpunit is green. It wipes the database named in DB_NAME, which must end in _test.
- Assumptions:   MariaDB or MySQL is running locally with the laundry_test database.
- Do NOT change: an assertion to make failing code pass.
- Open risks:    no test runs two requests at the same moment; row locks and conditional updates are reasoned, not load-tested. index.php is checked by hand with curl, not by PHPUnit.

### HANDOFF — security
- Produced:      tests/SecurityTest.php and the review table of Task 13.
- Contract:      no unresolved critical or high finding.
- Assumptions:   HTTPS is switched on at TransIP, and site/www is the web root.
- Do NOT change: SCANNER_SIMULATOR must be false before real mats are registered.
- Open risks:    <from the Task 13 report>. Still to check at TransIP: database engine and version, PHP version, that .user.ini is honoured, and that the HTTPS redirect in .htaccess works behind TransIP's proxy.
```

---

## Not in this plan

- The React office console and scanner simulator (plans 2 and 3).
- Token login for the Chainway C72. The session cookie is enough while the scanner is the web app.
- Deployment to TransIP. The `site` folder is ready to upload; doing it needs the hosting details.
- Cleaning up old throttle files, session files and proof files. How long proofs are kept is a question for Proti.
- Paging for handovers and tasks. Both lists return the newest rows only (100 and 200).
