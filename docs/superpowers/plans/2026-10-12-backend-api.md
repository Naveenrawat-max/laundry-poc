# Backend API Implementation Plan (Laravel)

Version: 0.3.0

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the JSON API under `/api/v1` that holds every rule of the Proti mat tracking POC, as a Laravel application: login, roles, mat lifecycle, handovers, contracts, tasks and proof files.

**Architecture:** One Laravel 13 application in `site/`, with `site/public/` as the web root. Laravel does the plumbing: routing, login session, CSRF check, login throttle, input validation, file storage, the command line and the test runner. This plan writes only the rules: thin controllers that run plain SQL through Laravel's `DB` facade. Every status change is a conditional `UPDATE ... WHERE status = <old status>` inside `DB::transaction()` plus one `item_events` row, which is what makes every scan safe to send twice. Every setting that differs per machine comes from `.env`; nothing is hardcoded.

**Tech Stack:** PHP 8.3+ (8.4.19 on the dev machine), Laravel 13, Laravel Sanctum (session cookie for the web app now, tokens for the handheld later), MariaDB or MySQL at TransIP, PHPUnit 12 through `php artisan test`, Composer.

**Spec:** [ARCHITECTURE.md](../../../ARCHITECTURE.md), with [POC.md](../../../POC.md), [FEATURES.md](../../../FEATURES.md), [FLOW.md](../../../FLOW.md) and [db/schema.sql](../../../db/schema.sql).

**Checked, and not checked:** this plan is a rewrite of the plain-PHP plan (commit `ca51bb5`, still in git as the fallback). The **rules and the test cases** come from that plan, where all 167 tests passed on PHP 8.4.19 with MariaDB 11.4.13. The **Laravel code in this plan has not been run.** Facts about the framework (Laravel 13.35 skeleton, what `php artisan install:api` creates, the file layout, the default settings) were looked up in a throwaway install; the controllers, the middleware and the test harness were written from that, not executed. So a step that fails during execution may be a mistake in this plan's Laravel plumbing. Fix the code, never a test's assertion: the assertions are the checked part.

## Scope

This is plan 1 of 3. The whole build is too big for one plan, and the frontend may only be built against a published API contract.

| Plan | Builds | Status |
|---|---|---|
| 1. Backend API (this file) | Everything under `/api/v1`, its tests, the security review, the published API contract | Written |
| 2. Office console | React `/office` area against the contract from plan 1 | To write after plan 1 is built |
| 3. Scanner simulator | React `/scanner` area with the pile panel | To write after plan 1 is built |

The database is already designed (`db/schema.sql`, 13 tables). This plan changes no table and no column of those 13. Laravel adds two small tables of its own: `migrations` (which migrations ran) and `personal_access_tokens` (empty until the handheld gets token login).

## Changes to the spec decided while planning

Where this section and the documents disagree, this section wins. Task 14 brings the documents in line.

| # | Decision | Was | Is now |
|---|---|---|---|
| 1 | When a wash is counted | At the washing house manager's scan when he **receives** used mats (Handover B) | At the washing house manager's scan **after washing**, when he scans the washed mats out (Handover C). That scan is required. It confirms the mats are ready to use, moves the wash dates and adds 1 to the wash count. Receiving in Handover B counts nothing. A rewash is counted the same way, when the mat leaves washing |
| 2 | Time zone of stored times | UTC | Stays UTC, now with the reason written down: Dutch time repeats one hour every autumn, so two scans in that night could not be put in order, and a named time zone needs tables a shared database often lacks. Every screen shows Dutch time. Calendar days (due dates, wash dates) are Dutch days already |
| 3 | What the backend is built with | Plain PHP files, no framework | Laravel 13 with Sanctum. The security plumbing (login, session, CSRF, throttle, validation, uploads) is the framework's, kept up to date by its maintainers, and token login for the handheld is already there. The rules, the database and the API paths stay the same |

## What Laravel replaces, and what stays

| The plain-PHP plan wrote by hand | Now |
|---|---|
| Router and role check (`kernel.php`, `routes.php`) | `site/routes/api.php` and one small middleware, `role` |
| Login, session, CSRF token (`auth.php`, `index.php`) | Sanctum in cookie mode and Laravel's session and forgery check |
| Login throttle in files | Laravel's `RateLimiter` |
| Input readers (`str_in`, `int_in`, ...) | Laravel validation rules |
| Upload checks and proof storage | `UploadedFile` and the `Storage` disk `local` |
| `bin/create-office-user.php` | The command `php artisan laundry:office-user` |
| `cfg()` and `ENV_FILE` | Laravel's `.env`, and `.env.testing` for the tests |
| Own test harness | `php artisan test` |
| **The rules:** two-scan handover, wash count, closing a stop, contract activation, the next swap | **Unchanged.** Same SQL, same checks, same test cases |

## Prerequisites (human, once, before Task 1)

No database is installed on the development machine. Development, tests and demo data all use databases at TransIP. An agent cannot do these steps: they need the TransIP control panel, and a database password must never pass through an agent.

**First confirm three things in the TransIP control panel.** TransIP's own guide ([installing Laravel on a web hosting package](https://www.transip.eu/knowledgebase/installing-laravel-on-a-web-hosting-package)) says all three are there, but the package decides.

1. The web root (DocumentRoot) of the site can be set to a subfolder. Laravel needs it to point at `site/public`.
2. SSH access is on. Without it `composer` and `php artisan` cannot run on the server.
3. PHP 8.3 or newer can be selected.

If number 1 or 3 is not possible, stop: use the plain-PHP plan instead (commit `ca51bb5`), which was shaped for exactly that kind of hosting. If only number 2 is missing, stop and say so: the first office user and the database set-up then need another way.

**Then set up the two databases.**

1. In the TransIP control panel create **two** databases: one for the real data and one for the tests.
   The tests drop every table of their database at the start of every run. They must never share a database with real data.
2. Switch on external access for the **test** database, so the tests can reach it from the development machine. Leave the real database reachable from the hosting only.
3. On the development machine create the file `site/.env.testing` (create the `site` folder if it is not there yet) with the details of the **test** database. Put the password in double quotes, and leave `APP_KEY=` empty: Task 1 fills it in.

   ```ini
   APP_NAME=Laundry
   APP_ENV=testing
   APP_KEY=
   APP_DEBUG=true
   APP_URL=http://127.0.0.1:8000
   DB_CONNECTION=mysql
   DB_HOST=the host name TransIP shows for the test database
   DB_PORT=3306
   DB_DATABASE=the name of the test database
   DB_USERNAME=the user of the test database
   DB_PASSWORD="THE-PASSWORD"
   SCANNER_SIMULATOR=true
   SESSION_DRIVER=file
   SESSION_LIFETIME=720
   SESSION_SECURE_COOKIE=false
   CACHE_STORE=file
   QUEUE_CONNECTION=sync
   FILESYSTEM_DISK=local
   ```

4. The file `site/.env`, with the details of the real database, is made on the server when the site is put there (see "Put it on TransIP" in Task 14). It is not needed on the development machine, and it must not exist there with the test database in it: the tests refuse to run when `.env` and `.env.testing` name the same database.

Two more things to confirm in the control panel before Task 1. If either is not possible, stop and say so: the tests then have no safe place to run.

- The hosting package allows a second database.
- A database can be reached from outside the hosting.

Because the test database is far away, a full test run takes a while. The tests rebuild the database once per run and roll every test back, which keeps the number of round trips low.

## Global Constraints

- **PHP 8.3+, Laravel 13.** `declare(strict_types=1);` in every PHP file this plan creates under `site/app`, `site/database`, `site/routes` and `site/tests`. Files that come with Laravel stay as they are unless a step replaces them.
- **Packages:** only what `composer create-project laravel/laravel` and `php artisan install:api` bring. No other package.
- **Nothing hardcoded per machine.** Database, site address, debug switch, simulator switch and cookie security come from `.env` through `config(...)`. Code never calls `env()` outside the `site/config` folder, so `php artisan config:cache` keeps working on the server.
- **Database:** MySQL 8.0.16+ or MariaDB 10.4+. `db/schema.sql` and `db/seed.sql` are fixed. If a task seems to need a schema change, stop and report it; do not edit the schema. (Task 14 changes two comment lines in `db/schema.sql`, nothing else.) The one migration of this plan runs `db/schema.sql` as it is.
- **Folders:** `site/` is the Laravel application. `site/public/` is the web root; nothing else is reachable from the web. `db/` stays at the repository root, next to `site/`: the migration and the seeder read `../db/*.sql`, so the server needs both folders.
- **SQL:** every query goes through `rows()`, `row()`, `DB::scalar()`, `DB::select()`, `DB::insert()`, `DB::update()`, `DB::delete()` or the query builder, with `?` placeholders. Only constants written in the code may be put into SQL text. No value from a request is ever pasted into a query.
- **Status changes:** always `DB::update('UPDATE items SET status = <new> ... WHERE id = ? AND status = <old>', ...)`, inside `DB::transaction()`, followed by one `Scan::log()` row. Never load a model, change it and save it: that loses the "where still old" check, and a second scan would count twice. A second scan of the same mat must change nothing and write no second event.
- **Roles:** exactly `office`, `inventory`, `driver`, `washing`, `outbound` (`User::ROLES`).
- **Scan source:** `simulator` or `c72` from scanners. The server writes `web` for office actions. `simulator` is refused when `SCANNER_SIMULATOR` is not `true`.
- **Errors:** always `{"error": {"code": "...", "message": "..."}}` with status `401` not logged in, `403` wrong role, not yours, or failed forgery check, `404` not found, `405` wrong method, `409` already done, closed, duplicate or wrong status, `422` input not valid, `429` too many login tries. Code stops a request with `fail($status, $code, $message)`.
- **Dates:** `DATETIME` columns are UTC and set with `UTC_TIMESTAMP()`. The API sends them as they are stored (`2026-10-12 21:03:11`, UTC); turning them into Dutch time is the screen's job. `DATE` columns (`due_date`, wash dates, contract dates) are calendar days in Europe/Amsterdam and set with `today_nl()`.
- **Wash count:** goes up only in `HandoverController::move()` for `washing_to_inventory`, at the giver's scan. No other code touches `wash_count` or the wash dates.
- **Tag ids** (EPC, TID) are stored and compared as uppercase hex, 8 to 64 characters.
- **Nothing is hard-deleted.** The one exception is the lines of a contract that is still a draft.
- **Every source file this plan creates starts with a one-line doc comment** saying what the file is for.
- **Secrets:** database passwords and `APP_KEY` live only in `site/.env` (real database, on the server) and `site/.env.testing` (test database, on the development machine). Neither file is ever committed, printed or logged.
- **No local database.** Nothing in this plan installs or starts a database server. Every test and every manual check runs against the TransIP test database named in `site/.env.testing`. Never point a test or a manual check at the real database.
- **Stay in the backend lane:** no React, no change to `site/resources`, `site/package.json` or `site/vite.config.js`, no schema edits. Laravel's welcome page at `/` stays until plan 2 replaces it.
- **Branch:** all work happens on `feat/backend-api`. Never commit on `main`.
- **Versions and commits:** every task is one commit and bumps the patch version. The commit step of each task gives the exact version and message. Commit messages follow the repository style: one sentence ending in `at <version>.` **Never add a `Co-Authored-By` line, a "Generated with" line, or any other AI attribution** to a commit, tag, pull request or file. This overrides any tool default.
- **Where commands run:** all commands are for Git Bash and start at the repository root. A command that needs Laravel is written as `(cd site && ...)`, so the shell is back at the root afterwards. Composer is the file `composer.phar` at the root, called as `php ../composer.phar` from inside `site`.
- **Run the tests** with `(cd site && php artisan test)`. A run talks to a database at TransIP, so it takes a while; run one test file while working on a task and the whole suite before a commit.

## Review Focus

Inputs and conditions the spec implies but does not spell out, most likely first. Each has a test in the task that owns the code.

1. **A tag id made of digits only** (for example `123456789012345678901234`). PHP turns such a string into a number when it is used as an array key. The mat must still be found. Test in Task 5.
2. **A phone photo bigger than PHP's 2 MB upload default.** A 3 MB photo must be accepted, a photo above 5 MB refused with a clear message. Test and `site/public/.user.ini` in Tasks 2 and 11.
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
| 7 | Login throttle: 5 failed tries per minute per username and address, then `429`. Counted by Laravel's `RateLimiter` in the cache (files under `site/storage`) | The schema has no table for it |
| 8 | `GET /items/{id}` is open to the washing house manager, who sees the events without note, task and zone | ARCHITECTURE.md (uncommitted edit) gives washing the mat history; a lookup must not reveal the customer |
| 9 | Closing a stop needs `signed_name` | A signature without a name proves little |
| 10 | A collection task accepts no drops. A delivery task expects no pick-ups | Follows from what the task types mean |
| 11 | Passwords have 10 to 72 characters. An office user cannot switch off or demote himself | Nobody may lock the last office user out |
| 12 | Sessions and the login throttle live in files under `site/storage/framework`, proofs under `site/storage/app/private/proofs` | No extra table and no extra service on shared hosting. `storage` is outside the web root |
| 13 | Ending a contract sets the end date to today, or to the start date when today is earlier | The database refuses an end date before the start date |
| 14 | Opening a handover that is already open between the same two people returns the open one | A retry on a bad connection must not create a second handover |
| 15 | Photo at most 5 MB, signature at most 1 MB. `site/public/.user.ini` raises PHP's upload limit to 6 MB | The spec says "a size limit" without a number |
| 16 | Forgery check: Laravel lets a changing request through when the browser marks it as same-origin (`Sec-Fetch-Site`), or when it carries the header `X-CSRF-Token` with the `csrf_token` from the login or from `GET /auth/me`. The web app sends the header on every changing request | Laravel 13's own check; the header keeps the contract of the plain-PHP plan and covers older browsers |
| 17 | The session cookie only works for requests whose `Origin` or `Referer` is the site's own address (`APP_URL`, or `SANCTUM_STATEFUL_DOMAINS`) | This is how Sanctum tells the web app from a token client such as the handheld |

## File Structure

```
composer.phar                  Composer itself, downloaded in Task 1, not committed
.gitignore
db/schema.sql, db/seed.sql     unchanged; read by the migration and the seeder
site/                          the Laravel application (composer create-project)
  .env.example                 every setting, without secrets
  .env.testing                 test database (human, not committed)
  composer.json                name, version, autoload of app/helpers.php
  phpunit.xml                  test settings; the database comes from .env.testing
  bootstrap/app.php            /api/v1 prefix, session for the web app, role middleware, the one error shape
  config/database.php          UTC and the strict SQL mode on every connection
  config/laundry.php           the simulator switch
  routes/api.php               every API route with the roles that may call it
  routes/console.php           the command laundry:office-user
  app/helpers.php              rows(), row(), marks(), today_nl(), days_after(), run_sql_file(), fail(), api_error()
  app/Models/User.php          the user: roles, password column, what the API shows, register()
  app/Support/Scan.php         tag reads, mat lookups, the mat as a scanner sees it, history rows
  app/Http/Middleware/Role.php         active user and role check
  app/Http/Middleware/ApiHeaders.php   nosniff, no-store, HSTS
  app/Http/Controllers/
    AuthController.php         login, logout, me
    RefdataController.php      areas, customers, buildings, zones, mat types
    UserController.php         user management
    ScanController.php         lookup, simulator list
    ItemController.php         register, ready, quality check, correction, list, history
    HandoverController.php     the five handover types
    ContractController.php     contracts, lines, activate, end
    TaskController.php         task lists, assignment, closing a stop, proof files
  database/migrations/         0001_01_01_000000_load_schema.php, Sanctum's token table
  database/seeders/DatabaseSeeder.php   runs db/seed.sql
  public/                      web root: index.php, .htaccess (HTTPS), .user.ini (upload limits)
  tests/
    TestCase.php               refuses to run without a test database of its own
    TestSeeder.php             mock data plus six users
    ApiTestCase.php            helpers to call the API and to look at the mats
    Feature/*Test.php          one file per task
```

One controller per subject. A controller method validates its input, runs its SQL in a transaction and returns an array, which Laravel sends as JSON. Rows are plain arrays (`rows()` and `row()`), not Eloquent models; the one model is `User`, because the login needs it.

---

### Task 1: Laravel application and database test harness

**Files:**
- Create: `.gitignore`, `composer.phar` (downloaded, not committed)
- Create: `site/` with `composer create-project`, then `php artisan install:api`
- Replace: `site/.env.example`, `site/phpunit.xml`, `site/tests/TestCase.php`, `site/database/seeders/DatabaseSeeder.php`
- Create: `site/config/laundry.php`, `site/app/helpers.php`, `site/database/migrations/0001_01_01_000000_load_schema.php`
- Create: `site/tests/TestSeeder.php`, `site/tests/ApiTestCase.php`
- Modify: `site/config/database.php` (three lines), `site/composer.json` (name, version, autoload), `site/.gitignore` (one line)
- Test: `site/tests/Feature/DbTest.php`
- Modify: `README.md:16` (version badge)

**Interfaces:**
- Consumes: `db/schema.sql`, `db/seed.sql`, `site/.env.testing` (made by the human, see Prerequisites).
- Produces:
  - `rows(string $sql, array $params = []): array` — every row as an array; positional `?` only
  - `row(string $sql, array $params = []): ?array` — the first row, or null
  - `marks(array $xs): string` — `?,?,?` for an `IN (...)` list
  - `today_nl(): string` — `Y-m-d` in Europe/Amsterdam
  - `days_after(string $date, int $days): string`
  - `run_sql_file(string $path): void`
  - `fail(int $status, string $code, string $message): never` — stops the request with the one error shape
  - `api_error(Throwable $e): Response` — turns any exception into the one error shape; wired up in Task 2
  - `config('laundry.simulator'): bool`
  - Laravel's own: `DB::transaction(callable)`, `DB::scalar($sql, $params)`, `DB::insert()`, `DB::update()` (returns the number of changed rows), `DB::getPdo()->lastInsertId()`
  - Test base class `Tests\ApiTestCase` with `$this->users` (`office`, `inventory`, `driver`, `driver2`, `washing`, `outbound` → id), `PASSWORD`, `api()`, `loginAs()`, `logout()`, `ok()`, `assertError()`, `epc()`, `reads()`, `item()`, `setItems()`, `events()`

- [ ] **Step 1: Check the prerequisites**

Run:

```bash
test -f site/.env.testing && echo "env ok" || echo "site/.env.testing missing"
php -r '$c = parse_ini_file("site/.env.testing", false, INI_SCANNER_RAW); $p = new PDO("mysql:host={$c["DB_HOST"]};port={$c["DB_PORT"]};dbname={$c["DB_DATABASE"]}", $c["DB_USERNAME"], $c["DB_PASSWORD"]); echo "database ok: ", $p->query("SELECT VERSION()")->fetchColumn(), PHP_EOL;'
```

Expected: `env ok` and `database ok:` followed by the version of the TransIP database. Write that version into the task report: it answers the open question in ARCHITECTURE.md. If either check fails, stop and report BLOCKED with the exact output: the Prerequisites section is not done, or external access is off. Do not print the content of `site/.env.testing`, and do not try to install a database locally instead.

- [ ] **Step 2: Install Composer locally and create the Laravel application**

`composer create-project` needs an empty target folder, so the settings file steps aside for a moment.

Run:

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === trim(file_get_contents('https://composer.github.io/installer.sig'))) { echo 'Installer verified', PHP_EOL; } else { echo 'Installer corrupt', PHP_EOL; unlink('composer-setup.php'); exit(1); }"
php composer-setup.php --quiet
php -r "unlink('composer-setup.php');"

mv site/.env.testing ./.env.testing.keep
rmdir site
php composer.phar create-project laravel/laravel site "^13.0" --no-interaction
(cd site && php artisan install:api --no-interaction)
mv ./.env.testing.keep site/.env.testing
```

Expected: `Installer verified`. `create-project` ends with `Application key set successfully` and a small migration run against a local SQLite file. `install:api` ends with `API scaffolding installed. Please add the [Laravel\Sanctum\HasApiTokens] trait to your User model.` (Task 2 does that.) `(cd site && php artisan --version)` prints `Laravel Framework 13.`

If `rmdir site` fails, the folder holds more than the settings file: stop and report what is in it.

- [ ] **Step 3: Remove what this project does not use**

Laravel's starter comes with a SQLite database, its own `users`, `cache` and `jobs` tables, example tests, and generic instructions for coding agents. This project has its own database (`db/schema.sql`) and its own rules.

Create `.gitignore` at the repository root:

```
/composer.phar
/.env.testing.keep
/.superpowers/
```

Run:

```bash
rm site/.env site/database/database.sqlite
rm site/database/migrations/0001_01_01_000000_create_users_table.php
rm site/database/migrations/0001_01_01_000001_create_cache_table.php
rm site/database/migrations/0001_01_01_000002_create_jobs_table.php
rm -r site/database/factories site/tests/Unit
rm site/tests/Feature/ExampleTest.php site/AGENTS.md site/CLAUDE.md
printf '.env.testing\n' >> site/.gitignore
(cd site && php artisan key:generate --env=testing)
ls site/database/migrations
```

Expected: `Application key set successfully.` (the key went into `site/.env.testing`), and `ls` shows exactly one file, ending in `_create_personal_access_tokens_table.php`. If `rm` reports that `AGENTS.md` or `CLAUDE.md` does not exist, that is fine: not every Laravel version ships them.

- [ ] **Step 4: Write the test settings and the test harness**

Replace `site/phpunit.xml` with:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
>
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <!-- APP_ENV=testing makes Laravel read .env.testing. The database comes from there, never from this file. -->
        <env name="APP_ENV" value="testing"/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
    </php>
</phpunit>
```

Replace `site/tests/TestCase.php` with:

```php
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
```

Create `site/tests/TestSeeder.php`:

```php
<?php
/** What every test starts with: the mock data plus six users, one per role and a second driver. */
declare(strict_types=1);

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class TestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);
        $hash = Hash::make(ApiTestCase::PASSWORD);
        $roles = ['office' => 'office', 'inventory' => 'inventory', 'driver' => 'driver',
            'driver2' => 'driver', 'washing' => 'washing', 'outbound' => 'outbound'];
        foreach ($roles as $username => $role) {
            DB::table('users')->insert(['name' => ucfirst($username), 'username' => $username,
                'password_hash' => $hash, 'role' => $role]);
        }
    }
}
```

Create `site/tests/ApiTestCase.php`:

```php
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
}
```

`api()` and `loginAs()` need the routes and the login, which Task 2 builds. This task's tests do not call them.

- [ ] **Step 5: Write the failing test**

Create `site/tests/Feature/DbTest.php`:

```php
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
```

- [ ] **Step 6: Run the test to see it fail**

Run: `(cd site && php artisan test tests/Feature/DbTest.php)`
Expected: FAIL. Every test errors while the test database is being built and seeded, because nothing loads `db/schema.sql` yet. If the output is `Refusing to run`, the Prerequisites are not done: stop.

- [ ] **Step 7: Write the settings, the helpers, the migration and the seeder**

Replace `site/.env.example` with:

```ini
# Every setting that differs per machine lives in a copy of this file. Nothing is hardcoded in the code.
#   site/.env          on the server, with the real database
#   site/.env.testing  on the development machine, with the test database. The tests wipe that database.
# Neither copy is ever committed.

APP_NAME=Laundry
# "production" on the server, "testing" in .env.testing.
APP_ENV=production
# Filled in by: php artisan key:generate
APP_KEY=
# true shows error details in API answers. Never true on the server.
APP_DEBUG=false
# The address of the site, with https. The login cookie only works for the web app at this address.
APP_URL=https://example.nl

# Database: host, name, user and password come from the TransIP control panel.
DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=""

# true while the handheld is simulated. false switches the simulator and GET /sim/items off.
SCANNER_SIMULATOR=true

# The login session: kept in files under storage/, for 12 hours (one working day).
SESSION_DRIVER=file
SESSION_LIFETIME=720
# true sends the login cookie over https only. false only on a development machine without https.
SESSION_SECURE_COOKIE=true
# Only when the web app is served from another host than APP_URL: host names, comma separated, without https://
# SANCTUM_STATEFUL_DOMAINS=

# No extra tables and no extra services: cache in files, no queue, proofs on the server's own disk.
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning
BCRYPT_ROUNDS=12
```

Create `site/config/laundry.php`:

```php
<?php
/** Settings of the mat tracking app itself. The values come from .env, so the code is the same on every machine. */

return [
    // true while the handheld is simulated. false refuses scans with source "simulator" and hides GET /sim/items.
    'simulator' => (bool) env('SCANNER_SIMULATOR', false),
];
```

In `site/config/database.php`, inside the `'mysql'` connection (the first of the two places with this line), replace the line `'strict' => true,` with:

```php
'strict' => true,
// Every DATETIME is UTC. Without the strict mode MySQL silently accepts an unknown status.
'timezone' => '+00:00',
'modes' => ['STRICT_ALL_TABLES', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION'],
```

Create `site/app/helpers.php`:

```php
<?php
/** Small functions used everywhere: rows as arrays, the date helpers, running a .sql file, and the one error shape. */
declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** All rows of a query, each as an array. Values are bound to the "?" marks, never pasted into the SQL. */
function rows(string $sql, array $params = []): array
{
    return array_map(fn (object $row): array => (array) $row, DB::select($sql, $params));
}

/** The first row of a query as an array, or null when there is none. */
function row(string $sql, array $params = []): ?array
{
    $row = DB::selectOne($sql, $params);
    return $row === null ? null : (array) $row;
}

/** "?,?,?" for an IN (...) list with one mark per element. */
function marks(array $xs): string
{
    return implode(',', array_fill(0, count($xs), '?'));
}

/** Today as a calendar day in the Netherlands. DATE columns hold these, DATETIME columns hold UTC. */
function today_nl(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
}

function days_after(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify(sprintf('%+d days', $days))->format('Y-m-d');
}

/** Runs every statement of a .sql file. The files have no ";" at a line end inside a statement. */
function run_sql_file(string $path): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($path));
    foreach (preg_split('/;[ \t]*(?:\r?\n|$)/', $sql) as $statement) {
        if (trim($statement) !== '') {
            DB::unprepared($statement);
        }
    }
}

/** Stops the request with the API's one error shape. Inside DB::transaction() it also rolls the transaction back. */
function fail(int $status, string $code, string $message): never
{
    throw new HttpResponseException(response()->json(['error' => ['code' => $code, 'message' => $message]], $status));
}

/** Any exception as the API's one error shape. bootstrap/app.php calls this for every failed API request. */
function api_error(Throwable $e): Response
{
    if ($e instanceof HttpResponseException) {
        return $e->getResponse();   // made by fail()
    }
    $dbError = $e instanceof QueryException ? ($e->errorInfo[1] ?? 0) : 0;
    $http = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 0;
    [$status, $code, $message] = match (true) {
        $e instanceof ValidationException => [422, 'invalid_input', $e->validator->errors()->first()],
        $e instanceof AuthenticationException => [401, 'not_logged_in', 'Log in first.'],
        // The database is the last line of defence. Its refusals become the API's error codes.
        $dbError === 1062 => [409, 'duplicate', 'That value is already in use.'],
        $dbError === 1452 => [422, 'invalid_reference', 'A referenced record does not exist.'],
        $dbError === 3819, $dbError === 4025 => [422, 'rule_violation', 'The database refused an impossible state.'],
        $http === 404 => [404, 'not_found', 'No such path.'],
        $http === 405 => [405, 'method_not_allowed', 'This path does not accept that method.'],
        $http === 419 => [403, 'csrf', 'Missing or wrong CSRF token.'],
        $http !== 0 => [$http, 'http_error', $e->getMessage()],
        // With APP_DEBUG=false the details stay in the server log.
        default => [500, 'server_error', config('app.debug') ? $e->getMessage() : 'Something went wrong.'],
    };
    return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
}
```

Create `site/database/migrations/0001_01_01_000000_load_schema.php`:

```php
<?php
/** Builds the 13 tables by running db/schema.sql as it is. That file stays the one description of the database. */
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        run_sql_file(base_path('../db/schema.sql'));
    }
};
```

Replace `site/database/seeders/DatabaseSeeder.php` with:

```php
<?php
/** Loads the mock data of db/seed.sql: two customers, 40 mock mats, two draft contracts. No users. */
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        run_sql_file(base_path('../db/seed.sql'));
    }
}
```

Now tell Composer about `app/helpers.php`, and give the project its name and version. Run:

```bash
php -r '$f = "site/composer.json"; $c = json_decode(file_get_contents($f), true); $c["name"] = "proti/laundry-poc"; $c["description"] = "Proti mat tracking POC: Laravel API"; $c["version"] = "0.4.0"; $c["autoload"]["files"] = ["app/helpers.php"]; file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
(cd site && php ../composer.phar dump-autoload)
```

Expected: `Generated optimized autoload files`. `git diff --stat site/composer.json` is not available yet (the folder is new), so check by eye that `site/composer.json` still has its `require`, `require-dev` and `scripts` blocks and now has `"files": ["app/helpers.php"]` inside `"autoload"`.

- [ ] **Step 8: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/DbTest.php)`
Expected: `Tests:    8 passed`.

If `test_numbers_come_back_as_numbers` fails because `wash_count` comes back as the string `"0"`, the PDO driver is not `mysqlnd`: report it, do not cast around it. If `test_connection_runs_in_utc` fails, the three lines in `site/config/database.php` went into the `'mariadb'` block instead of the `'mysql'` block.

- [ ] **Step 9: Commit**

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.0-2563eb/' README.md
git add .gitignore site README.md
git status --short | grep -E '\.env\.testing|\.env$|vendor/|composer\.phar' ; echo "secrets check done"
git commit -m "Add the Laravel application and the database test harness at 0.4.0."
```

Expected before the commit: only `secrets check done`. A line above it names a file that must not be committed: run `git reset` and fix the ignore files first.

---

### Task 2: Routing, the error shape and login

**Files:**
- Replace: `site/app/Models/User.php`, `site/bootstrap/app.php`, `site/routes/api.php`
- Create: `site/app/Http/Middleware/Role.php`, `site/app/Http/Middleware/ApiHeaders.php`
- Create: `site/app/Http/Controllers/AuthController.php`
- Modify: `site/public/.htaccess` (HTTPS redirect)
- Create: `site/public/.user.ini`
- Test: `site/tests/Feature/AuthTest.php`

**Interfaces:**
- Consumes: `fail()`, `api_error()` from Task 1; Laravel's `Auth`, `RateLimiter`, session and Sanctum.
- Produces:
  - `App\Models\User` — `User::ROLES`; as JSON a user is exactly `id, name, username, role, is_active` (`is_active` true or false)
  - Middleware alias `role`: on every route behind the login it logs a switched-off user out (`401`); `role:office,inventory` lets only those roles through (`403 forbidden`)
  - In a controller: `$request->user()` is the logged-in `User` (`->id` int, `->role` string); `$request->validate([...])` answers `422 invalid_input` by itself; a path id arrives as a method parameter `int $id`
  - Text input is trimmed, and an empty text arrives as `null` (Laravel's `TrimStrings` and `ConvertEmptyStringsToNull`), except a field named `password`
  - `site/routes/api.php`: every path is relative to `/api/v1`. Routes behind the login go inside the one `auth:sanctum` group; later tasks add their lines at the end of that group. `$scanners` in that group is the middleware for the four scanning roles
  - Routes: `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`

- [ ] **Step 1: Write the failing test**

Create `site/tests/Feature/AuthTest.php`:

```php
<?php
/** Login, logout, the session, the login throttle, and the one error shape for unknown paths and server errors. */
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\ApiTestCase;

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
        $this->assertSame(['driver', true], [$out['user']['role'], $out['user']['is_active']]);
        $this->assertAuthenticatedAs(User::find($this->users['driver']), 'web');
        $this->assertSame(csrf_token(), $out['csrf_token']);
        $this->assertNotSame('', $out['csrf_token']);
    }

    public function test_wrong_password_and_unknown_user_get_the_same_answer(): void
    {
        $this->assertError(401, 'bad_login', $this->login('driver', 'wrong-password'));
        $this->assertError(401, 'bad_login', $this->login('nobody', 'wrong-password'));
        $this->assertGuest('web');
    }

    public function test_a_switched_off_user_cannot_log_in(): void
    {
        DB::update('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver']]);
        $this->assertError(401, 'bad_login', $this->login('driver'));
        $this->assertGuest('web');
    }

    public function test_a_user_switched_off_while_logged_in_is_logged_out_at_his_next_call(): void
    {
        $this->ok($this->login('driver'));
        $this->ok($this->api('GET', '/auth/me'));
        DB::update('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver']]);
        $this->assertError(401, 'not_logged_in', $this->api('GET', '/auth/me'));
        $this->assertGuest('web');
    }

    public function test_five_failed_tries_block_the_sixth_even_with_the_right_password(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertError(401, 'bad_login', $this->login('driver', 'wrong-password'));
        }
        $this->assertError(429, 'too_many_tries', $this->login('driver'));
        $this->assertError(429, 'too_many_tries', $this->login('DRIVER'));   // the same user in capitals
        $this->ok($this->login('washing'));   // another username is not affected
    }

    public function test_a_good_login_clears_the_failed_tries(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->login('driver', 'wrong-password');
        }
        $this->ok($this->login('driver'));
        $this->logout();
        for ($i = 0; $i < 4; $i++) {
            $this->assertError(401, 'bad_login', $this->login('driver', 'wrong-password'));
        }
    }

    public function test_login_needs_username_and_password(): void
    {
        $this->assertError(422, 'invalid_input', $this->api('POST', '/auth/login', ['username' => 'driver']));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/auth/login', ['password' => 'x']));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/auth/login', ['username' => ['a'], 'password' => 'x']));
    }

    public function test_me_needs_a_login(): void
    {
        $this->assertError(401, 'not_logged_in', $this->api('GET', '/auth/me'));
        $this->loginAs('office');
        $out = $this->ok($this->api('GET', '/auth/me'));
        $this->assertSame('office', $out['user']['role']);
        $this->assertSame(csrf_token(), $out['csrf_token']);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->ok($this->login('office'));
        $this->assertSame(['ok' => true], $this->ok($this->api('POST', '/auth/logout')));
        $this->assertGuest('web');
        $this->assertError(401, 'not_logged_in', $this->api('GET', '/auth/me'));
    }

    public function test_unknown_path_is_404_and_wrong_method_is_405(): void
    {
        $this->assertError(404, 'not_found', $this->api('GET', '/nothing-here'));
        $this->assertError(405, 'method_not_allowed', $this->api('DELETE', '/auth/me'));
    }

    public function test_a_server_error_hides_its_details_unless_debug_is_on(): void
    {
        Route::get('/api/v1/boom', fn () => throw new RuntimeException('secret detail'));

        config(['app.debug' => false]);
        [$status, $body] = $this->api('GET', '/boom');
        $this->assertSame([500, 'server_error', 'Something went wrong.'],
            [$status, $body['error']['code'], $body['error']['message']]);

        config(['app.debug' => true]);
        $this->assertSame('secret detail', $this->api('GET', '/boom')[1]['error']['message']);
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `(cd site && php artisan test tests/Feature/AuthTest.php)`
Expected: FAIL. The routes do not exist yet, so every call gets Laravel's own `404`.

- [ ] **Step 3: Write the user model and the two middleware**

Replace `site/app/Models/User.php` with:

```php
<?php
/** A person who logs in. One role per user. The password is stored only as a hash and never leaves the server. */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    // Not used yet: it is what lets the handheld log in with a token later, without a new build.
    use HasApiTokens;

    public const ROLES = ['office', 'inventory', 'driver', 'washing', 'outbound'];

    /** The schema names the column password_hash. Laravel's login finds the hash through this name. */
    protected $authPasswordName = 'password_hash';

    /** Exactly what the API shows of a user. */
    protected $visible = ['id', 'name', 'username', 'role', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
```

Create `site/app/Http/Middleware/Role.php`:

```php
<?php
/** Runs on every route behind the login: a switched-off user is out at once, and "role:a,b" lets only those roles through. */
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class Role
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (!$user->is_active) {
            // The user is read from the database on every call, so switching someone off works at his next call.
            Auth::guard('web')->logout();
            fail(401, 'not_logged_in', 'Log in first.');
        }
        if ($roles !== [] && !in_array($user->role, $roles, true)) {
            fail(403, 'forbidden', 'Your role may not do this.');
        }
        return $next($request);
    }
}
```

Create `site/app/Http/Middleware/ApiHeaders.php`:

```php
<?php
/** Headers on every API answer: never guess a content type, never cache, and HTTPS only once the site is live. */
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store');
        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        return $response;
    }
}
```

- [ ] **Step 4: Write login and logout**

Create `site/app/Http/Controllers/AuthController.php`:

```php
<?php
/** Login, logout and the logged-in user. Laravel keeps the session; failed logins are throttled per username and address. */
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

final class AuthController extends Controller
{
    private const MAX_TRIES = 5;

    private const WINDOW_SECONDS = 60;

    public function login(Request $request): array
    {
        if (!$request->hasSession()) {
            // Sanctum starts a session only for calls that come from the web app's own address (APP_URL).
            fail(403, 'csrf', 'Log in from the web app.');
        }
        $in = $request->validate(['username' => 'required|string|max:60', 'password' => 'required|string']);
        $username = strtolower($in['username']);

        $key = 'login|' . $username . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_TRIES)) {
            fail(429, 'too_many_tries', 'Too many tries. Wait a minute and try again.');
        }
        // attempt() takes as long for an unknown user as for a wrong password, so timing does not reveal usernames.
        if (!Auth::guard('web')->attempt(['username' => $username, 'password' => $in['password'], 'is_active' => 1])) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
            fail(401, 'bad_login', 'Wrong username or password.');
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();   // a new session id at login
        return ['user' => Auth::guard('web')->user(), 'csrf_token' => csrf_token()];
    }

    public function logout(Request $request): array
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return ['ok' => true];
    }

    public function me(Request $request): array
    {
        return ['user' => $request->user(), 'csrf_token' => csrf_token()];
    }
}
```

- [ ] **Step 5: Wire the application and write the routes**

Replace `site/bootstrap/app.php` with:

```php
<?php
/** Wires the application: the API under /api/v1, the session for the web app, the role check, and the one error shape. */

use App\Http\Middleware\ApiHeaders;
use App\Http\Middleware\Role;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The web app logs in with a session cookie. Sanctum adds the session and the forgery check to its API calls.
        $middleware->statefulApi();
        $middleware->api(append: [ApiHeaders::class]);
        $middleware->alias(['role' => Role::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every failure under /api answers in the one error shape. Other pages keep Laravel's own error pages.
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? api_error($e) : null);
    })->create();
```

Replace `site/routes/api.php` with:

```php
<?php
/** The API routes, all under /api/v1. Only the login is open; "role:" says which roles may call a route. */
declare(strict_types=1);

use App\Http\Controllers as C;
use Illuminate\Support\Facades\Route;

// An id in a path is a whole number. Anything else is an unknown path.
Route::pattern('id', '\d{1,18}');
Route::pattern('itemId', '\d{1,18}');

Route::post('/auth/login', [C\AuthController::class, 'login']);

// Everything below needs a logged-in user who is still switched on. A route without its own "role:" is open to all roles.
Route::middleware(['auth:sanctum', 'role'])->group(function () {
    $scanners = 'role:inventory,driver,washing,outbound';

    Route::post('/auth/logout', [C\AuthController::class, 'logout']);
    Route::get('/auth/me', [C\AuthController::class, 'me']);
});
```

- [ ] **Step 6: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/AuthTest.php)`
Expected: `Tests:    11 passed`.

The two throttle tests take a few seconds: Laravel makes every failed login take at least 0.2 seconds on purpose.

If every test gets `401` where it expects `200`, the session is not reaching the request: check that `APP_URL` in `site/.env.testing` is `http://127.0.0.1:8000` and that `site/bootstrap/app.php` calls `statefulApi()`.

- [ ] **Step 7: HTTPS and the upload limit in the web root**

Neither file is read by the tests of this task or by PHP's built-in server; both matter on the server at TransIP.

In `site/public/.htaccess`, add these lines directly under the line `RewriteEngine On`:

```apache
    # HTTPS only. The second condition is for a proxy that already took the HTTPS off.
    RewriteCond %{HTTPS} !=on
    RewriteCond %{HTTP:X-Forwarded-Proto} !=https
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```

Create `site/public/.user.ini`:

```ini
; A proof photo from a phone is bigger than PHP's 2 MB default.
upload_max_filesize = 6M
post_max_size = 8M
```

- [ ] **Step 8: Run all tests**

Run: `(cd site && php artisan test)`
Expected: `Tests:    19 passed`.

- [ ] **Step 9: Commit**

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.1-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.1"/' site/composer.json
git add site README.md
git commit -m "Add routing, the error shape and login at 0.4.1."
```

---

### Task 3: Areas, customers, buildings, zones and mat types

**Files:**
- Create: `site/app/Http/Controllers/RefdataController.php`
- Modify: `site/routes/api.php`
- Test: `site/tests/Feature/RefdataTest.php`

**Interfaces:**
- Consumes: `fail()`, `$request->validate()`, the `role` middleware and the route group from Task 2.
- Produces routes, all answering `{"rows": [...]}` for a list and `{"row": {...}}` for one record, with `is_active` as true or false:
  - `GET, POST /areas`, `PATCH /areas/{id}` — office
  - `GET, POST /customers`, `PATCH /customers/{id}` — office
  - `GET /item-types` — office and inventory; `POST /item-types`, `PATCH /item-types/{id}` — office
  - `GET, POST /customers/{id}/buildings`, `PATCH /buildings/{id}` — office
  - `GET, POST /buildings/{id}/zones`, `PATCH /zones/{id}` — office

- [ ] **Step 1: Write the failing test**

Create `site/tests/Feature/RefdataTest.php`:

```php
<?php
/** Office management of areas, customers, buildings, zones and mat types. */
declare(strict_types=1);

namespace Tests\Feature;

use Tests\ApiTestCase;

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

Run: `(cd site && php artisan test tests/Feature/RefdataTest.php)`
Expected: FAIL, every test gets `404 not_found` where it expects another status.

- [ ] **Step 3: Write the implementation**

Create `site/app/Http/Controllers/RefdataController.php`:

```php
<?php
/** Areas, customers, buildings, zones and mat types: five small tables the office manages the same way. */
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RefdataController extends Controller
{
    // Per resource: its table, the column that points to its parent (or null), and the rules of its fields.
    // Table and column names come only from this constant, never from a request.
    // "sometimes|required" means: may be left out, but when it is sent it must have a value.
    private const RESOURCES = [
        'areas' => ['table' => 'areas', 'parent' => null, 'rules' => [
            'name' => 'required|string|max:80',
        ]],
        'customers' => ['table' => 'customers', 'parent' => null, 'rules' => [
            'name' => 'required|string|max:160',
            'contact_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:40',
            'contact_email' => 'nullable|string|max:190',
            'is_active' => 'sometimes|required|boolean',
        ]],
        'item-types' => ['table' => 'item_types', 'parent' => null, 'rules' => [
            'name' => 'required|string|max:120',
            'is_active' => 'sometimes|required|boolean',
        ]],
        'buildings' => ['table' => 'buildings', 'parent' => 'customer_id', 'rules' => [
            'area_id' => 'required|integer|min:0',
            'name' => 'required|string|max:160',
            'street' => 'required|string|max:160',
            'postcode' => 'required|string|max:12',
            'city' => 'required|string|max:80',
            'is_active' => 'sometimes|required|boolean',
        ]],
        'zones' => ['table' => 'zones', 'parent' => 'building_id', 'rules' => [
            'name' => 'required|string|max:120',
            'is_active' => 'sometimes|required|boolean',
        ]],
    ];

    /** The resource a route is for. Each route names it itself, see routes/api.php. */
    private function resource(Request $request): array
    {
        return self::RESOURCES[$request->route('resource')];
    }

    private function view(object $row): array
    {
        $row = (array) $row;
        if (array_key_exists('is_active', $row)) {
            $row['is_active'] = (bool) $row['is_active'];
        }
        return $row;
    }

    private function find(string $table, int $id): array
    {
        $row = DB::table($table)->where('id', $id)->first();
        if ($row === null) {
            fail(404, 'not_found', 'No such record.');
        }
        return $this->view($row);
    }

    public function index(Request $request): array
    {
        ['table' => $table, 'parent' => $parent] = $this->resource($request);
        $rows = DB::table($table)
            ->when($parent !== null, fn ($query) => $query->where($parent, (int) $request->route('id')))
            ->orderBy('name')
            ->get();
        return ['rows' => $rows->map(fn (object $row) => $this->view($row))->all()];
    }

    public function store(Request $request): array
    {
        ['table' => $table, 'parent' => $parent, 'rules' => $rules] = $this->resource($request);
        $values = $request->validate($rules);
        if ($parent !== null) {
            $values[$parent] = (int) $request->route('id');
        }
        return ['row' => $this->find($table, DB::table($table)->insertGetId($values))];
    }

    /** Changes only the fields that were sent. */
    public function update(Request $request): array
    {
        ['table' => $table, 'rules' => $rules] = $this->resource($request);
        $id = (int) $request->route('id');
        $this->find($table, $id);
        $values = $request->validate(array_map(fn (string $rule) => "sometimes|$rule", $rules));
        if ($values !== []) {
            DB::table($table)->where('id', $id)->update($values);
        }
        return ['row' => $this->find($table, $id)];
    }
}
```

A name that exists, or an area that does not exist, is not checked here: the database refuses it and `api_error()` turns that into `409 duplicate` or `422 invalid_reference`.

In `site/routes/api.php` add these lines at the end of the `auth:sanctum` group (directly above its closing `});`):

```php

    // Areas, customers, mat types, buildings and zones. A building hangs under a customer, a zone under a building.
    $refdata = ['areas' => null, 'customers' => null, 'item-types' => null, 'buildings' => 'customers', 'zones' => 'buildings'];
    foreach ($refdata as $resource => $parent) {
        $list = $parent === null ? "/$resource" : "/$parent/{id}/$resource";
        $read = $resource === 'item-types' ? 'role:office,inventory' : 'role:office';
        Route::get($list, [C\RefdataController::class, 'index'])->defaults('resource', $resource)->middleware($read);
        Route::post($list, [C\RefdataController::class, 'store'])->defaults('resource', $resource)->middleware('role:office');
        Route::patch("/$resource/{id}", [C\RefdataController::class, 'update'])->defaults('resource', $resource)->middleware('role:office');
    }
```

- [ ] **Step 4: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/RefdataTest.php)`
Expected: `Tests:    9 passed`.

- [ ] **Step 5: Run all tests and commit**

Run: `(cd site && php artisan test)`
Expected: `Tests:    28 passed`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.2-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.2"/' site/composer.json
git add site README.md
git commit -m "Add office management of customers, buildings, zones and mat types at 0.4.2."
```

---

### Task 4: Users and the first office user

**Files:**
- Replace: `site/app/Models/User.php` (adds `register()`), `site/routes/console.php`
- Create: `site/app/Http/Controllers/UserController.php`
- Modify: `site/routes/api.php`
- Test: `site/tests/Feature/UsersTest.php`

**Interfaces:**
- Consumes: `User`, `fail()`, `$request->validate()`, the route group.
- Produces:
  - `User::register(array $in): User` — validates `name`, `username`, `password`, `role`, stores the user and returns it. A bad value throws Laravel's `ValidationException` (`422 invalid_input` in the API)
  - Routes, office only: `GET /users` → `{"rows": [...]}`, `POST /users` → `{"user": {...}}`, `PATCH /users/{id}` → `{"user": {...}}`
  - Command `php artisan laundry:office-user` — asks for name, username and password (hidden while typing)

- [ ] **Step 1: Write the failing test**

Create `site/tests/Feature/UsersTest.php`:

```php
<?php
/** User management by the office, and the one-time command that creates the first office user. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

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

        $this->logout();
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

        $this->logout();
        $this->assertError(401, 'bad_login',
            $this->api('POST', '/auth/login', ['username' => 'driver', 'password' => self::PASSWORD]));
    }

    public function test_office_changes_role_name_and_password(): void
    {
        $this->loginAs('office');
        $user = $this->ok($this->api('PATCH', "/users/{$this->users['outbound']}",
            ['role' => 'inventory', 'name' => 'Olga', 'password' => 'another-password-2']))['user'];
        $this->assertSame(['inventory', 'Olga'], [$user['role'], $user['name']]);

        $this->logout();
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
        $this->artisan('laundry:office-user')
            ->expectsQuestion('Full name', 'First Office')
            ->expectsQuestion('Username', 'First.Office')
            ->expectsQuestion('Password (10 to 72 characters)', 'long-password-1')
            ->expectsOutputToContain('Created office user first.office.')
            ->assertExitCode(0);

        $out = $this->ok($this->api('POST', '/auth/login', ['username' => 'first.office', 'password' => 'long-password-1']));
        $this->assertSame('office', $out['user']['role']);
    }

    public function test_the_command_refuses_a_weak_password_and_a_name_that_exists(): void
    {
        $this->artisan('laundry:office-user')
            ->expectsQuestion('Full name', 'First Office')
            ->expectsQuestion('Username', 'first.office')
            ->expectsQuestion('Password (10 to 72 characters)', 'short')
            ->assertExitCode(1);
        $this->artisan('laundry:office-user')
            ->expectsQuestion('Full name', 'Second Office')
            ->expectsQuestion('Username', 'office')
            ->expectsQuestion('Password (10 to 72 characters)', 'long-password-1')
            ->assertExitCode(1);
        $this->assertSame(6, (int) DB::scalar('SELECT COUNT(*) FROM users'));
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `(cd site && php artisan test tests/Feature/UsersTest.php)`
Expected: FAIL: the API tests get `404 not_found`, the two command tests fail because the command `laundry:office-user` does not exist.

- [ ] **Step 3: Write the implementation**

Replace `site/app/Models/User.php` with:

```php
<?php
/** A person who logs in. One role per user. The password is stored only as a hash and never leaves the server. */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    // Not used yet: it is what lets the handheld log in with a token later, without a new build.
    use HasApiTokens;

    public const ROLES = ['office', 'inventory', 'driver', 'washing', 'outbound'];

    // bcrypt reads only the first 72 bytes, so a longer password would be silently cut.
    public const PASSWORD_RULE = 'string|min:10|max:72';

    /** The schema names the column password_hash. Laravel's login finds the hash through this name. */
    protected $authPasswordName = 'password_hash';

    /** Exactly what the API shows of a user. */
    protected $visible = ['id', 'name', 'username', 'role', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** Validates and stores a new user. Used by POST /users and by the command laundry:office-user. */
    public static function register(array $in): self
    {
        if (is_string($in['username'] ?? null)) {
            $in['username'] = strtolower(trim($in['username']));
        }
        $data = Validator::make($in, [
            'name' => 'required|string|max:120',
            'username' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,60}$/'],
            'password' => 'required|' . self::PASSWORD_RULE,
            'role' => ['required', Rule::in(self::ROLES)],
        ], ['username.regex' => 'The username must be 3 to 60 letters, digits, dots, dashes or underscores.'])->validate();

        $user = new self;
        $user->forceFill([
            'name' => trim($data['name']),
            'username' => $data['username'],
            'password_hash' => Hash::make($data['password']),
            'role' => $data['role'],
        ])->save();
        return $user->fresh();
    }
}
```

Create `site/app/Http/Controllers/UserController.php`:

```php
<?php
/** User management by the office. No delete, only switching off. */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

final class UserController extends Controller
{
    public function index(): array
    {
        return ['rows' => User::orderBy('name')->get()];
    }

    public function store(Request $request): array
    {
        return ['user' => User::register($request->all())];
    }

    /** Changes only the fields that were sent. */
    public function update(Request $request, int $id): array
    {
        $user = User::find($id);
        if ($user === null) {
            fail(404, 'not_found', 'No such user.');
        }
        $in = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'role' => ['sometimes', 'required', Rule::in(User::ROLES)],
            'is_active' => 'sometimes|required|boolean',
            'password' => 'sometimes|required|' . User::PASSWORD_RULE,
        ]);

        $demoted = ($in['role'] ?? 'office') !== 'office' || !($in['is_active'] ?? true);
        if ($user->is($request->user()) && $demoted) {
            fail(422, 'self_lockout', 'You cannot switch off or demote yourself.');
        }
        if (isset($in['password'])) {
            $in['password_hash'] = Hash::make($in['password']);
            unset($in['password']);
        }
        $user->forceFill($in)->save();
        return ['user' => $user->fresh()];
    }
}
```

Replace `site/routes/console.php` with:

```php
<?php
/** Commands for the server's command line. */
declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

// Creates the first office user. Run once on the server: php artisan laundry:office-user
// No user is seeded, because a password must never sit in a file.
Artisan::command('laundry:office-user', function () {
    try {
        $user = User::register([
            'name' => $this->ask('Full name'),
            'username' => $this->ask('Username'),
            'password' => $this->secret('Password (10 to 72 characters)'),
            'role' => 'office',
        ]);
    } catch (ValidationException $e) {
        $this->error(implode(' ', $e->validator->errors()->all()));
        return 1;
    } catch (UniqueConstraintViolationException) {
        $this->error('That username already exists.');
        return 1;
    }
    $this->info("Created office user {$user->username}.");
    return 0;
})->purpose('Create the first office user');
```

In `site/routes/api.php` add these lines at the end of the `auth:sanctum` group (directly above its closing `});`):

```php

    Route::get('/users', [C\UserController::class, 'index'])->middleware('role:office');
    Route::post('/users', [C\UserController::class, 'store'])->middleware('role:office');
    Route::patch('/users/{id}', [C\UserController::class, 'update'])->middleware('role:office');
```

- [ ] **Step 4: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/UsersTest.php)`
Expected: `Tests:    9 passed`.

- [ ] **Step 5: Check a real login over HTTP by hand**

The tests call the API in-process. This is the first moment a real HTTP login is possible, with a real cookie and the real forgery check. It runs against the test database: `APP_ENV=testing` makes Laravel read `site/.env.testing`.

Start the server in the background (in the Bash tool: `run_in_background: true`):

```bash
(cd site && APP_ENV=testing php -S 127.0.0.1:8000 -t public)
```

Then run:

```bash
(cd site && php artisan tinker --env=testing --execute="App\Models\User::register(['name' => 'Smoke Office', 'username' => 'smoke', 'password' => 'smoke-password-1', 'role' => 'office']);")
JAR=$(mktemp)
API=http://127.0.0.1:8000/api/v1
WEB=(-H 'Origin: http://127.0.0.1:8000' -H 'Accept: application/json' -H 'Content-Type: application/json')
SAME=(-H 'Sec-Fetch-Site: same-origin')
LOGIN='{"username":"smoke","password":"smoke-password-1"}'
curl -s -i -c "$JAR" "${WEB[@]}" "${SAME[@]}" -d "$LOGIN" $API/auth/login
curl -s -b "$JAR" "${WEB[@]}" $API/auth/me; echo
curl -s -b "$JAR" "${WEB[@]}" -X POST $API/auth/logout; echo
curl -s -b "$JAR" "${WEB[@]}" "${SAME[@]}" -X POST $API/auth/logout; echo
curl -s "${WEB[@]}" $API/auth/me; echo
curl -s "${WEB[@]}" "${SAME[@]}" -d '{broken' $API/auth/login; echo
curl -s -H 'Accept: application/json' -H 'Content-Type: application/json' "${SAME[@]}" -d "$LOGIN" $API/auth/login; echo
rm "$JAR"
```

`Origin` is what a browser sends from the web app's own address, and `Sec-Fetch-Site: same-origin` is how a browser marks a request the page itself made. curl has to say both by hand.

Expected, in order:
1. `HTTP/1.1 200 OK`; a `Set-Cookie` header for the session cookie (its name starts with `laundry`) with `httponly` and `samesite=lax`; the headers `X-Content-Type-Options: nosniff` and a `Cache-Control` that contains `no-store`; and a JSON body with `"user"` and `"csrf_token"`.
2. The same user as JSON.
3. `{"error":{"code":"csrf","message":"Missing or wrong CSRF token."}}` (this logout carried no token and was not marked same-origin).
4. `{"ok":true}`
5. `{"error":{"code":"not_logged_in","message":"Log in first."}}`
6. `{"error":{"code":"invalid_input","message":"The username field is required."}}`
7. `{"error":{"code":"csrf","message":"Log in from the web app."}}` (no `Origin`, so no session).

Stop the background server. If an answer differs, fix the file that owns it (`site/bootstrap/app.php` for the session and the middleware, `site/app/helpers.php` for the error shape, `AuthController` for the login) and repeat this step. The user `smoke` stays in the test database until the next test run rebuilds it.

- [ ] **Step 6: Run all tests and commit**

Run: `(cd site && php artisan test)`
Expected: `Tests:    37 passed`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.3-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.3"/' site/composer.json
git add site README.md
git commit -m "Add user management and the first-office-user command at 0.4.3."
```

---

### Task 5: Tag reads, lookup, simulator list and mat registration

**Files:**
- Create: `site/app/Support/Scan.php`
- Create: `site/app/Http/Controllers/ScanController.php`, `site/app/Http/Controllers/ItemController.php`
- Modify: `site/routes/api.php`
- Test: `site/tests/Feature/ScanTest.php`

**Interfaces:**
- Consumes: `rows()`, `row()`, `marks()`, `fail()`, `config('laundry.simulator')`, `DB::transaction()`.
- Produces, all static on `App\Support\Scan`:
  - `Scan::STATUSES` (the eleven statuses), `Scan::HELD` (`out_for_delivery`, `refused`, `collected`: the statuses in which a driver carries the mat), `Scan::ITEM_SELECT` (`SELECT i.*, t.name AS type_name FROM items i JOIN item_types t ...`)
  - `Scan::hex(mixed $value, string $key): string` — uppercase hex, 8 to 64 characters, else 422 `invalid_tag`
  - `Scan::source(array $in): string` — `simulator` or `c72`
  - `Scan::reads(array $in): array` — unique uppercase EPCs from `{"reads": [{"epc": ...}]}`, in first-heard order
  - `Scan::itemsByEpc(array $epcs, bool $lock = false): array` — item rows keyed by EPC, each with `type_name`; `$lock` adds `FOR UPDATE`
  - `Scan::itemById(int $id, bool $lock = false): array` — 404 when unknown
  - `Scan::view(array $item): array` — `id, sku, type, status, wash_count, current_wash_date, previous_wash_date`
  - `Scan::log(array $item, int $userId, string $type, string $to, string $source, array $extra = []): void` — `from_status` is `$item['status']`; `$extra` may hold `handover_id`, `task_id`, `zone_id`, `note`
  - `Scan::result(string $epc, string $result, ?array $item, ?string $reason = null): array`
  - Every scan endpoint answers `{"results": [{"epc", "result", "item", "reason"?}], "missing": [...]}` with `result` one of `found`, `not_on_list`, `unknown`, `rejected`
  - Routes: `POST /scan/lookup` (inventory, driver, washing, outbound), `GET /sim/items` (same four), `POST /items` (inventory)

- [ ] **Step 1: Write the failing test**

Create `site/tests/Feature/ScanTest.php`:

```php
<?php
/** Tag reads, the lookup that changes nothing, the simulator's mat list, and registering a new mat. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

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
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM item_events'));
    }

    public function test_a_tag_id_made_of_digits_only_is_found(): void
    {
        DB::statement("INSERT INTO items (item_type_id, sku, epc, tid_primary, tid_secondary)
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
        config(['laundry.simulator' => false]);   // what SCANNER_SIMULATOR=false in .env does
        $this->assertError(404, 'not_found', $this->api('GET', '/sim/items'));
        $this->assertError(403, 'simulator_off', $this->api('POST', '/scan/lookup', $this->reads(1)));
        $this->ok($this->api('POST', '/scan/lookup', ['source' => 'c72'] + $this->reads(1)));
    }

    public function test_register_stores_a_new_mat_and_writes_history(): void
    {
        $this->loginAs('inventory');
        $item = $this->ok($this->api('POST', '/items', $this->newMat(['epc' => '4d4f434b00000000000000ff'])))['item'];
        $this->assertSame(['MOCK-0255', 'registered', 0], [$item['sku'], $item['status'], $item['wash_count']]);

        $row = row("SELECT * FROM items WHERE sku = 'MOCK-0255'");
        $this->assertSame(['4D4F434B00000000000000FF', '4D4F434B000A0000000000FF', '4D4F434B000B0000000000FF'],
            [$row['epc'], $row['tid_primary'], $row['tid_secondary']]);

        $event = row('SELECT * FROM item_events WHERE item_id = ?', [$row['id']]);
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
        $this->assertSame(40, (int) DB::scalar('SELECT COUNT(*) FROM items'));
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

Run: `(cd site && php artisan test tests/Feature/ScanTest.php)`
Expected: FAIL, the tests get `404 not_found`.

- [ ] **Step 3: Write the shared scan code**

Create `site/app/Support/Scan.php`:

```php
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
        $sql = self::ITEM_SELECT . ' WHERE i.epc IN (' . marks($epcs) . ')' . ($lock ? ' FOR UPDATE' : '');
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
```

Create `site/app/Http/Controllers/ScanController.php`:

```php
<?php
/** The lookup that changes nothing, and the list of mats the simulator can "hear". */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Scan;
use Illuminate\Http\Request;

final class ScanController extends Controller
{
    public function lookup(Request $request): array
    {
        Scan::source($request->all());
        $epcs = Scan::reads($request->all());
        $items = Scan::itemsByEpc($epcs);
        $results = [];
        foreach ($epcs as $epc) {
            $results[] = isset($items[$epc]) ? Scan::result($epc, 'found', $items[$epc]) : Scan::result($epc, 'unknown', null);
        }
        return ['results' => $results, 'missing' => []];
    }

    /** Simulator only: a real scanner hears any mat near it, so the pile panel must be able to pick any mat. */
    public function simItems(): array
    {
        if (!config('laundry.simulator')) {
            fail(404, 'not_found', 'No such path.');
        }
        return ['items' => rows('SELECT sku, epc FROM items ORDER BY sku')];
    }
}
```

- [ ] **Step 4: Write mat registration**

Create `site/app/Http/Controllers/ItemController.php`:

```php
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
}
```

In `site/routes/api.php` add these lines at the end of the `auth:sanctum` group (directly above its closing `});`):

```php

    Route::post('/scan/lookup', [C\ScanController::class, 'lookup'])->middleware($scanners);
    Route::get('/sim/items', [C\ScanController::class, 'simItems'])->middleware($scanners);
    Route::post('/items', [C\ItemController::class, 'store'])->middleware('role:inventory');
```

- [ ] **Step 5: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/ScanTest.php)`
Expected: `Tests:    10 passed`.

- [ ] **Step 6: Run all tests and commit**

Run: `(cd site && php artisan test)`
Expected: `Tests:    47 passed`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.4-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.4"/' site/composer.json
git add site README.md
git commit -m "Add tag reads, lookup, simulator list and mat registration at 0.4.4."
```

---

### Task 6: Ready, quality check, correction, mat list and mat history

**Files:**
- Modify: `site/app/Http/Controllers/ItemController.php` (add members at the end of the class), `site/routes/api.php`
- Test: `site/tests/Feature/ItemsTest.php`

**Interfaces:**
- Consumes: `Scan::source()`, `Scan::reads()`, `Scan::hex()`, `Scan::itemsByEpc()`, `Scan::itemById()`, `Scan::view()`, `Scan::log()`, `Scan::result()`, `Scan::STATUSES`, `Scan::HELD`, `rows()`, `row()`, `DB::transaction()`, `$request->validate()`.
- Produces:
  - Routes:
    - `POST /items/ready` (inventory) — `{source, reads}` → scan answer
    - `POST /items/qc` (inventory) — `{source, epc, result: pass|rewash|repair|scrap, note?}` → scan answer with one line
    - `POST /items/{id}/correct` (office) — `{status, holder_user_id, zone_id, note}` → `{"item": {...}}`
    - `GET /items` (office, inventory, driver, washing) — `?status=&type=&q=&page=` → `{"items": [...], "page", "per_page", "total"}`
    - `GET /items/{id}` (office, washing) → `{"item": {...}, "events": [...]}`
  - For the office a mat is `Scan::view()` plus `epc, tid_primary, tid_secondary, item_type_id, holder, location`

- [ ] **Step 1: Write the failing test**

Create `site/tests/Feature/ItemsTest.php`:

```php
<?php
/** Releasing mats, the quality check, office corrections, the mat list per role and the mat history. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

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
        DB::statement("UPDATE items SET sku = '50%OFF' WHERE sku = 'MOCK-0001'");
        DB::statement("UPDATE items SET sku = 'A_B' WHERE sku = 'MOCK-0002'");
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

Run: `(cd site && php artisan test tests/Feature/ItemsTest.php)`
Expected: FAIL: `POST /items/ready` and `POST /items/qc` get `404 not_found`, `GET /items` gets `405 method_not_allowed`.

- [ ] **Step 3: Write the implementation**

Add these members at the end of the class in `site/app/Http/Controllers/ItemController.php`:

```php
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
            'status' => 'nullable|in:' . implode(',', Scan::STATUSES),
            'type' => 'nullable|integer|min:0',
            'q' => 'nullable|string|max:64',
            'page' => 'nullable|integer|min:0',
        ]);
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
        if (isset($filter['status'])) {
            $where[] = 'i.status = ?';
            $params[] = $filter['status'];
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
```

In `site/routes/api.php` add these lines at the end of the `auth:sanctum` group (directly above its closing `});`):

```php

    Route::get('/items', [C\ItemController::class, 'index'])->middleware('role:office,inventory,driver,washing');
    Route::post('/items/ready', [C\ItemController::class, 'ready'])->middleware('role:inventory');
    Route::post('/items/qc', [C\ItemController::class, 'qc'])->middleware('role:inventory');
    Route::get('/items/{id}', [C\ItemController::class, 'show'])->middleware('role:office,washing');
    Route::post('/items/{id}/correct', [C\ItemController::class, 'correct'])->middleware('role:office');
```

- [ ] **Step 4: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/ItemsTest.php)`
Expected: `Tests:    12 passed`.

- [ ] **Step 5: Run all tests and commit**

Run: `(cd site && php artisan test)`
Expected: `Tests:    59 passed`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.5-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.5"/' site/composer.json
git add site README.md
git commit -m "Add ready, quality check, correction, mat list and mat history at 0.4.5."
```

---

### Task 7: Handovers: open and scan

**Files:**
- Create: `site/app/Http/Controllers/HandoverController.php`
- Modify: `site/routes/api.php`, `site/tests/ApiTestCase.php` (two helpers)
- Test: `site/tests/Feature/HandoverScanTest.php`

**Interfaces:**
- Consumes: `Scan::source()`, `Scan::reads()`, `Scan::itemsByEpc()`, `Scan::view()`, `Scan::log()`, `Scan::result()`, `Scan::ITEM_SELECT`, `rows()`, `row()`, `marks()`, `today_nl()`, `DB::transaction()`, `$request->validate()`.
- Produces:
  - `HandoverController::TYPES` — per type: `giver` (list of roles), `receiver` (role), `from`, `to`, `two_sided`, `wash`
  - A handover in an answer is `id, type, status, created_at, closed_at, giver {id, name}, receiver {id, name}, items [mat as Scan::view() + given_at, received_at, resolved_at]`
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

Add these two methods at the end of the class in `site/tests/ApiTestCase.php`:

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

Create `site/tests/Feature/HandoverScanTest.php`:

```php
<?php
/** Opening a handover and the two scans: a mat moves only when both sides scanned it, and only once. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

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
        $this->assertSame(2, (int) DB::scalar('SELECT COUNT(*) FROM handovers'));
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
        DB::statement('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver2']]);
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
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM handover_items'));
    }

    public function test_a_mat_only_the_receiver_scanned_is_not_on_the_list_and_stays_put(): void
    {
        $this->setItems([1], 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->scanHandover('inventory', $handover, 1);

        $out = $this->scanHandover('driver', $handover, 1, 2);
        $this->assertSame(['found', 'not_on_list'], array_column($out['results'], 'result'));
        $this->assertSame(['ready', null], [$this->item(2)['status'], $this->item(2)['holder_user_id']]);

        $row = row('SELECT given_at, received_at FROM handover_items WHERE handover_id = ? AND item_id = ?',
            [$handover, $this->item(2)['id']]);
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
        DB::statement("UPDATE items SET current_wash_date = '2026-09-01', previous_wash_date = '2026-08-01', wash_count = 3 WHERE sku = 'MOCK-0001'");
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
        DB::statement("UPDATE items SET current_wash_date = '2026-09-01', previous_wash_date = '2026-08-01', wash_count = 3 WHERE sku = 'MOCK-0001'");
        $handover = $this->openHandover('washing', 'washing_to_inventory', 'inventory');

        $give = $this->scanHandover('washing', $handover, 1, 2);
        $seen = $give['results'][0]['item'];
        $this->assertSame(['ready', 4, today_nl(), '2026-09-01'],
            [$seen['status'], $seen['wash_count'], $seen['current_wash_date'], $seen['previous_wash_date']]);
        $this->scanHandover('washing', $handover, 1, 2);   // the same pile scanned again: no second wash

        $mat = $this->item(1);
        $this->assertSame(['ready', 4, today_nl(), '2026-09-01'],
            [$mat['status'], $mat['wash_count'], $mat['current_wash_date'], $mat['previous_wash_date']]);
        $this->assertSame([1, today_nl(), null],
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
        $this->assertSame(['ready', 1, today_nl()],
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

Run: `(cd site && php artisan test tests/Feature/HandoverScanTest.php)`
Expected: FAIL, the tests get `404 not_found`.

- [ ] **Step 4: Write the implementation**

Create `site/app/Http/Controllers/HandoverController.php`:

```php
<?php
/** Handovers: one person passes a pile to another. A mat moves when both have scanned it (Handover C: when the giver has). */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class HandoverController extends Controller
{
    // Per type: who may give, which role receives, the status a mat must have, the status it gets,
    // whether the receiver's scan is needed, and whether the move counts as a wash.
    // A wash is counted once: when the washing house manager scans the washed mat out (Handover C).
    public const TYPES = [
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

    /** One handover with its two people and its pile. 404 when unknown. */
    private static function view(int $id): array
    {
        $handover = row('SELECT h.*, g.name AS giver_name, r.name AS receiver_name
            FROM handovers h JOIN users g ON g.id = h.giver_id JOIN users r ON r.id = h.receiver_id
            WHERE h.id = ?', [$id]);
        if ($handover === null) {
            fail(404, 'not_found', 'No such handover.');
        }
        $items = rows('SELECT i.*, t.name AS type_name, hi.given_at, hi.received_at, hi.resolved_at
            FROM handover_items hi JOIN items i ON i.id = hi.item_id JOIN item_types t ON t.id = i.item_type_id
            WHERE hi.handover_id = ? ORDER BY i.sku', [$id]);
        return [
            'id' => $handover['id'],
            'type' => $handover['type'],
            'status' => $handover['status'],
            'created_at' => $handover['created_at'],
            'closed_at' => $handover['closed_at'],
            'giver' => ['id' => $handover['giver_id'], 'name' => $handover['giver_name']],
            'receiver' => ['id' => $handover['receiver_id'], 'name' => $handover['receiver_name']],
            'items' => array_map(fn (array $item) => Scan::view($item)
                + ['given_at' => $item['given_at'], 'received_at' => $item['received_at'], 'resolved_at' => $item['resolved_at']], $items),
        ];
    }

    /** The caller opens a handover as giver. */
    public function open(Request $request): array
    {
        $in = $request->validate([
            'type' => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'receiver_id' => 'required|integer|min:0',
        ]);
        $type = $in['type'];
        $receiverId = (int) $in['receiver_id'];
        $user = $request->user();
        $rule = self::TYPES[$type];
        if (!in_array($user->role, $rule['giver'], true)) {
            fail(403, 'forbidden', "Your role cannot give in a $type handover.");
        }
        if (DB::scalar('SELECT 1 FROM users WHERE id = ? AND role = ? AND is_active = 1', [$receiverId, $rule['receiver']]) === null) {
            fail(422, 'invalid_reference', "The receiver must be an active {$rule['receiver']} user.");
        }
        return DB::transaction(function () use ($type, $receiverId, $user): array {
            // Opening the same handover twice (a retry on a bad connection) gives the open one back.
            $open = row("SELECT id FROM handovers WHERE type = ? AND giver_id = ? AND receiver_id = ? AND status = 'open' FOR UPDATE",
                [$type, $user->id, $receiverId]);
            if ($open !== null) {
                return ['handover' => self::view($open['id'])];
            }
            DB::insert('INSERT INTO handovers (type, giver_id, receiver_id) VALUES (?, ?, ?)', [$type, $user->id, $receiverId]);
            return ['handover' => self::view((int) DB::getPdo()->lastInsertId())];
        });
    }

    /** One scan into a handover. Who calls decides whether it counts as giving or as receiving. */
    public function scan(Request $request, int $id): array
    {
        $source = Scan::source($request->all());
        $epcs = Scan::reads($request->all());
        $user = $request->user();
        return DB::transaction(function () use ($source, $epcs, $user, $id): array {
            $handover = row('SELECT * FROM handovers WHERE id = ? FOR UPDATE', [$id]);
            if ($handover === null) {
                fail(404, 'not_found', 'No such handover.');
            }
            $side = match ($user->id) {
                $handover['giver_id'] => 'give',
                $handover['receiver_id'] => 'receive',
                default => fail(403, 'forbidden', 'This is not your handover.'),
            };
            if ($handover['status'] !== 'open') {
                fail(409, 'closed', 'This handover is closed.');
            }

            $items = Scan::itemsByEpc($epcs, true);
            $lines = $items === [] ? [] : array_column(
                rows('SELECT * FROM handover_items WHERE handover_id = ? AND item_id IN (' . marks($items) . ')',
                    [$id, ...array_column($items, 'id')]),
                null, 'item_id');

            $results = [];
            foreach ($epcs as $epc) {
                $item = $items[$epc] ?? null;
                if ($item === null) {
                    $results[] = Scan::result($epc, 'unknown', null);
                    continue;
                }
                $line = $lines[$item['id']] ?? null;
                $results[] = $side === 'give'
                    ? self::give($handover, $item, $line, $user, $source)
                    : self::receive($handover, $item, $line, $user, $source);
            }

            // The receiver sees which mats the giver scanned and he has not.
            $missing = $side !== 'receive' ? [] : array_map(Scan::view(...), rows(Scan::ITEM_SELECT
                . ' JOIN handover_items hi ON hi.item_id = i.id
                    WHERE hi.handover_id = ? AND hi.given_at IS NOT NULL AND hi.received_at IS NULL ORDER BY i.sku', [$id]));
            return ['results' => $results, 'missing' => $missing];
        });
    }

    /** The giver scans one mat. $line is the mat's row in this handover, or null when it has none yet. */
    private static function give(array $handover, array $item, ?array $line, User $user, string $source): array
    {
        $rule = self::TYPES[$handover['type']];
        $epc = $item['epc'];
        if ($line !== null && $line['given_at'] !== null) {
            return Scan::result($epc, 'found', $item);   // a second scan: nothing to change
        }
        if ($item['status'] !== $rule['from']) {
            return Scan::result($epc, 'rejected', $item, "{$item['sku']} is {$item['status']}, not {$rule['from']}.");
        }
        if ($user->role === 'driver' && $item['holder_user_id'] !== $user->id) {
            return Scan::result($epc, 'rejected', $item, "{$item['sku']} is not on your van.");
        }
        // "In another handover" means: given there and still waiting for that receiver's scan.
        $elsewhere = DB::scalar("SELECT 1 FROM handover_items hi JOIN handovers h ON h.id = hi.handover_id
            WHERE hi.item_id = ? AND hi.given_at IS NOT NULL AND hi.received_at IS NULL
              AND h.status = 'open' AND h.type <> 'washing_to_inventory' AND h.id <> ? LIMIT 1",
            [$item['id'], $handover['id']]);
        if ($elsewhere !== null) {
            return Scan::result($epc, 'rejected', $item, "{$item['sku']} is already in another open handover.");
        }

        DB::insert('INSERT INTO handover_items (handover_id, item_id, given_at) VALUES (?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE given_at = UTC_TIMESTAMP()', [$handover['id'], $item['id']]);
        $receivedAlready = $line !== null && $line['received_at'] !== null;
        $after = !$rule['two_sided'] || $receivedAlready ? self::move($handover, $item) + $item : $item;
        Scan::log($item, $user->id, 'handover_give', $after['status'], $source, ['handover_id' => $handover['id']]);
        return Scan::result($epc, 'found', $after);
    }

    /** The receiver scans one mat. */
    private static function receive(array $handover, array $item, ?array $line, User $user, string $source): array
    {
        $epc = $item['epc'];
        $given = $line !== null && $line['given_at'] !== null;
        $result = $given ? 'found' : 'not_on_list';
        if ($line !== null && $line['received_at'] !== null) {
            return Scan::result($epc, $result, $item);   // a second scan: nothing to change
        }

        // A mat the giver did not scan is still recorded: it shows up as a handover issue for the office.
        DB::insert('INSERT INTO handover_items (handover_id, item_id, received_at) VALUES (?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE received_at = UTC_TIMESTAMP()', [$handover['id'], $item['id']]);
        $after = $given && self::TYPES[$handover['type']]['two_sided'] ? self::move($handover, $item) + $item : $item;
        Scan::log($item, $user->id, 'handover_receive', $after['status'], $source, ['handover_id' => $handover['id']]);
        return Scan::result($epc, $result, $after);
    }

    /**
     * Moves one mat to the status its handover type gives it, only if it still has the status the type expects.
     * Returns the fields that changed, or nothing when the mat did not move.
     * This "set new where still old" in one query is what makes a second scan harmless. Never load, change and save.
     */
    private static function move(array $handover, array $item): array
    {
        $rule = self::TYPES[$handover['type']];
        $holder = $rule['to'] === 'out_for_delivery' ? $handover['receiver_id'] : null;
        $today = today_nl();
        // Keep this order: MySQL and MariaDB assign left to right, so previous_wash_date gets the old current date.
        $wash = $rule['wash'] ? ', previous_wash_date = current_wash_date, current_wash_date = ?, wash_count = wash_count + 1' : '';
        $params = [$rule['to'], $holder, ...($rule['wash'] ? [$today] : []), $item['id'], $rule['from']];
        $moved = DB::update("UPDATE items SET status = ?, holder_user_id = ?$wash WHERE id = ? AND status = ?", $params);
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
}
```

In `site/routes/api.php` add these lines at the end of the `auth:sanctum` group (directly above its closing `});`):

```php

    Route::post('/handovers', [C\HandoverController::class, 'open'])->middleware($scanners);
    Route::post('/handovers/{id}/scan', [C\HandoverController::class, 'scan'])->middleware($scanners);
```

- [ ] **Step 5: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/HandoverScanTest.php)`
Expected: `Tests:    14 passed`.

`test_only_the_two_people_of_a_handover_may_scan_into_it` expects `403 forbidden` for the office too: the route's role check refuses the office before the controller runs.

- [ ] **Step 6: Run all tests and commit**

Run: `(cd site && php artisan test)`
Expected: `Tests:    73 passed`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.6-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.6"/' site/composer.json
git add site README.md
git commit -m "Add handover opening and two-sided scanning at 0.4.6."
```

---

### Task 8: Handovers: close, lists, issues and receivers

**Files:**
- Modify: `site/app/Http/Controllers/HandoverController.php` (add members at the end of the class), `site/routes/api.php`
- Test: `site/tests/Feature/HandoverCloseTest.php`

**Interfaces:**
- Consumes: `HandoverController::TYPES`, `HandoverController::view()`, `rows()`, `row()`, `DB::transaction()`, `$request->validate()`, and the test helpers `openHandover()` and `scanHandover()` from Task 7.
- Produces routes:
  - `POST /handovers/{id}/close` (all roles) — the receiver closes; for `washing_to_inventory` the giver or the receiver; the office may close any → `{"handover": {...}}`
  - `GET /handovers` (all roles) — office: all; others: the ones they give or receive → `{"handovers": [{id, type, status, created_at, closed_at, giver_id, giver_name, receiver_id, receiver_name, given, received}]}`
  - `GET /handovers?issues=1` (office) → `{"issues": [{handover_id, type, closed_at, giver_name, receiver_name, given_at, received_at, item_id, sku, type_name, status}]}`
  - `GET /handovers/{id}` (all roles; non-office only for their own) → `{"handover": {...}}`
  - `POST /handovers/{id}/items/{itemId}/resolve` (office) — `{note}` → `{"ok": true}`
  - `GET /handover-receivers?type=...` (inventory, driver, washing, outbound) → `{"rows": [{id, name}]}`

A handover issue is a mat in a **closed** handover with only one of the two scans, not yet resolved. In `washing_to_inventory` a mat without the receiver's scan is normal and is not an issue.

- [ ] **Step 1: Write the failing test**

Create `site/tests/Feature/HandoverCloseTest.php`:

```php
<?php
/** Closing a handover, the handover lists per role, the office's issue list, and the list of possible receivers. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

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
        $row = row('SELECT resolved_by, resolution_note, resolved_at FROM handover_items WHERE handover_id = ? AND item_id = ?',
            [$handover, $given]);
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

        DB::statement('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver2']]);
        $rows = $this->ok($this->api('GET', '/handover-receivers?type=inventory_to_driver'))['rows'];
        $this->assertSame([['id' => $this->users['driver'], 'name' => 'Driver']], $rows);
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `(cd site && php artisan test tests/Feature/HandoverCloseTest.php)`
Expected: FAIL: `close` and `handover-receivers` get `404 not_found`, `GET /handovers` gets `405 method_not_allowed`.

- [ ] **Step 3: Write the implementation**

Add these members at the end of the class in `site/app/Http/Controllers/HandoverController.php`:

```php
    // A mat in a closed handover with only one of its two scans, not handled yet by the office.
    // In Handover C the receiver's scan is optional, so "given only" is normal there.
    private const ISSUE = "h.status = 'closed' AND hi.resolved_at IS NULL
        AND (hi.given_at IS NULL OR (hi.received_at IS NULL AND h.type <> 'washing_to_inventory'))";

    public function close(Request $request, int $id): array
    {
        $user = $request->user();
        return DB::transaction(function () use ($user, $id): array {
            $handover = row('SELECT * FROM handovers WHERE id = ? FOR UPDATE', [$id]);
            if ($handover === null) {
                fail(404, 'not_found', 'No such handover.');
            }
            // The receiver says "done". In Handover C the receiver may never show up, so the giver may close too.
            $mayClose = self::TYPES[$handover['type']]['two_sided']
                ? [$handover['receiver_id']]
                : [$handover['giver_id'], $handover['receiver_id']];
            if ($user->role !== 'office' && !in_array($user->id, $mayClose, true)) {
                fail(403, 'forbidden', 'Only the receiver closes this handover.');
            }
            if ($handover['status'] !== 'open') {
                fail(409, 'closed', 'This handover is already closed.');
            }
            DB::update("UPDATE handovers SET status = 'closed', closed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'open'", [$id]);
            return ['handover' => self::view($id)];
        });
    }

    public function show(Request $request, int $id): array
    {
        $user = $request->user();
        $handover = self::view($id);
        $mine = in_array($user->id, [$handover['giver']['id'], $handover['receiver']['id']], true);
        if ($user->role !== 'office' && !$mine) {
            fail(403, 'forbidden', 'This is not your handover.');
        }
        return ['handover' => $handover];
    }

    public function index(Request $request): array
    {
        $user = $request->user();
        $office = $user->role === 'office';
        if ($office && $request->query('issues') === '1') {
            return ['issues' => rows('SELECT h.id AS handover_id, h.type, h.closed_at, g.name AS giver_name, r.name AS receiver_name,
                    hi.given_at, hi.received_at, i.id AS item_id, i.sku, t.name AS type_name, i.status
                FROM handover_items hi
                JOIN handovers h ON h.id = hi.handover_id
                JOIN items i ON i.id = hi.item_id
                JOIN item_types t ON t.id = i.item_type_id
                JOIN users g ON g.id = h.giver_id
                JOIN users r ON r.id = h.receiver_id
                WHERE ' . self::ISSUE . ' ORDER BY h.closed_at DESC, i.sku')];
        }
        $where = $office ? '1 = 1' : '(h.giver_id = ? OR h.receiver_id = ?)';
        $params = $office ? [] : [$user->id, $user->id];
        // ponytail: the newest 100, no paging. Add paging when the office needs to look further back.
        return ['handovers' => rows("SELECT h.id, h.type, h.status, h.created_at, h.closed_at,
                h.giver_id, g.name AS giver_name, h.receiver_id, r.name AS receiver_name,
                (SELECT COUNT(*) FROM handover_items hi WHERE hi.handover_id = h.id AND hi.given_at IS NOT NULL) AS given,
                (SELECT COUNT(*) FROM handover_items hi WHERE hi.handover_id = h.id AND hi.received_at IS NOT NULL) AS received
            FROM handovers h JOIN users g ON g.id = h.giver_id JOIN users r ON r.id = h.receiver_id
            WHERE $where ORDER BY h.status = 'open' DESC, h.id DESC LIMIT 100", $params)];
    }

    /** The office marks one handover issue as handled, with a note. */
    public function resolve(Request $request, int $id, int $itemId): array
    {
        $in = $request->validate(['note' => 'required|string|max:500']);
        $resolved = DB::update('UPDATE handover_items hi JOIN handovers h ON h.id = hi.handover_id
            SET hi.resolved_at = UTC_TIMESTAMP(), hi.resolved_by = ?, hi.resolution_note = ?
            WHERE hi.handover_id = ? AND hi.item_id = ? AND ' . self::ISSUE,
            [$request->user()->id, $in['note'], $id, $itemId]);
        if ($resolved !== 1) {
            fail(409, 'not_an_issue', 'This mat is not an open handover issue.');
        }
        return ['ok' => true];
    }

    /** The people a giver can pick as receiver for one handover type. */
    public function receivers(Request $request): array
    {
        $in = $request->validate(['type' => 'required|in:' . implode(',', array_keys(self::TYPES))]);
        $rule = self::TYPES[$in['type']];
        if (!in_array($request->user()->role, $rule['giver'], true)) {
            fail(403, 'forbidden', 'Your role cannot give in this handover.');
        }
        return ['rows' => rows('SELECT id, name FROM users WHERE role = ? AND is_active = 1 ORDER BY name', [$rule['receiver']])];
    }
```

In `site/routes/api.php` add these lines at the end of the `auth:sanctum` group (directly above its closing `});`):

```php

    Route::get('/handovers', [C\HandoverController::class, 'index']);
    Route::get('/handovers/{id}', [C\HandoverController::class, 'show']);
    Route::post('/handovers/{id}/close', [C\HandoverController::class, 'close']);
    Route::post('/handovers/{id}/items/{itemId}/resolve', [C\HandoverController::class, 'resolve'])->middleware('role:office');
    Route::get('/handover-receivers', [C\HandoverController::class, 'receivers'])->middleware($scanners);
```

- [ ] **Step 4: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/HandoverCloseTest.php)`
Expected: `Tests:    10 passed`.

- [ ] **Step 5: Run all tests and commit**

Run: `(cd site && php artisan test)`
Expected: `Tests:    83 passed`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.7-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.7"/' site/composer.json
git add site README.md
git commit -m "Add handover closing, lists, issues and receivers at 0.4.7."
```

---

### Task 9: Contracts

**Files:**
- Create: `site/app/Http/Controllers/ContractController.php`
- Modify: `site/routes/api.php`
- Test: `site/tests/Feature/ContractsTest.php`

**Interfaces:**
- Consumes: `rows()`, `row()`, `today_nl()`, `fail()`, `DB::transaction()`, `$request->validate()`.
- Produces:
  - A contract in an answer is `id, customer_id, customer_name, swap_interval_days, status, start_date, end_date, lines [{id, zone_id, zone_name, building_id, building_name, item_type_id, type_name, quantity}]`
  - Routes, office only:
    - `GET /contracts` → `{"rows": [...]}`
    - `POST /contracts` — `{customer_id, swap_interval_days, start_date, lines?: [{zone_id, item_type_id, quantity}]}` → `{"contract": {...}}`
    - `PATCH /contracts/{id}` — any of `swap_interval_days`, `start_date`, `lines` → `{"contract": {...}}`
    - `POST /contracts/{id}/activate` → `{"contract": {...}, "task_ids": [...]}`
    - `POST /contracts/{id}/end` → `{"contract": {...}}`

Rules: a contract starts as `draft`. Lines and start date change only while it is a draft. Activating creates one `delivery` task per building that has lines, due on the start date. n (`swap_interval_days`) may change while the contract is active; the open swap tasks of that contract then move by the difference. Ending cancels the open swap tasks.

- [ ] **Step 1: Write the failing test**

Create `site/tests/Feature/ContractsTest.php`:

```php
<?php
/** Contracts: lines per zone, activation into delivery tasks, changing n, and ending. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class ContractsTest extends ApiTestCase
{
    private function newContract(array $over = []): array
    {
        return $over + ['customer_id' => 1, 'swap_interval_days' => 7, 'start_date' => '2026-11-01',
            'lines' => [['zone_id' => 1, 'item_type_id' => 1, 'quantity' => 2], ['zone_id' => 2, 'item_type_id' => 3, 'quantity' => 1]]];
    }

    private function tasksOf(int $contractId): array
    {
        return rows('SELECT * FROM tasks WHERE contract_id = ? ORDER BY id', [$contractId]);
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
        $this->assertSame(2, (int) DB::scalar('SELECT COUNT(*) FROM contracts'));
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
        $this->assertSame('draft', DB::scalar('SELECT status FROM contracts WHERE id = ?', [$id]));
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
        DB::statement("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('swap', 1, 1, '2026-11-02')");
        DB::statement("INSERT INTO tasks (type, contract_id, building_id, due_date, status, completed_at, photo_path, signature_path)
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
        DB::statement("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('swap', 1, 1, '2026-11-02')");

        $contract = $this->ok($this->api('POST', '/contracts/1/end'))['contract'];
        $this->assertSame(['ended', max(today_nl(), $contract['start_date'])], [$contract['status'], $contract['end_date']]);
        $this->assertSame(['open', 'cancelled'], array_column($this->tasksOf(1), 'status'));   // delivery stays, swap is cancelled

        $this->assertError(409, 'not_active', $this->api('POST', '/contracts/1/end'));
        $this->assertError(409, 'ended', $this->api('PATCH', '/contracts/1', ['swap_interval_days' => 10]));
    }

    public function test_a_contract_can_be_ended_before_its_start_date(): void
    {
        DB::statement("UPDATE contracts SET start_date = '2099-01-01' WHERE id = 1");
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

Run: `(cd site && php artisan test tests/Feature/ContractsTest.php)`
Expected: FAIL, the tests get `404 not_found`.

- [ ] **Step 3: Write the implementation**

Create `site/app/Http/Controllers/ContractController.php`:

```php
<?php
/** Contracts: how many mats of which type lie in which zone, and n, the days between swaps. Activation creates the delivery tasks. */
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContractController extends Controller
{
    // n: the days between two swaps.
    private const INTERVAL_RULE = 'integer|between:1,365';

    private const LINE_RULES = [
        'lines' => 'array|list',
        'lines.*.zone_id' => 'required|integer|min:0',
        'lines.*.item_type_id' => 'required|integer|min:0',
        'lines.*.quantity' => 'required|integer|between:1,1000',
    ];

    /** One contract with its lines. 404 when unknown. */
    private static function view(int $id): array
    {
        $contract = row('SELECT c.id, c.customer_id, cu.name AS customer_name, c.swap_interval_days, c.status, c.start_date, c.end_date
            FROM contracts c JOIN customers cu ON cu.id = c.customer_id WHERE c.id = ?', [$id]);
        if ($contract === null) {
            fail(404, 'not_found', 'No such contract.');
        }
        $contract['lines'] = rows('SELECT l.id, l.zone_id, z.name AS zone_name, z.building_id, b.name AS building_name,
                l.item_type_id, t.name AS type_name, l.quantity
            FROM contract_lines l
            JOIN zones z ON z.id = l.zone_id
            JOIN buildings b ON b.id = z.building_id
            JOIN item_types t ON t.id = l.item_type_id
            WHERE l.contract_id = ? ORDER BY b.name, z.name, t.name', [$id]);
        return $contract;
    }

    /** Locks one contract row for the rest of the transaction. */
    private static function locked(int $id): array
    {
        $contract = row('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [$id]);
        if ($contract === null) {
            fail(404, 'not_found', 'No such contract.');
        }
        return $contract;
    }

    /** The validated lines as [zone id, type id, quantity]. Every zone must belong to the contract's customer. */
    private static function lines(array $lines, int $customerId): array
    {
        $out = [];
        foreach ($lines as $line) {
            $zoneId = (int) $line['zone_id'];
            $ofCustomer = DB::scalar('SELECT 1 FROM zones z JOIN buildings b ON b.id = z.building_id WHERE z.id = ? AND b.customer_id = ?',
                [$zoneId, $customerId]);
            if ($ofCustomer === null) {
                fail(422, 'invalid_reference', "Zone $zoneId does not belong to this customer.");
            }
            $out[] = [$zoneId, (int) $line['item_type_id'], (int) $line['quantity']];
        }
        return $out;
    }

    /** Replaces the lines. Only ever called for a draft, when no task and no history depends on them yet. */
    private static function saveLines(int $contractId, array $lines): void
    {
        DB::delete('DELETE FROM contract_lines WHERE contract_id = ?', [$contractId]);
        foreach ($lines as [$zoneId, $typeId, $quantity]) {
            DB::insert('INSERT INTO contract_lines (contract_id, zone_id, item_type_id, quantity) VALUES (?, ?, ?, ?)',
                [$contractId, $zoneId, $typeId, $quantity]);
        }
    }

    public function index(): array
    {
        // ponytail: one query per contract. Fine for tens of contracts; join and group when there are hundreds.
        $ids = array_column(rows('SELECT id FROM contracts ORDER BY id DESC'), 'id');
        return ['rows' => array_map(self::view(...), $ids)];
    }

    public function store(Request $request): array
    {
        $in = $request->validate([
            'customer_id' => 'required|integer|min:0',
            'swap_interval_days' => 'required|' . self::INTERVAL_RULE,
            'start_date' => 'required|date_format:Y-m-d',
        ] + self::LINE_RULES);
        $customerId = (int) $in['customer_id'];
        if (DB::scalar('SELECT 1 FROM customers WHERE id = ?', [$customerId]) === null) {
            fail(422, 'invalid_reference', 'Unknown customer.');
        }
        $lines = self::lines($in['lines'] ?? [], $customerId);
        return DB::transaction(function () use ($in, $customerId, $lines): array {
            DB::insert('INSERT INTO contracts (customer_id, swap_interval_days, start_date) VALUES (?, ?, ?)',
                [$customerId, (int) $in['swap_interval_days'], $in['start_date']]);
            $id = (int) DB::getPdo()->lastInsertId();
            self::saveLines($id, $lines);
            return ['contract' => self::view($id)];
        });
    }

    /** Changes only the fields that were sent. */
    public function update(Request $request, int $id): array
    {
        $in = $request->validate([
            'swap_interval_days' => 'sometimes|required|' . self::INTERVAL_RULE,
            'start_date' => 'sometimes|required|date_format:Y-m-d',
        ] + self::LINE_RULES);
        $hasLines = $request->has('lines');   // an empty list counts: it removes every line
        return DB::transaction(function () use ($in, $hasLines, $id): array {
            $contract = self::locked($id);
            if ($contract['status'] === 'ended') {
                fail(409, 'ended', 'An ended contract cannot be changed.');
            }
            if (($hasLines || isset($in['start_date'])) && $contract['status'] !== 'draft') {
                fail(409, 'not_draft', 'Lines and start date can only be changed while the contract is a draft.');
            }
            if (isset($in['start_date'])) {
                DB::update('UPDATE contracts SET start_date = ? WHERE id = ?', [$in['start_date'], $id]);
            }
            if ($hasLines) {
                self::saveLines($id, self::lines($in['lines'] ?? [], $contract['customer_id']));
            }
            if (isset($in['swap_interval_days'])) {
                $days = (int) $in['swap_interval_days'];
                DB::update('UPDATE contracts SET swap_interval_days = ? WHERE id = ?', [$days, $id]);
                // An open swap task is due on D + old n. Moving it by the difference makes it D + new n.
                DB::update("UPDATE tasks SET due_date = due_date + INTERVAL ? DAY WHERE contract_id = ? AND type = 'swap' AND status = 'open'",
                    [$days - $contract['swap_interval_days'], $id]);
            }
            return ['contract' => self::view($id)];
        });
    }

    /** Starts a draft contract: one delivery task per building that has lines, due on the start date. */
    public function activate(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $contract = self::locked($id);
            if ($contract['status'] !== 'draft') {
                fail(409, 'not_draft', "This contract is already {$contract['status']}.");
            }
            $buildingIds = array_column(rows('SELECT DISTINCT z.building_id FROM contract_lines l JOIN zones z ON z.id = l.zone_id
                WHERE l.contract_id = ? ORDER BY z.building_id', [$id]), 'building_id');
            if ($buildingIds === []) {
                fail(422, 'no_lines', 'A contract needs at least one line before it can start.');
            }
            DB::update("UPDATE contracts SET status = 'active' WHERE id = ? AND status = 'draft'", [$id]);
            $taskIds = [];
            foreach ($buildingIds as $buildingId) {
                DB::insert("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('delivery', ?, ?, ?)",
                    [$id, $buildingId, $contract['start_date']]);
                $taskIds[] = (int) DB::getPdo()->lastInsertId();
            }
            return ['contract' => self::view($id), 'task_ids' => $taskIds];
        });
    }

    /** Ends an active contract and cancels its open swap tasks. */
    public function end(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $contract = self::locked($id);
            if ($contract['status'] !== 'active') {
                fail(409, 'not_active', "Only an active contract can be ended. This one is {$contract['status']}.");
            }
            // The database refuses an end date before the start date, so a contract ended early ends on its start date.
            DB::update("UPDATE contracts SET status = 'ended', end_date = ? WHERE id = ? AND status = 'active'",
                [max(today_nl(), $contract['start_date']), $id]);
            DB::update("UPDATE tasks SET status = 'cancelled' WHERE contract_id = ? AND type = 'swap' AND status = 'open'", [$id]);
            return ['contract' => self::view($id)];
        });
    }
}
```

A mat type that does not exist, or the same zone and type twice, is refused by the database (`422 invalid_reference`, `409 duplicate`), and the transaction takes the half-made contract away again.

In `site/routes/api.php` add these lines at the end of the `auth:sanctum` group (directly above its closing `});`):

```php

    Route::get('/contracts', [C\ContractController::class, 'index'])->middleware('role:office');
    Route::post('/contracts', [C\ContractController::class, 'store'])->middleware('role:office');
    Route::patch('/contracts/{id}', [C\ContractController::class, 'update'])->middleware('role:office');
    Route::post('/contracts/{id}/activate', [C\ContractController::class, 'activate'])->middleware('role:office');
    Route::post('/contracts/{id}/end', [C\ContractController::class, 'end'])->middleware('role:office');
```

- [ ] **Step 4: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/ContractsTest.php)`
Expected: `Tests:    9 passed`.

- [ ] **Step 5: Run all tests and commit**

Run: `(cd site && php artisan test)`
Expected: `Tests:    92 passed`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.8-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.8"/' site/composer.json
git add site README.md
git commit -m "Add contracts with activation and ending at 0.4.8."
```

---

### Task 10: Tasks: lists per role, assignment, needs per driver, collection

**Files:**
- Create: `site/app/Http/Controllers/TaskController.php`
- Modify: `site/routes/api.php`, `site/tests/ApiTestCase.php` (one helper)
- Test: `site/tests/Feature/TasksTest.php`

**Interfaces:**
- Consumes: `rows()`, `row()`, `fail()`, `DB::transaction()`, `$request->validate()`, and `POST /contracts/{id}/activate` from Task 9.
- Produces:
  - A task in an answer is `id, type, status, due_date, completed_at, contract_id, building_id, previous_task_id, assigned_to, assigned_name, signed_name, issue_note, has_proof, building_name, street, postcode, city, area_name, customer_id, customer_name, contact_name, contact_phone`
  - For a driver each task also has `zones [{id, name}]`, `expected [{zone_id, zone_name, item_type_id, type_name, quantity}]`, `in_building [{id, sku, epc, zone_id, type}]`
  - `TaskController::view(int $id): array` (private) — one task; 404 when unknown
  - Routes:
    - `GET /tasks` — office: `{"tasks": [...]}` with filters `status`, `type`, `assigned_to`; driver: `{"tasks": [...]}` of his own open tasks with the stop details; inventory and outbound: `{"drivers": [{driver {id, name}, needs [{item_type_id, type, needed, carried, remaining}]}]}`
    - `POST /tasks` (office) — `{contract_id, building_id, due_date}` creates a `collection` task → `{"task": {...}}`
    - `PATCH /tasks/{id}` (office) — any of `assigned_to` (driver id or null), `due_date`, `status: "cancelled"` → `{"task": {...}}`
  - Test helper `activateContract(int $contractId, ?string $driver = null): array` — returns the task ids

- [ ] **Step 1: Add the test helper**

Add this method at the end of the class in `site/tests/ApiTestCase.php`:

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

Create `site/tests/Feature/TasksTest.php`:

```php
<?php
/** Task lists per role, assigning a driver, how many mats each driver still needs, and collection tasks. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

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
        DB::statement('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver2']]);
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

Run: `(cd site && php artisan test tests/Feature/TasksTest.php)`
Expected: FAIL: the helper's `PATCH /tasks/{id}` and every `GET /tasks` get `404 not_found`.

- [ ] **Step 4: Write the implementation**

Create `site/app/Http/Controllers/TaskController.php`:

```php
<?php
/** Tasks: one visit to one building. Lists per role, assignment, closing a stop with proof, and the next swap. */
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TaskController extends Controller
{
    private const SELECT = 'SELECT t.id, t.type, t.status, t.due_date, t.completed_at, t.contract_id, t.building_id, t.previous_task_id,
            t.assigned_to, u.name AS assigned_name, t.signed_name, t.issue_note, t.photo_path IS NOT NULL AS has_proof,
            b.name AS building_name, b.street, b.postcode, b.city, a.name AS area_name,
            c.id AS customer_id, c.name AS customer_name, c.contact_name, c.contact_phone
        FROM tasks t
        JOIN buildings b ON b.id = t.building_id
        JOIN areas a ON a.id = b.area_id
        JOIN customers c ON c.id = b.customer_id
        LEFT JOIN users u ON u.id = t.assigned_to';

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
        $task['in_building'] = rows("SELECT i.id, i.sku, i.epc, i.zone_id, it.name AS type
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
            $tasks = rows(self::SELECT . " WHERE t.assigned_to = ? AND t.status = 'open' ORDER BY t.due_date, t.id", [$user->id]);
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

    /** The office creates a collection task: pick-up only, when a contract ends. */
    public function store(Request $request): array
    {
        $in = $request->validate([
            'contract_id' => 'required|integer|min:0',
            'building_id' => 'required|integer|min:0',
            'due_date' => 'required|date_format:Y-m-d',
        ]);
        $contractId = (int) $in['contract_id'];
        $buildingId = (int) $in['building_id'];

        $contract = row('SELECT status FROM contracts WHERE id = ?', [$contractId]);
        if ($contract === null) {
            fail(404, 'not_found', 'No such contract.');
        }
        if ($contract['status'] === 'draft') {
            fail(409, 'not_started', 'A draft contract has no mats to collect.');
        }
        $inContract = DB::scalar('SELECT 1 FROM contract_lines l JOIN zones z ON z.id = l.zone_id WHERE l.contract_id = ? AND z.building_id = ? LIMIT 1',
            [$contractId, $buildingId]);
        if ($inContract === null) {
            fail(422, 'invalid_reference', 'This building is not part of that contract.');
        }
        DB::insert("INSERT INTO tasks (type, contract_id, building_id, due_date) VALUES ('collection', ?, ?, ?)",
            [$contractId, $buildingId, $in['due_date']]);
        return ['task' => self::view((int) DB::getPdo()->lastInsertId())];
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
}
```

In `site/routes/api.php` add these lines at the end of the `auth:sanctum` group (directly above its closing `});`):

```php

    Route::get('/tasks', [C\TaskController::class, 'index'])->middleware('role:office,driver,inventory,outbound');
    Route::post('/tasks', [C\TaskController::class, 'store'])->middleware('role:office');
    Route::patch('/tasks/{id}', [C\TaskController::class, 'update'])->middleware('role:office');
```

- [ ] **Step 5: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/TasksTest.php)`
Expected: `Tests:    8 passed`.

- [ ] **Step 6: Run all tests and commit**

Run: `(cd site && php artisan test)`
Expected: `Tests:    100 passed`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.9-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.9"/' site/composer.json
git add site README.md
git commit -m "Add task lists per role, assignment and collection tasks at 0.4.9."
```

---

### Task 11: Closing a stop with proof, and the next swap

**Files:**
- Modify: `site/app/Http/Controllers/TaskController.php` (imports, and members at the end of the class), `site/routes/api.php`, `site/tests/ApiTestCase.php` (two helpers)
- Test: `site/tests/Feature/TaskCompleteTest.php`

**Interfaces:**
- Consumes: `Scan::source()`, `Scan::hex()`, `Scan::itemsByEpc()`, `Scan::log()`, `TaskController::view()`, `today_nl()`, `days_after()`, `rows()`, `row()`, `DB::transaction()`, Laravel's `Storage` disk `local` (the folder `site/storage/app/private`, outside the web root), and the test helper `activateContract()` from Task 10.
- Produces:
  - `POST /tasks/{id}/complete` (driver) — a form (`multipart/form-data`): the field `payload` holds the JSON `{source, drops: [{zone_id, epcs: [...]}], pickups: [...], refused: [...], signed_name, issue_note}`, plus the files `photo` (JPEG, at most 5 MB) and `signature` (PNG, at most 1 MB) → `{"task": {...}, "next_task_id": int|null}`
  - `GET /tasks/{id}/photo` and `GET /tasks/{id}/signature` (office) → the file
  - Test helpers `proofFile(string $kind, int $padBytes = 0): UploadedFile` and `epcs(int ...$mats): array`

Rules for closing a stop:

1. **Refused whole** (nothing is saved): the task is not open (`409`) or not assigned to the caller (`403`); a tag no mat has (`422`); a dropped or refused mat that is not `out_for_delivery` on the caller's van (`409`); a drop zone outside the task's building (`422`); a picked-up mat that is not `at_customer` in this building (`409`); the same mat in two lists (`422`); a drop on a collection task (`422`); a missing or wrong photo or signature (`422`).
2. **Needs a note** (`422 note_required` without one): the drops do not match the contract lines of this building exactly (per zone and mat type), or the pick-ups are not exactly the mats lying in this building. A delivery expects no pick-ups; a collection expects no drops.
3. **On success:** dropped mats become `at_customer` in their zone, refused mats become `refused` and stay with the driver, picked-up mats become `collected` with the driver. The two files are stored in the folder `proofs` of the `local` disk with a server-made name. The task becomes `done`. For a delivery or swap of an active contract the next swap task is created, due on today + n, not assigned.

- [ ] **Step 1: Add the test helpers**

Add these two methods at the end of the class in `site/tests/ApiTestCase.php`:

```php
    /** A real 8x8 image of the given kind ("jpeg" or "png") as an upload. $padBytes makes it report a bigger size. */
    protected function proofFile(string $kind, int $padBytes = 0): \Illuminate\Http\UploadedFile
    {
        $file = \Illuminate\Http\UploadedFile::fake()->image($kind === 'jpeg' ? 'proof.jpg' : 'proof.png', 8, 8);
        return $padBytes > 0 ? $file->size(intdiv($padBytes, 1024) + 1) : $file;
    }

    /** The EPCs of mock mats n, n, n. */
    protected function epcs(int ...$mats): array
    {
        return array_map(fn (int $n) => $this->epc($n), $mats);
    }
```

- [ ] **Step 2: Write the failing test**

Create `site/tests/Feature/TaskCompleteTest.php`:

```php
<?php
/** Closing a stop: scan, photo and signature; hard refusals; the note for a mismatch; the next swap task; the proof files. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

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
        return row('SELECT * FROM tasks WHERE id = ?', [$id]);
    }

    private function storedProofs(): array
    {
        return Storage::disk('local')->files('proofs');
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
        Storage::disk('local')->assertExists('proofs/' . $row['photo_path']);
        Storage::disk('local')->assertExists('proofs/' . $row['signature_path']);

        $next = $this->taskRow($out['next_task_id']);
        $this->assertSame(['swap', 'open', 1, 1, $task, days_after(today_nl(), 14), null],
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
        $text = UploadedFile::fake()->createWithContent('proof.jpg', 'this is not an image');

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
        $ini = parse_ini_file(public_path('.user.ini'));
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
        $this->assertSame(1, (int) DB::scalar("SELECT COUNT(*) FROM tasks WHERE type = 'swap'"));
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
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM item_events'));
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
        $collection = $this->ok($this->api('POST', '/tasks', ['contract_id' => 1, 'building_id' => 1, 'due_date' => today_nl()]))['task']['id'];
        $this->ok($this->api('PATCH', "/tasks/$collection", ['assigned_to' => $this->users['driver']]));

        $this->loginAs('driver');
        $this->assertError(422, 'no_drops', $this->complete($collection,
            $this->stop(['drops' => [['zone_id' => 1, 'epcs' => $this->epcs(4)]], 'pickups' => $this->epcs(1, 2, 3)])));

        $out = $this->ok($this->complete($collection, $this->stop(['pickups' => $this->epcs(1, 2, 3)])));
        $this->assertNull($out['next_task_id']);
        $this->assertSame('collected', $this->item(2)['status']);
        $this->assertSame(0, (int) DB::scalar("SELECT COUNT(*) FROM tasks WHERE status = 'open'"));
    }

    public function test_a_delivery_for_an_ended_contract_plans_no_swap(): void
    {
        $task = $this->deliveryReady();
        $this->loginAs('office');
        $this->ok($this->api('POST', '/contracts/1/end'));

        $this->loginAs('driver');
        $out = $this->ok($this->complete($task, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));
        $this->assertNull($out['next_task_id']);
        $this->assertSame(0, (int) DB::scalar("SELECT COUNT(*) FROM tasks WHERE type = 'swap'"));
    }

    public function test_proof_files_are_served_to_the_office_only(): void
    {
        $task = $this->deliveryReady();
        $this->ok($this->complete($task, $this->stop(['drops' => $this->fullDrop(1, 2, 3)])));
        $this->assertError(403, 'forbidden', $this->api('GET', "/tasks/$task/photo"));   // not even the driver who took it

        $this->loginAs('office');
        [$status, $photo] = $this->api('GET', "/tasks/$task/photo");
        $this->assertSame(200, $status);
        $this->assertSame('image/jpeg', $photo->headers->get('Content-Type'));
        $this->assertSame('image/png', $this->api('GET', "/tasks/$task/signature")[1]->headers->get('Content-Type'));

        $open = DB::scalar("SELECT id FROM tasks WHERE status = 'open'");   // the planned swap has no proof yet
        $this->assertError(404, 'no_proof', $this->api('GET', "/tasks/$open/photo"));
        $this->assertError(404, 'not_found', $this->api('GET', '/tasks/999/photo'));
    }
}
```

- [ ] **Step 3: Run the test to see it fail**

Run: `(cd site && php artisan test tests/Feature/TaskCompleteTest.php)`
Expected: FAIL, every `complete` call gets `404 not_found`.

- [ ] **Step 4: Write the implementation**

Add these lines to the imports at the top of `site/app/Http/Controllers/TaskController.php`, so the list reads:

```php
use App\Support\Scan;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

Add these members at the end of the class in `site/app/Http/Controllers/TaskController.php`:

```php
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
                foreach ($drops as $zoneId => $epcs) {
                    foreach ($epcs as $epc) {
                        $move($items[$epc], 'at_customer', null, $zoneId, 'deliver');
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
                DB::update("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP(), photo_path = ?, signature_path = ?,
                        signed_name = ?, issue_note = ? WHERE id = ? AND status = 'open'",
                    ["$stem.jpg", "$stem.png", $signedName, $note, $id]);

                // Today is D. The next swap is due on D + n. The unique key on previous_task_id allows only one.
                $nextId = null;
                if ($task['type'] !== 'collection' && $task['contract_status'] === 'active') {
                    DB::insert("INSERT INTO tasks (type, contract_id, building_id, previous_task_id, due_date) VALUES ('swap', ?, ?, ?, ?)",
                        [$task['contract_id'], $task['building_id'], $id, days_after(today_nl(), $task['swap_interval_days'])]);
                    $nextId = (int) DB::getPdo()->lastInsertId();
                }
                return ['task' => self::view($id), 'next_task_id' => $nextId];
            });
        } catch (\Throwable $e) {
            // Nothing was saved in the database, so nothing may stay on disk.
            Storage::disk('local')->delete($saved);
            throw $e;
        }
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
```

In `site/routes/api.php` add these lines at the end of the `auth:sanctum` group (directly above its closing `});`):

```php

    Route::post('/tasks/{id}/complete', [C\TaskController::class, 'complete'])->middleware('role:driver');
    Route::get('/tasks/{id}/photo', [C\TaskController::class, 'photo'])->middleware('role:office');
    Route::get('/tasks/{id}/signature', [C\TaskController::class, 'signature'])->middleware('role:office');
```

- [ ] **Step 5: Run the test to see it pass**

Run: `(cd site && php artisan test tests/Feature/TaskCompleteTest.php)`
Expected: `Tests:    13 passed`.

- [ ] **Step 6: Run all tests and commit**

Run: `(cd site && php artisan test)`
Expected: `Tests:    113 passed`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.10-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.10"/' site/composer.json
git add site README.md
git commit -m "Add closing a stop with proof and the next swap task at 0.4.10."
```

---

### Task 12: Testing gate: the whole loop

This task is the blocking **testing gate**. It adds no production code. A failing test here is a finding: fix it in the file that owns the rule, never by weakening the test.

**Files:**
- Test: `site/tests/Feature/LifecycleTest.php`

**Interfaces:**
- Consumes: every route built in Tasks 2 to 11; the test helpers `activateContract()`, `openHandover()`, `scanHandover()`, `proofFile()`, `epcs()`; the constants `Scan::STATUSES`, `Scan::HELD`, `HandoverController::TYPES`.
- Produces: nothing new.

- [ ] **Step 1: Write the test**

Create `site/tests/Feature/LifecycleTest.php`:

```php
<?php
/** The testing gate: one mat round the whole loop through the API, and the lifecycle rules as a table. */
declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HandoverController;
use App\Support\Scan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\ApiTestCase;

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
        $this->assertSame(days_after(today_nl(), 14), DB::scalar('SELECT due_date FROM tasks WHERE id = ?', [$swap]));
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
        $this->assertSame(['ready', 1, today_nl(), null],
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
            foreach (Scan::STATUSES as $status) {
                if ($status === HandoverController::TYPES[$type]['from']) {
                    continue;
                }
                $this->setItems([1], $status,
                    in_array($status, Scan::HELD, true) ? $this->users['driver'] : null,
                    $status === 'at_customer' ? 1 : null);
                $out = $this->scanHandover($giver, $handover, 1);
                $this->assertSame('rejected', $out['results'][0]['result'], "$type must not take a mat that is $status");
                $this->assertSame($status, $this->item(1)['status']);
            }
        }
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM handover_items'));
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM item_events'));
    }

    public function test_the_database_itself_refuses_a_closed_stop_without_proof(): void
    {
        [$task] = $this->activateContract(1);
        $this->expectException(PDOException::class);
        DB::statement("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP() WHERE id = ?", [$task]);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `(cd site && php artisan test tests/Feature/LifecycleTest.php)`
Expected: `Tests:    3 passed`.

If a test fails, the failure names a broken rule. Find the owning file (`HandoverController` for a handover rule, `TaskController` for a stop, `ItemController` for the quality check), fix it there with its own failing test first, and run this file again. Do not change an assertion to match the code. If the server answered `500`, the reason is in `site/storage/logs/laravel.log`.

- [ ] **Step 3: Run the full suite twice**

Run: `(cd site && php artisan test && php artisan test)`
Expected: `Tests:    116 passed` both times. Two runs in a row prove that no test depends on what an earlier run left in the database or in the storage folder.

- [ ] **Step 4: Check every PHP file of this plan for syntax**

Run:

```bash
(cd site && for f in $(git ls-files 'app/*.php' 'routes/*.php' 'database/*.php' 'tests/*.php' 'config/laundry.php' 'bootstrap/app.php'); do php -l "$f" | grep -v "No syntax errors"; done; echo "lint done")
```

Expected: only `lint done`.

- [ ] **Step 5: Commit**

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.11-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.11"/' site/composer.json
git add site README.md
git commit -m "Add whole-loop lifecycle tests as the testing gate at 0.4.11."
```

---

### Task 13: Security gate

This task is the blocking **security gate**. It reviews the whole branch against the Security section of ARCHITECTURE.md. It builds no feature. A finding is fixed in the file that owns it, with a test.

**Files:**
- Test: `site/tests/Feature/SecurityTest.php`
- Modify: only files in which a finding is fixed

**Interfaces:**
- Consumes: Laravel's router (`Route::getRoutes()`, read as data), every route, `activateContract()`.
- Produces: nothing new.

- [ ] **Step 1: Write the test**

Create `site/tests/Feature/SecurityTest.php`:

```php
<?php
/** The security gate: every route needs a login and the right role, and hostile input stays data. */
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\ApiTestCase;

final class SecurityTest extends ApiTestCase
{
    /**
     * Every API route as "METHOD /path" => the roles that may call it, or null when it needs no login.
     * Read from the router itself, so a new route is covered the day it is added.
     */
    private function apiRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $roles = null;
            if (in_array('auth:sanctum', $middleware, true)) {
                $roles = User::ROLES;
                foreach ($middleware as $name) {
                    if (str_starts_with($name, 'role:')) {
                        $roles = explode(',', substr($name, 5));
                    }
                }
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes["$method " . substr($route->uri(), strlen('api/v1'))] = $roles;
            }
        }
        return $routes;
    }

    public function test_every_route_needs_a_login_and_the_right_role(): void
    {
        $guarded = array_filter($this->apiRoutes(), fn (?array $roles) => $roles !== null);
        $this->assertCount(46, $guarded);

        foreach ($guarded as $name => $roles) {
            [$method, $path] = explode(' ', preg_replace('/\{\w+\}/', '1', $name));
            $this->logout();
            [$status, $body] = $this->api($method, $path);
            $this->assertSame([401, 'not_logged_in'], [$status, $body['error']['code'] ?? null], "$name without a login");
            foreach (array_diff(User::ROLES, $roles) as $role) {
                $this->loginAs($role);
                [$status, $body] = $this->api($method, $path);
                $this->assertSame([403, 'forbidden'], [$status, $body['error']['code'] ?? null], "$name as $role");
            }
        }
    }

    public function test_only_login_is_open_without_a_session(): void
    {
        $open = array_keys(array_filter($this->apiRoutes(), fn (?array $roles) => $roles === null));
        $this->assertSame(['POST /auth/login'], $open);
    }

    public function test_every_answer_carries_the_security_headers(): void
    {
        $headers = fn () => $this->json('GET', '/api/v1/auth/me')->headers;   // a 401: errors carry the headers too
        $this->assertSame('nosniff', $headers()->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $headers()->get('Cache-Control'));
        $this->assertNull($headers()->get('Strict-Transport-Security'));

        $this->app['env'] = 'production';   // what APP_ENV=production in .env does
        $this->assertSame('max-age=31536000', $headers()->get('Strict-Transport-Security'));
    }

    public function test_sql_in_a_login_or_a_search_stays_text(): void
    {
        $this->assertError(401, 'bad_login',
            $this->api('POST', '/auth/login', ['username' => "' OR '1'='1", 'password' => "' OR '1'='1"]));
        $this->loginAs('office');
        $this->assertSame(0, $this->ok($this->api('GET', '/items?q=' . urlencode("' OR 1=1 -- ")))['total']);
        $this->assertSame(40, (int) DB::scalar('SELECT COUNT(*) FROM items'));
    }

    public function test_a_tampered_proof_path_cannot_leave_the_proofs_folder(): void
    {
        [$task] = $this->activateContract(1);
        DB::update("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP(), photo_path = '../../../.env', signature_path = 'x.png' WHERE id = ?", [$task]);
        $this->loginAs('office');
        $this->assertError(404, 'no_proof', $this->api('GET', "/tasks/$task/photo"));
    }

    public function test_a_password_is_stored_only_as_a_hash(): void
    {
        $this->loginAs('office');
        $out = $this->ok($this->api('POST', '/users',
            ['name' => 'Dana', 'username' => 'dana', 'password' => 'long-password-1', 'role' => 'driver']));
        $this->assertStringNotContainsString('long-password-1', json_encode($out));
        $hash = DB::scalar("SELECT password_hash FROM users WHERE username = 'dana'");
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertTrue(Hash::check('long-password-1', $hash));
    }
}
```

- [ ] **Step 2: Run the test**

Run: `(cd site && php artisan test tests/Feature/SecurityTest.php)`
Expected: `Tests:    6 passed`. The first test walks all 46 routes behind the login. If its count differs, a route was added or left out in an earlier task: compare `(cd site && php artisan route:list --path=api/v1)` with the route lists in Tasks 2 to 11 before going on.

A failing case is a security finding. Fix it in `site/routes/api.php` or in the controller that owns the route.

- [ ] **Step 3: Check that no request value reaches SQL text**

Run:

```bash
grep -nE '\$[a-zA-Z_]+' site/app/helpers.php site/app/Support/*.php site/app/Models/*.php site/app/Http/Controllers/*.php \
  | grep -E 'rows\(|row\(|DB::|SELECT|INSERT|UPDATE|DELETE|WHERE|ORDER BY' | grep -vE '^\S+:\s*(//|\*)'
```

Read every line it prints. A PHP variable in the list of values **after** the SQL text is right: that is a bound `?`. A PHP variable **inside** the SQL text is allowed only in this list, where it holds a constant from the code or text built from constants:

| File | Variable in SQL text | Why it is safe |
|---|---|---|
| `helpers.php` | `$sql`, `$statement` | `rows()` and `row()` pass on the SQL their caller wrote; `run_sql_file()` only ever gets the two files in `db/` |
| `RefdataController.php` | `$table`, `$parent` | Names from the `RESOURCES` constant; the values go through the query builder |
| `ItemController.php` | `$sqlWhere` | Fixed text with `?` marks |
| `HandoverController.php` | `$where`, `$wash` | Fixed text with `?` marks |
| `Scan.php` | `$sql` | `ITEM_SELECT` plus `?` marks |
| `TaskController.php`, `ItemController.php`, `HandoverController.php`, `Scan.php` | `marks(...)`, `implode(' AND ', $where)`, `self::SELECT`, `self::OFFICE_SELECT`, `self::ISSUE` | `?` marks and fixed text |

Anything else that puts a variable into SQL text is a finding: replace it with a `?` parameter.

- [ ] **Step 4: Check secrets, settings and file placement**

Run:

```bash
git ls-files | grep -E '(^|/)\.env(\.testing)?$|^site/storage/app/private/proofs/|^site/vendor/|composer\.phar' ; echo "tracked-files check done"
git grep -nE '\benv\(' -- site/app site/routes site/database site/tests site/bootstrap ; echo "env check done"
git grep -nE 'DB_PASSWORD=.' -- site | grep -v 'DB_PASSWORD=""' ; echo "password check done"
ls -A site/public
```

Expected: the first three commands print only their `done` line (the empty `DB_PASSWORD=""` in `site/.env.example` is filtered out). `ls -A site/public` shows exactly `.htaccess`, `.user.ini`, `favicon.ico`, `index.php` and `robots.txt`. No business code, no `storage`, no `.env` inside the web root.

A hit of the second command is a setting read outside `site/config`: move it into a config file and read it with `config(...)`, or `php artisan config:cache` will break it on the server.

- [ ] **Step 5: Check the cookie and the web root over HTTP**

Start the server in the background (`run_in_background: true`). The two extra settings are what the server's `.env` has; given this way they win over `site/.env.testing`:

```bash
(cd site && APP_ENV=testing SESSION_SECURE_COOKIE=true APP_DEBUG=false php -S 127.0.0.1:8000 -t public)
```

Run:

```bash
(cd site && php artisan tinker --env=testing --execute="App\Models\User::register(['name' => 'Sec Office', 'username' => 'sec.office', 'password' => 'sec-password-12', 'role' => 'office']);")
curl -s -i -H 'Origin: http://127.0.0.1:8000' -H 'Sec-Fetch-Site: same-origin' -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"username":"sec.office","password":"sec-password-12"}' http://127.0.0.1:8000/api/v1/auth/login \
  | grep -iE '^(HTTP|set-cookie|x-content-type-options|cache-control)'
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/.env
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/composer.json
```

Expected: `HTTP/1.1 200 OK`, `X-Content-Type-Options: nosniff`, a `Cache-Control` with `no-store`, two `Set-Cookie` lines that both contain `secure` and `samesite=lax` (the session cookie also `httponly`; the `XSRF-TOKEN` cookie is meant to be read by the page and is not), and then two times `404`: files outside `site/public` cannot be fetched.

Stop the background server.

- [ ] **Step 6: Walk the Security section of ARCHITECTURE.md**

For each line, confirm the place in the code and the test. Write the table, with any finding, into the task report.

| Security rule in ARCHITECTURE.md | Where it is enforced | Test |
|---|---|---|
| HTTPS only | `site/public/.htaccess` redirect; `Strict-Transport-Security` in `ApiHeaders` when `APP_ENV=production`; `SESSION_SECURE_COOKIE=true` | `SecurityTest::test_every_answer_carries_the_security_headers`, Step 5 |
| Passwords stored as a hash | `User::register()`, `UserController::update()`, both through `Hash::make()` | `SecurityTest::test_a_password_is_stored_only_as_a_hash` |
| Login limited per username and address | `AuthController::login()` with `RateLimiter` | `AuthTest::test_five_failed_tries_block_the_sixth_even_with_the_right_password` |
| Cookie `HttpOnly`, `Secure`, `SameSite=Lax` | Laravel's session (`site/config/session.php`), `SESSION_SECURE_COOKIE` in `.env` | Step 5 |
| Forgery check on every changing request | Laravel's own check, put on the API by `statefulApi()` in `site/bootstrap/app.php` | Task 4 Step 5, answer 3. By hand: Laravel switches this check off inside its own test runner |
| Session id renewed at login | `AuthController::login()` calls `session()->regenerate()` | Read the code |
| Switched-off user is out at his next call | `Role` middleware | `AuthTest::test_a_user_switched_off_while_logged_in_is_logged_out_at_his_next_call` |
| Role checked on the server for every call | `auth:sanctum` and `role` in `site/routes/api.php` | `SecurityTest::test_every_route_needs_a_login_and_the_right_role` |
| Ownership checked on the server | `TaskController::complete()`, `HandoverController::scan()`, `show()`, `close()`, `ItemController::index()` | `TaskCompleteTest::test_only_the_assigned_driver_closes_a_stop_and_only_once`, `HandoverScanTest::test_only_the_two_people_of_a_handover_may_scan_into_it`, `ItemsTest::test_each_role_sees_only_its_own_mats` |
| All SQL uses prepared statements | `rows()`, `row()` and `DB::` calls with `?` marks | Step 3, `SecurityTest::test_sql_in_a_login_or_a_search_stays_text` |
| Uploads checked by content, size limit, server-made name, outside the web root, served only to the office | `TaskController::proof()`, `complete()`, `proofFile()` | `TaskCompleteTest` (three proof tests), `SecurityTest::test_a_tampered_proof_path_cannot_leave_the_proofs_folder` |
| Secrets in `.env` and `.env.testing`, never in git | `site/.gitignore`; settings only through `site/config` | Step 4 |
| Error details stay on the server | `api_error()` with `APP_DEBUG=false` | `AuthTest::test_a_server_error_hides_its_details_unless_debug_is_on` |
| Simulator off in production use | `Scan::source()`, `ScanController::simItems()`, `site/config/laundry.php` | `ScanTest::test_switching_the_simulator_off_closes_its_list_and_refuses_its_scans` |
| Corrections leave a trace | `ItemController::correct()` | `ItemsTest::test_a_correction_moves_the_mat_and_leaves_a_trace` |
| A lookup does not show the customer | `Scan::view()` | `ScanTest::test_lookup_tells_what_each_mat_is_and_changes_nothing` |
| Personal data: how long proofs are kept | Not built: a question for Proti | Report as open |

The gate passes when there is no unresolved finding of critical or high severity. List lower findings in the report.

- [ ] **Step 7: Run all tests and commit**

Run: `(cd site && php artisan test)`
Expected: `Tests:    122 passed`.

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.4.12-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.4.12"/' site/composer.json
git add site README.md
git commit -m "Add role matrix and security tests as the security gate at 0.4.12."
```

---

### Task 14: Publish the API contract

The docs pass runs after both gates, so the documents describe what was built. The updated endpoint table in ARCHITECTURE.md is the **API contract** that plans 2 and 3 (the React app) build against.

**Files:**
- Modify: `ARCHITECTURE.md` (version line, built-with table, hosting section, settings table, endpoint section, first login, security, wash count, time zone)
- Modify: `POC.md`, `FEATURES.md`, `FLOW.md` (wash count at Handover C)
- Modify: `db/schema.sql` (two comment lines only, no table or column)
- Modify: `README.md` (status note, diagrams, two new sections, version badge)
- Modify: `site/composer.json` (version)

**Interfaces:**
- Consumes: the routes in `site/routes/api.php` as built.
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

Laravel checks every changing request (everything except `GET`) for forgery. A request passes when the browser marks it as same-origin, or when it carries the header `X-CSRF-Token` with the `csrf_token` from the login or from `GET /auth/me`. The web app sends that header on every changing request. The session cookie only works for requests from the site's own address.
```

Below the error example in the same section, replace the line that starts with `` `401` not logged in `` with:

```markdown
`401` not logged in · `403` wrong role, not yours, or failed forgery check · `404` not found · `405` wrong method · `409` already done, closed, duplicate or wrong status · `422` input not valid · `429` too many login tries.
```

- [ ] **Step 2: Describe the Laravel build in ARCHITECTURE.md**

In each replacement, "Replace" is the exact text now in the file.

In the table under `## Overview`. Replace:

```markdown
| Backend | PHP 8.3+ JSON API | Login, role checks, every business rule, every status change. The frontend never decides whether a mat may move. |
```

With:

```markdown
| Backend | Laravel 13 on PHP 8.3+, JSON API | Login, role checks, every business rule, every status change. The frontend never decides whether a mat may move. Laravel does the login session, the forgery check, input validation and file storage; the rules are this project's own code. |
```

In the section `## Hosting at TransIP`. Replace:

````markdown
```
site/
  app/               PHP backend code            (not reachable from the web)
  storage/proofs/    photos and signatures       (not reachable from the web)
  .env               database password, settings (not reachable from the web, never in git)
  www/               web root
    index.php        entry point for /api/v1/*
    index.html       the React app
    assets/          the React build
    .htaccess        /api/* -> index.php, everything else -> index.html
```
````

With:

````markdown
```
db/                  schema.sql and seed.sql      (not reachable from the web)
site/                the Laravel application
  app/               backend code                 (not reachable from the web)
  routes/api.php     every API route and who may call it
  storage/           proofs, sessions, logs       (not reachable from the web)
  .env               database password, settings  (not reachable from the web, never in git)
  public/            web root: the site's DocumentRoot points here
    index.php        Laravel's entry point, serves /api/v1/*
    .htaccess        HTTPS only; everything that is not a file goes to index.php
```

The web root must point at `site/public`. It needs SSH on the hosting, for `composer` and `php artisan`. Where the React app's files go is decided in the frontend plan.
````

Replace the settings table:

```markdown
| Name | Meaning |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Database connection, from the TransIP control panel |
| `APP_ENV` | `local` or `production` |
| `SCANNER_SIMULATOR` | `true` while the handheld is simulated. `false` switches the simulator and its helper endpoint off. |
```

With:

```markdown
| Name | Meaning |
|---|---|
| `APP_KEY` | Secret key for cookies and sessions. Made once with `php artisan key:generate`. |
| `APP_ENV` | `production` on the server |
| `APP_DEBUG` | `false` on the server: error details stay in the log |
| `APP_URL` | The address of the site, with `https://`. The login cookie only works for the web app at this address. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database connection, from the TransIP control panel |
| `SCANNER_SIMULATOR` | `true` while the handheld is simulated. `false` switches the simulator and its helper endpoint off. |
| `SESSION_SECURE_COOKIE` | `true` on the server: the login cookie travels over HTTPS only |

Nothing that differs per machine is written in the code. `site/.env.example` lists every setting with a comment. After a change to `.env` on the server, run `php artisan config:cache`.

There is no database on the development machine. The tests use a second database at TransIP, named in `site/.env.testing`, and rebuild it on every run. The test database is reachable from outside the hosting; the real one is not.
```

Directly after the paragraph that starts with `On every database connection the backend runs`, add:

```markdown
Times are stored and sent in UTC, not in Dutch time. Dutch time repeats one hour every autumn and skips one every spring, so two scans in that night could not be put in order. A named time zone also needs time zone tables that a shared database often does not have. The screens show every time in Dutch time. Calendar days (due dates, wash dates) are Dutch calendar days.

Laravel adds two tables of its own next to the 13 of `db/schema.sql`: `migrations` (which set-up steps ran) and `personal_access_tokens` (empty until the handheld logs in with a token).
```

In the section `### First login`, replace the paragraph with:

```markdown
No user is seeded, because a password must not sit in a file in git. After the tables are built, the first office user is created once on the server:

    php artisan laundry:office-user

The command asks for the name, the username and the password (10 to 72 characters, hidden while typing). That user then adds everyone else in the office console.
```

In the Security section. Replace:

```markdown
- **Passwords** are stored only as a hash (PHP `password_hash`). Login is limited to a few tries per minute per username and address.
```

With:

```markdown
- **Passwords** are stored only as a hash (bcrypt, through Laravel's `Hash`). Login is limited to 5 failed tries per minute per username and address.
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
> **Backend built, screens not yet.** The Laravel API under `/api/v1` is built and tested. The web app (office console and scanner simulator) is the next step. The Chainway C72 handheld is simulated, and all mats are mock mats.
```

Replace:

```markdown
Both files run unchanged on MySQL 8.0.16+ and MariaDB 10.4+. At TransIP the same two files can be imported through phpMyAdmin.
```

With:

```markdown
Both files run unchanged on MySQL 8.0.16+ and MariaDB 10.4+. Laravel loads them: `php artisan migrate` runs `db/schema.sql`, `php artisan db:seed` runs `db/seed.sql`.
```

Add these two sections directly before `## Versions`:

````markdown
## Run the API locally

Needs PHP 8.3+, Composer, and a test database at TransIP that can be reached from outside the hosting. No database is installed locally. Copy `site/.env.example` to `site/.env.testing`, set `APP_ENV=testing`, `APP_DEBUG=true`, `APP_URL=http://127.0.0.1:8000` and `SESSION_SECURE_COOKIE=false`, and fill in the details of the **test** database. The tests rebuild that database on every run, so it must never be the real one.

```bash
cd site
composer install
php artisan key:generate --env=testing     # once
php artisan test                           # rebuilds the test database, then runs
php artisan laundry:office-user --env=testing             # a user to log in with
APP_ENV=testing php -S 127.0.0.1:8000 -t public
```

The next test run rebuilds the test database, and that user is gone with it.

The API then answers at `http://127.0.0.1:8000/api/v1`. The endpoints are listed in [ARCHITECTURE.md](ARCHITECTURE.md#endpoints).

## Put it on TransIP

Needs a web hosting package with SSH, PHP 8.3+ and a web root that can be pointed at a subfolder. Every setting lives in `site/.env` on the server; nothing is hardcoded.

```bash
# over SSH, in a folder that is not reachable from the web
git clone <this repository> laundry
cd laundry/site
composer install --no-dev --optimize-autoloader
cp .env.example .env                 # then fill in APP_URL and the real database
php artisan key:generate
php artisan migrate --force          # builds the tables from db/schema.sql
php artisan db:seed --force          # the mock data of db/seed.sql, for the demo only
php artisan laundry:office-user      # the first office user
php artisan config:cache             # again after every change to .env
```

Then, in the TransIP control panel, point the site's web root (DocumentRoot) at `laundry/site/public` and switch HTTPS on. The folders `site/storage` and `site/bootstrap/cache` must be writable by PHP.

Never run `php artisan migrate:fresh` or `php artisan test` on the server: both empty the database they are pointed at.
````

- [ ] **Step 5: Check the documents against the code**

Run:

```bash
(cd site && php artisan route:list --path=api/v1 --json) | php -r '
$doc = file_get_contents("ARCHITECTURE.md");
foreach (json_decode(stream_get_contents(STDIN), true) as $route) {
    $path = substr($route["uri"], strlen("api/v1"));
    $last = substr($path, strrpos($path, "/"));
    if (!str_contains($doc, $path) && !str_contains($doc, $last)) echo "NOT IN ARCHITECTURE.md: {$route["method"]} $path\n";
}
echo "contract check done\n";'
```

Expected: only `contract check done`. A line `NOT IN ARCHITECTURE.md` names a route the endpoint table misses: add it to the table.

Run: `(cd site && php artisan test)`
Expected: `Tests:    122 passed`.

- [ ] **Step 6: Commit**

```bash
sed -i 's/version-[0-9.]*-2563eb/version-0.5.0-2563eb/' README.md
sed -i 's/"version": "[0-9.]*"/"version": "0.5.0"/' site/composer.json
git add ARCHITECTURE.md POC.md FEATURES.md FLOW.md README.md db/schema.sql site/composer.json
git commit -m "Publish the API contract and move the wash count to Handover C in the documents at 0.5.0."
```

Do not tag and do not push. Tagging `v0.5.0` and merging are the user's decision when the branch is finished.

- [ ] **Step 7: Emit the handoff**

Put these blocks in the final report, with the two "Open risks" lines completed from the gate reports of Tasks 12 and 13.

```
### HANDOFF — backend
- Produced:      site/ (a Laravel 13 application): routes/api.php, app/Http/Controllers/*, app/Http/Middleware/*, app/Support/Scan.php, app/Models/User.php, app/helpers.php, config/laundry.php, bootstrap/app.php, the migration that loads db/schema.sql, public/{.htaccess,.user.ini}, .env.example
- Contract:      the endpoint table and the "How data flows" section of ARCHITECTURE.md 0.4.0. All paths under /api/v1, JSON in and out (closing a stop is a form with the JSON in "payload"), a session cookie set by Laravel, header X-CSRF-Token on every changing request, errors as {"error": {"code", "message"}}.
- Assumptions:   the web app is served from the address in APP_URL, so its requests carry the session cookie. The frontend never decides whether a mat may move; it shows what the API answers. Lists are small enough to load whole, except GET /items, which pages by 50. Every time in an answer is UTC ("2026-10-12 21:03:11"); the screens show it in Dutch time. Dates (due_date, wash dates) are Dutch calendar days and are shown as they are.
- Do NOT change: paths, field names, the result words found / not_on_list / unknown / rejected, the error codes, db/schema.sql. Status changes stay one conditional UPDATE; never load, change and save.
- Open risks:    <from the Task 12 and Task 13 reports>. The picture flow-0.1.6.png still shows the wash count at Handover B and must be rendered again.

### HANDOFF — testing
- Produced:      site/tests/*, site/phpunit.xml. 122 tests, all against the TransIP test database.
- Contract:      (cd site && php artisan test) is green. It reads only site/.env.testing, rebuilds that database, and refuses to run when it is the database of site/.env.
- Assumptions:   the TransIP test database exists and is reachable from the development machine.
- Do NOT change: an assertion to make failing code pass.
- Open risks:    no test runs two requests at the same moment; row locks and conditional updates are reasoned, not load-tested. The forgery check and the real cookie are checked by hand with curl, not by the test suite.

### HANDOFF — security
- Produced:      site/tests/Feature/SecurityTest.php and the review table of Task 13.
- Contract:      no unresolved critical or high finding.
- Assumptions:   HTTPS is switched on at TransIP, and site/public is the web root.
- Do NOT change: SCANNER_SIMULATOR must be false before real mats are registered. APP_DEBUG must be false on the server.
- Open risks:    <from the Task 13 report>. Still to check at TransIP: database engine and version, PHP version, that .user.ini is honoured, that the HTTPS redirect in .htaccess works behind TransIP's proxy, and whether the address Laravel sees is the visitor's own (the login throttle counts per username and address).
```

---

## Not in this plan

- The React office console and scanner simulator (plans 2 and 3), and where their built files go inside `site`.
- Token login for the Chainway C72. Sanctum and its table are in place; issuing tokens is a small task of its own when the handheld comes.
- Doing the deployment. The steps are written down in Task 14 ("Put it on TransIP"); running them needs the hosting details.
- Cleaning up old session files, log files and proof files. How long proofs are kept is a question for Proti.
- Paging for handovers and tasks. Both lists return the newest rows only (100 and 200).
- Eloquent models for mats, handovers, contracts and tasks. The rules are conditional SQL updates on purpose; models can come later for read-only screens if they help.
