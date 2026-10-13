# Handoff: the documents of Task 14 and the reading part of Task 13

For Harshit. Three tasks of the backend plan are left: 12 (whole-loop tests), 13 (security gate) and 14 (publish the API contract). Every part that needs a test run stays with Naveen, because the test database takes one run at a time. The two parts below need no database, so they can be done at the same moment.

## Who does what

| Part | Who | From the plan |
|---|---|---|
| Whole-loop tests, both full runs, the commit | Naveen | Task 12 |
| Security test file, cookie check over HTTP, fixing findings, full run, the commit | Naveen | Task 13 steps 1, 2, 5, 7 |
| **Read-only security review** | **Harshit** | **Task 13 steps 3, 4, 6** |
| **The documents** | **Harshit** | **Task 14 steps 1, 2, 3, 4** |
| Check of the documents against the real routes, full run, the 0.5.0 commit, the final handoff blocks | Naveen | Task 14 steps 5, 6, 7 |

## Read first

- The plan: [docs/superpowers/plans/2026-10-12-backend-api.md](../superpowers/plans/2026-10-12-backend-api.md). Task 14 starts at line 5354, Task 13 at line 5123.
- "Where the plan is out of date" below. Read it before you type a replacement.

## When you can start

Now. Nothing of this waits for Naveen.

## Set up

Git and an editor are enough. No Composer, no `site/.env.testing`, no SSH tunnel.

```bash
git fetch
git switch -c docs/api-contract origin/feat/backend-api
```

**Do not run `php artisan test`.** The test database is in use for the runs of Tasks 12 and 13, and a second run breaks the first.

## Part 1: the documents (Task 14, steps 1 to 4)

Do the steps in the order of the plan. Each step gives the text to find and the text to put in its place.

| Step | Lines in the plan | Files | What changes |
|---|---|---|---|
| 1 | 5371 to 5421 | `ARCHITECTURE.md` | Version line to 0.4.0, the endpoint table, the line with the status codes |
| 2 | 5423 to 5529 | `ARCHITECTURE.md` | Built-with table, hosting section, settings table, time zone, first login, passwords |
| 3 | 5531 to 5728 | `ARCHITECTURE.md`, `POC.md`, `FEATURES.md`, `FLOW.md`, `README.md`, `db/schema.sql` | The wash is counted at Handover C, not at Handover B |
| 4 | 5730 to 5791 | `README.md` | Status note, how the database files are loaded, the sections "Run the API locally" and "Put it on TransIP" |

After step 3, check the one file you may normally not touch:

```bash
git diff --stat db/schema.sql
```

Expected: `1 file changed, 2 insertions(+), 2 deletions(-)`. Two comment lines above `CREATE TABLE handovers`, nothing else. Any other change to that file is a mistake: undo it.

"Replace" in the plan means the exact text now in the file. **If you cannot find that exact text, do not guess a place for it.** Leave it, write down which one, and go on with the next.

Commit once, when the four steps are done, and push your branch:

```bash
git add ARCHITECTURE.md POC.md FEATURES.md FLOW.md README.md db/schema.sql
git commit -m "Bring the documents in line with the built API."
git push -u origin docs/api-contract
```

This commit carries no version. Naveen merges it and commits the version 0.5.0 with Task 14.

## Part 2: the read-only security review (Task 13, steps 3, 4, 6)

You read and report. You change no file in this part: a finding is fixed by Naveen, with a test, in the file that owns it.

The commands are for Git Bash and start at the repository root.

| Step | Lines in the plan | What you do |
|---|---|---|
| 3 | 5254 to 5274 | Run the `grep`. Read every line it prints. A PHP variable **inside** SQL text is allowed only where the table in the plan lists it. Anything else is a finding |
| 4 | 5276 to 5289 | Run the four commands. The first three print only their `done` line. `ls -A site/public` shows exactly `.htaccess`, `.user.ini`, `favicon.ico`, `index.php`, `robots.txt` |
| 6 | 5314 to 5338 | For each of the 17 rows, open the place in the code the row names and confirm the rule is really there |

In step 6 some rows name a test in `SecurityTest`. That file does not exist yet (Naveen writes it), so for those rows confirm the code only.

Read these most carefully. Nobody but their writer has looked at them:

- `TaskController::complete()`, `proof()` and `proofFile()`: closing a stop and the proof files. Built today.
- `HandoverController`: who may scan, see, close and resolve.
- `ItemController::index()` and `show()`: which mats and which history each role gets.
- `Scan::source()` and `Scan::view()`: the simulator switch, and that a lookup does not show the customer.

One point is known already, so you need not report it: `POST /tasks/{id}/complete` puts no limit on the number of tags in one stop, where a scan allows 5000.

## Where the plan is out of date

The plan was written before anything was built. Follow this list where it differs.

| The plan says | Do this instead |
|---|---|
| Step 2, under the settings table: "The test database is reachable from outside the hosting; the real one is not." | Write: "No database at TransIP can be reached from outside the hosting. The development machine reaches the test database through an SSH tunnel." |
| Step 4, "Run the API locally": "a test database at TransIP that can be reached from outside the hosting" | Write: "an SSH client, and a test database at TransIP". Add that the tunnel must be open first (`ssh -N -L 3307:<database host name>:3306 <ssh user>@<ssh host>`) and that `site/.env.testing` then has `DB_HOST=127.0.0.1` and `DB_PORT=3307`. The set-up in [backend-office.md](backend-office.md) has the wording |
| Step 2, "First login": the password has "10 to 72 characters" | Write: "10 to 72 characters and at most 72 bytes". That is your own rule from 0.4.9 |
| Task 14 intro: `ARCHITECTURE.md` may have an uncommitted edit | It has none. Nothing to do |
| Step 6 of the plan: change the version badge and `site/composer.json` | Not yours. See the rules |

## Rules

1. Touch only: `ARCHITECTURE.md`, `POC.md`, `FEATURES.md`, `FLOW.md`, `README.md` and the two comment lines in `db/schema.sql`.
2. Never touch: anything under `site/`, the version badge in `README.md`, the version in `site/composer.json`, any table or column in `db/schema.sql`, `db/seed.sql`.
3. Write no path, field name, role or error code from memory. The endpoint table of the plan is the text; where you doubt it, `site/routes/api.php` is the truth. Report the difference, do not smooth it over.
4. Do not run the tests.
5. Commits carry no `Co-Authored-By` line, no "Generated with" line and no other mention of an AI tool.
6. Commit on `docs/api-contract` only. Push that branch. Do not merge it and do not push to `main` or `feat/backend-api`.

## When you are done

Push `docs/api-contract` and send Naveen:

1. Every "Replace" text you could not find exactly, with the file and what you found there.
2. Every place where you wrote something else than the plan says, with the reason.
3. The security findings, one line each: file and line, what is wrong, and how bad you think it is (critical, high, medium, low). "No findings in step 3" is an answer too: say it per step.
4. Anything that looked wrong but was outside these two parts. Do not fix it yourself; report it.

If a step seems to need a change under `site/`, a change to a table, or a test run: stop and ask. Do not work around it.
