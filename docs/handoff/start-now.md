# Handoff: what Harshit can start today

For Harshit. Nothing in this file waits for Naveen. The backend tasks in [backend-office.md](backend-office.md) (Tasks 3, 4, 9, 10) can only start after Naveen has finished Task 2. This file is the work for the time until then, and for any later moment you are waiting on a merge or a review.

Honest note: no backend code is free of Task 2. Every endpoint sits on its login, its roles and its routes. So the backend part of this file is preparation, and the building part is the frontend with fake data.

Do the parts in this order.

| Part | What | Time | Why now |
|---|---|---|---|
| 1 | Get the SSH tunnel and the connection to the test database working | about 1 hour | Then you start the backend tasks the minute Task 2 is there |
| 2 | Read your four backend tasks | about 2 hours | You find the questions before you are in the middle of the code |
| 3 | Build the frontend screens with fake data | the rest | Needs no backend at all |

## Part 1: the tunnel and the test database

You need PHP 8.3 or newer with `pdo_mysql`, Composer, Git and an SSH client.

1. Ask Naveen for the details of the test database at TransIP (database name, user, password, host name) and for SSH access (user name, host and password). Naveen and you use the same database and the same user.

   One database is shared, so one rule: **never run the tests while Naveen runs them.** Every test run drops all tables and builds them again, and two runs at once break each other. The check in step 3 below only reads the version and is always safe.

2. Open the tunnel in a separate terminal window and leave it open.

   ```bash
   ssh -N -L 3307:<database host name>:3306 <ssh user>@<ssh host>
   ```

   After the login the window shows nothing. That is correct: the tunnel is open.

3. Check that your machine reaches the database through the tunnel. Put your own values in, run it, and do not save it in a file.

   ```bash
   php -r '$p = new PDO("mysql:host=127.0.0.1;port=3307;dbname=<the test database>", "<its user>", "<its password>"); echo "database ok: ", $p->query("SELECT VERSION()")->fetchColumn(), PHP_EOL;'
   ```

   Expected: `database ok:` and a MySQL 8 version number. Send that version to Naveen.

   `open failed: administratively prohibited` in the tunnel window means the hosting refuses the tunnel. `Access denied` means the user name or the password is wrong. Report either to Naveen and go on with Part 2.

Done when: `database ok` is printed.

## Part 2: read your four tasks

In [the plan](../superpowers/plans/2026-10-12-backend-api.md) read, in this order:

1. "Global Constraints" and "Decisions that fill gaps in the spec".
2. Task 2. You do not build it, but your tasks use its route group, its `role` middleware and its error shape.
3. Tasks 3, 4, 9 and 10: yours.

Then [ARCHITECTURE.md](../../ARCHITECTURE.md), the sections "Users and what they see", "Tasks and swaps" and "Endpoints".

Write down every question and every place where the plan and ARCHITECTURE.md seem to disagree, and send the list to Naveen. Do not write backend code yet: it cannot be tested before Task 2, and code that was never run is not progress.

Done when: Naveen has your list of questions, or the message that you have none.

## Part 3: the frontend screens, with fake data

The web app has two areas in one React app (see "Overview" in ARCHITECTURE.md):

- `/office`: the office console, for the role `office`.
- `/scanner`: the scanner simulator, for the roles `inventory`, `driver`, `washing`, `outbound`. Laid out for a phone-sized screen.

You build the screens now. They show fake data. When the backend is finished, only one file changes and the screens talk to the real API.

### Set up

```bash
git clone https://github.com/Naveenrawat-max/laundry-poc.git
cd laundry-poc
git switch -c feat/frontend-prototype main
npm create vite@latest frontend -- --template react
cd frontend
npm install
npm install react-router-dom
npm run dev
```

Everything you make lives in the new folder `frontend/`. Nothing goes into `site/`.

### The one rule that makes this safe

**Every piece of data goes through `frontend/src/api.js`.** No screen holds data of its own and no screen calls `fetch`.

- `api.js` has one function per endpoint of the table "Endpoints" in ARCHITECTURE.md, for example `login(username, password)`, `listItems(filters)`, `lookup(reads)`, `openHandover(type, receiverId)`, `scanHandover(id, reads)`, `completeTask(id, payload, photo, signature)`.
- For now each function returns fake data from `frontend/src/fake-data.js`, as a Promise, so the screens are already written for a real network call.
- The shapes are the ones in ARCHITECTURE.md, section "How data flows". Use them exactly: a scan is `{source, reads: [{epc}]}`, the answer is `{results: [...], missing: [...]}` with `result` being `found`, `not_on_list`, `unknown` or `rejected`.
- A list answers `{"rows": [...]}`, one record answers `{"row": {...}}`.
- An error is `{"error": {"code": "...", "message": "..."}}`. Let some fake calls fail, so the screens show errors too.
- Where ARCHITECTURE.md does not give a shape, choose the simplest one and write it in `frontend/ASSUMPTIONS.md`: the endpoint, the shape you assumed, the screen that uses it. When the real API contract is published, that file is the list of things to check.

### The fake data

Copy the made-up world of [db/seed.sql](../../db/seed.sql), so the screens look like the real system will:

- 2 areas, 2 customers, 3 buildings, 5 zones, 3 mat types.
- 40 mats, `MOCK-0001` to `MOCK-0040`. The EPC of mat n is `4D4F434B` followed by n as 16 hex digits, for example `4D4F434B0000000000000001`.
- Mat statuses: `registered`, `ready`, `qc_passed`, `rewash`, `repair`, `scrapped`, `out_for_delivery`, `refused`, `at_customer`, `collected`, `in_washing`. Put a few mats in each, so every screen has something to show.
- 2 contracts, and a few tasks and handovers you make up.
- Six users to log in with: `office`, `inventory`, `driver`, `driver2`, `washing`, `outbound`.

### Screens, in this order

Stop after any step: each one is useful on its own.

1. **Shell and login.** A login screen. After login the role decides the area: `office` opens `/office`, the other four open `/scanner`. A user who opens the other area by its address is sent back. Log out.
2. **Office console.** The rows of the table "Users and what they see" in ARCHITECTURE.md, column Office: customers with their buildings and zones, areas, mat types, users (add, edit, switch off, never delete), contracts with their lines (activate, end), the mat list with search and filters, one mat with its history, the task list (create a collection task, assign a driver, move the due date, cancel), handovers with the issue list, correct a mat's status with a required note.
3. **Scanner simulator.** The screens per role are in the table "Scanner screens per role" in ARCHITECTURE.md. Build the **pile panel** first, because every scan screen uses it: a list of mats to tick, a Scan button, and the shortcuts *all expected*, *leave one out*, *add a stranger*, *unknown tag* (section "Scanner simulator"). Then Look up, then the handover screens, then the driver's Stop screen with photo, signature and signed name.

Times from the API are UTC, like `2026-10-12 21:03:11`. Screens show them in Dutch time. Dates like a due date are shown as they come.

### Rules for Part 3

1. Work on `feat/frontend-prototype`, made from `main`. Push that branch. Do not merge it.
2. Touch only `frontend/`. Never `site/`, `db/`, `docs/`, or the version badge in `README.md`. The version is set when the branch is merged.
3. Packages: React, React Router, and what Vite brings. Plain CSS. Ask before adding anything else.
4. The screens never decide whether a mat may move. They send the scan and show what comes back. In the fake `api.js` you may answer with fixed examples; do not rebuild the rules of the backend there.
5. Never commit `node_modules/` or a `.env` file.
6. Commits carry no `Co-Authored-By` line, no "Generated with" line and no other mention of an AI tool.

Done when: `npm run dev` starts without errors or warnings in the browser console, each of the six users lands in the right area, every screen of the step you finished shows fake data and an error case, and `frontend/ASSUMPTIONS.md` lists every shape you had to guess.

### What can change later

- The folder and the build set-up may move when the frontend plan is written. Keeping everything inside `frontend/` makes that a move, not a rewrite.
- Field names may shift when the API contract is published at the end of the backend work. Because every screen goes through `api.js`, that is a change in one file.

## When Task 2 is there

Naveen tells you when `feat/backend-api` on GitHub contains the commit `Add routing, the error shape and login at 0.4.1.` Then park Part 3, commit what you have, and switch to [backend-office.md](backend-office.md). Come back to Part 3 whenever you wait for a review or a merge.
