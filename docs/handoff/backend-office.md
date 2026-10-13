# Handoff: backend office data (Tasks 3, 4, 9, 10)

For Harshit. The backend is built from one plan, split between Naveen and Harshit so the work finishes sooner and each half gets a second pair of eyes.

## Who does what

| Step | Who | Tasks of the plan |
|---|---|---|
| 1 | Naveen | 1 skeleton (done), 2 login, roles and the error format |
| 2, in parallel | Naveen | 5, 6 mats. 7, 8 handovers |
| 2, in parallel | **Harshit** | **3** areas, customers, buildings, zones, mat types. **4** users. **9** contracts. **10** driver tasks |
| 3 | Naveen | Merge your branch, then 11 closing a stop, 12 whole-loop tests, 13 security review, 14 API contract |

The two halves share no logic. Your tasks never call `Scan`, `ItemController`, `ScanController` or `HandoverController`, and Naveen's half never calls your controllers.

## Read first

- The plan: [docs/superpowers/plans/2026-10-12-backend-api.md](../superpowers/plans/2026-10-12-backend-api.md). Read the sections "Global Constraints" and "Decisions that fill gaps in the spec", then your four tasks. Each task has the complete code and the complete tests written out.
- The rules of the system: [ARCHITECTURE.md](../../ARCHITECTURE.md).
- The tables: [db/schema.sql](../../db/schema.sql). Fixed. Do not change a table or a column.

## When you can start

Only when the branch `feat/backend-api` on GitHub contains the commit

```
Add routing, the error shape and login at 0.4.1.
```

Your tasks need the routes, the `role` middleware and the login of that commit. Before it is there, nothing of yours can be tested.

Until then, do the work in [start-now.md](start-now.md): it waits for nobody.

## Set up, once

You need PHP 8.3 or newer with `pdo_mysql`, Composer, Git, and an SSH client. No database is installed on your machine: the tests use a database at TransIP.

1. Get the code and make your branch.

   ```bash
   git clone https://github.com/Naveenrawat-max/laundry-poc.git
   cd laundry-poc
   git switch feat/backend-api
   git switch -c feat/backend-office
   cd site
   composer install
   ```

2. Ask Naveen for the details of the test database (name, user, password, host name) and for SSH access to the hosting (user name, host and password). Naveen and you use the same database and the same user.

   One database is shared, so one rule: **never run the tests while Naveen runs them.** Every test run drops all tables and builds them again, and two runs at once break each other. Before a run, tell Naveen "testing"; after it, "done". A run that fails with a missing table, a lock timeout or a deadlock was hit by the other run: run it again before you look for a bug in your code.

3. A database at TransIP cannot be reached from outside the hosting, so open an SSH tunnel in a separate terminal window and leave it open while you work.

   ```bash
   ssh -N -L 3307:<database host name>:3306 <ssh user>@<ssh host>
   ```

   After the password the window shows nothing. That is correct: the tunnel is open.

4. Create `site/.env.testing`. It is ignored by git. Never commit it and never paste it anywhere.

   ```ini
   APP_NAME=Laundry
   APP_ENV=testing
   APP_KEY=
   APP_DEBUG=true
   APP_URL=http://127.0.0.1:8000
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3307
   DB_DATABASE=<the test database>
   DB_USERNAME=<its user>
   DB_PASSWORD="<its password>"
   SCANNER_SIMULATOR=true
   SESSION_DRIVER=file
   SESSION_LIFETIME=720
   SESSION_SECURE_COOKIE=false
   CACHE_STORE=file
   QUEUE_CONNECTION=sync
   FILESYSTEM_DISK=local
   ```

5. Fill in the key and check that everything works before you write anything.

   ```bash
   php artisan key:generate --env=testing
   php artisan test
   ```

   Expected: `Tests:    19 passed` (8 database tests and 11 login tests). If you see `Refusing to run`, `site/.env.testing` is missing or has no database name. Do not create a file `site/.env` on your machine.

## Your tasks

Do them in this order. One commit per task, with exactly the version and the message below.

| Task | Builds | Tests in | New tests | Total on your branch | Commit message |
|---|---|---|---|---|---|
| 3 | `RefdataController` | `RefdataTest.php` | 9 | 28 | `Add office management of customers, buildings, zones and mat types at 0.4.2.` |
| 4 | `UserController`, `User::register()`, the command `laundry:office-user` | `UsersTest.php` | 9 | 37 | `Add user management and the first-office-user command at 0.4.3.` |
| 9 | `ContractController` | `ContractsTest.php` | 9 | 46 | `Add contracts with activation and ending at 0.4.8.` |
| 10 | `TaskController` (lists, assignment, collection) | `TasksTest.php` | 8 | 54 | `Add task lists per role, assignment and collection tasks at 0.4.9.` |

Each task in the plan goes: write the test, see it fail, write the code, see it pass, commit. Keep that order.

Run one test file while you work, and the whole suite before each commit:

```bash
php artisan test tests/Feature/RefdataTest.php
php artisan test
```

## Where the plan is out of date

The plan was written before anything was built. Follow this list where it differs.

| The plan says | Do this instead |
|---|---|
| "Switch on external access" for the test database | Not possible at TransIP. Use the SSH tunnel from the set-up above |
| `php ../composer.phar ...` | `composer ...`. The file `composer.phar` is not in the repository |
| The total after each task (28, 37, 92, 100) | Your branch skips Tasks 5 to 8, so your totals are 28, 37, 46, 54 |
| "All work happens on `feat/backend-api`" | Your work happens on `feat/backend-office` |

The helper for today's date in the Netherlands is `today_nl()`. Laravel has its own `today()`, which returns an object and not a `Y-m-d` text. The plan already says `today_nl()` everywhere; never write `today()`.

The Laravel code in the plan was written from the documentation and has not been run. A step may fail because of a mistake in that code. Then fix the code. **Never change an assertion of a test to make it pass**: the assertions were checked against a working build, the Laravel code was not.

## Rules

1. Touch only the files your task lists under "Files". Those are, in total: `RefdataController.php`, `UserController.php`, `ContractController.php`, `TaskController.php`, `app/Models/User.php`, `routes/console.php`, `routes/api.php`, `tests/ApiTestCase.php` (Task 10 adds one helper), your four test files, and the version in `README.md` and `site/composer.json`.
2. Never touch: `db/`, `app/helpers.php`, `app/Support/Scan.php`, `ItemController.php`, `ScanController.php`, `HandoverController.php`, `bootstrap/app.php`, `config/`, and the middleware.
3. In `routes/api.php` only add your own routes, where the task says. Do not reorder or reformat the lines that are there.
4. Every query uses `?` placeholders. No value from a request is pasted into SQL text.
5. Never run the tests against a database that holds real data. Never run them while Naveen's run is going (see the set-up).
6. No package is added. Only what is installed already.
7. Commits carry no `Co-Authored-By` line, no "Generated with" line and no other mention of an AI tool.
8. Commit on `feat/backend-office` only. Push that branch. Do not merge it and do not push to `feat/backend-api`.

## Where the two halves will meet

Both halves add lines to the same two files. This is expected and is solved at the merge by Naveen, by keeping both sets of lines.

- `site/routes/api.php`: both add routes.
- `site/tests/ApiTestCase.php`: Task 7 adds two helpers, your Task 10 adds one.
- The version in `README.md` and `site/composer.json`: the higher number wins.

## When you are done

Push `feat/backend-office` and send Naveen:

1. The last lines of `php artisan test`, showing `Tests:    54 passed`.
2. A list of every place where you had to write code that differs from the plan, with the reason. The plan is corrected from that list.
3. Anything that looked wrong but was outside your tasks. Do not fix it yourself; report it.

If a task seems to need a change to a table, a change to a file you may not touch, or a route of the other half: stop and ask. Do not work around it.
