# Walkthrough: how the web app works, and how to test it end to end

Version: 0.1.0

This document has two parts. The first says how the web app is used, role by role. The second is the end-to-end test that was run on 13 October 2026 with 240 mats and 13 sites, written down so that anyone can run it again and compare.

The rules behind every step are in [ARCHITECTURE.md](../ARCHITECTURE.md). The loop itself is drawn in [FLOW.md](../FLOW.md).

## Part 1: the workflow of the web app

### Who gets what after login

There is one login page. The role of the user decides what opens.

| Role | Opens | Used on |
|---|---|---|
| Office | The office console with its menu | A computer |
| Inventory manager | His handheld screens, nothing else | The handheld |
| Delivery driver | His handheld screens, nothing else | The handheld |
| Washing house manager | His handheld screens, nothing else | The handheld |
| Outbound | His handheld screens, nothing else | The handheld |

A user who is not office and types an office address by hand still gets only his handheld. The server checks every call, so hiding a screen is never the protection.

### The office console

| Menu | Page | What the office does there |
|---|---|---|
| Workflow | How a mat travels | Reads the loop in plain words. Nothing to click. |
| Dashboard | Dashboard | Sees where every mat is, how many tasks are open, and what needs attention. Refreshes by itself. |
| Operations | Tasks | Gives each task a driver, moves a due date, cancels, makes a collection task, looks at the photo and signature of a closed stop. |
| Operations | Mats | Searches and filters all mats, 50 per page. Opens one mat for its tags, wash count and full history. Corrects a status, always with a note. |
| Operations | Handovers | Sees every pile that changed hands. The view "Issues" lists mats only one side scanned; the office marks each one handled with a note. |
| Setup | Customers | Adds customers, their buildings and the zones inside. A building is one stop for the driver. |
| Setup | Contracts | Says which mats lie in which zone and how often they are swapped. Starting a contract creates one delivery task per building. |
| Setup | Mat types and areas | Keeps the two short lists the rest picks from. |
| Setup | Users | Adds users and switches them off. Nothing is ever deleted. |
| Handheld | Handhelds | Shows the handheld of every role next to each other, see below. |

### The handhelds

Every handheld shows a short menu in the order of the working day.

| Role | Screens |
|---|---|
| Inventory manager | Register, Ready to use, Quality check, What drivers need, Hand over to driver, Receive from washing, Receive returns, Send to rewash, Mats in inventory, Look up |
| Delivery driver | My tasks, Load the van, Hand over to washing, Return refused mats, On my van, Look up |
| Washing house manager | Receive, In washing, Hand over to inventory, Look up |
| Outbound | What drivers need, Hand over to driver, Look up |

Until the real C72 scanner is connected, a scan is simulated: the screen lists the mats "in front of the scanner", the user ticks the ones in the pile and presses the scan button. The server gets exactly what the real scanner will send.

### The Handheld page in the office console

This page is for reviewing and demonstrating. It shows three handhelds side by side, or four with the switch "Outbound as a fourth". "One at a time" shows a single handheld and keeps the others where they were.

Each handheld on this page has its own login, like the real devices. So the inventory manager, a driver and the washing house manager can be logged in at the same moment in one browser window, and a pile handed over on one handheld shows up on the next within seconds. A handheld only accepts a user of its own role.

### One mat, once round the loop

1. **Office** starts a contract. One delivery task appears per building. The office gives each task a driver.
2. **Inventory** checks mats one by one (Quality check). Only a mat that passes may go out. A mat can also be sent to rewash, to repair, or scrapped.
3. **Handover A.** Inventory (or outbound) scans the pile out to a driver. The driver scans it in (Load the van). A mat moves the moment both have scanned it. The driver closes the handover.
4. **The stop.** The driver opens the task, says for each mat on his van in which zone he lays it, takes a photo, lets the customer sign, and closes the stop. The next swap is planned by itself.
5. **The swap.** At the next visit the driver lays clean mats and picks up the used ones in the same stop.
6. **Handover B.** The driver scans the used mats out to the washing house. The washing house manager scans them in.
7. **Handover C.** After washing, the washing house manager scans the mats out to inventory. This scan counts the wash: wash count plus one, wash date today. The mats are ready to use again. Inventory may scan them in, but does not have to.

What can go wrong, and what then happens:

| Situation | What the system does |
|---|---|
| The receiver does not scan a mat the giver scanned | The mat stays with the giver. After closing it is an issue for the office. |
| The customer refuses a mat | The driver marks it refused. It stays on his van and goes back to inventory without a wash (Return refused mats). |
| Fewer mats laid than the contract says | The stop cannot be closed without a note. |
| A stop without photo or signature | The handheld refuses before anything is sent. |
| The system and the real world disagree | The office corrects the mat's status. Its name and the note go into the mat's history. |

## Part 2: the end-to-end test

### The data

`db/demo.sql` goes on top of `db/seed.sql`. Together they give:

| | seed.sql | demo.sql | Together |
|---|---|---|---|
| Mats | 40 | 200 | 240 |
| Customers | 2 | 5 | 7 |
| Buildings (sites) | 3 | 10 | 13 |
| Zones | 5 | 18 | 23 |
| Mat types | 3 | 2 | 5 |
| Contracts, all draft | 2 | 5 | 7 |

Every mat is `ready`, every name starts with "Mock", every tag id starts with `4D4F434B`.

### Set it up

This needs the SSH tunnel to the test database, as described under "Run it locally" in the [README](../README.md).

```bash
cd site
php artisan test --filter DbTest                              # rebuilds the test database: 40 mats and six users
php artisan db:seed --class=DemoSeeder --env=testing --force  # adds the 200 mats and 10 buildings
npm install
npm run build
php artisan serve --host=127.0.0.1 --port=8000 --env=testing
```

The six users are `office`, `inventory`, `driver`, `driver2`, `washing` and `outbound`. They share the test password that stands in `site/tests/ApiTestCase.php`. Any later test run rebuilds the database, so load the demo data again after it.

Open `http://127.0.0.1:8000` and log in as `office`.

> [!NOTE]
> Through the tunnel every call takes two to three seconds, and PHP's local server answers one call at a time. Four handhelds asking for news at once make everything slow. Send a handheld that is not in use back to its menu. On the hosting the database is next door and this does not happen.

### The steps

Each step says what to do and what must be seen. All of it was done by clicking in the browser, except where "by script" is written: there the same API calls a handheld makes were sent from a script, only to save time.

**A. The office with 240 mats**

| # | Do | Must be seen |
|---|---|---|
| 1 | Dashboard | In inventory 240, on a van 0, at customers 0, in washing 0. Ready to use 240. |
| 2 | Operations, Mats | "240 mats", 50 rows, pages 1 to 5. Page 5 has 40 rows and ends with MOCK-0240. |
| 3 | Search `MOCK-023` while on page 5 | 10 mats. The search goes back to page 1 by itself. |
| 4 | Setup, Contracts | 7 contracts, all draft. |
| 5 | Start the contracts of Mock Supermarkets NV and Mock Hotel Group | A dialog says how many tasks are created and what can no longer be changed. Both become active. |
| 6 | Operations, Tasks | 5 delivery tasks, due on the start date. |
| 7 | Give the three supermarket tasks to Driver, the two hotel tasks to Driver2 | The name shows at once. "Driver assigned." |

**B. Getting the mats ready**

| # | Do | Must be seen |
|---|---|---|
| 8 | Handheld page, switch on "Outbound as a fourth", log in the four handhelds | Four menus next to each other. A driver's login on the inventory handheld is refused. |
| 9 | Inventory and outbound: What drivers need | Driver: 3 large, 3 small, 1 standing mat to go. Driver2: 2 logo, 3 large, 5 small. |
| 10 | Inventory, Quality check: MOCK-0041 pass | "Checked, may go out". |
| 11 | MOCK-0050 rewash with a note, MOCK-0051 scrap with a note | "Needs rewash", "Scrapped". |
| 12 | Pass 16 more mats (by script): 42, 43, 161 to 166, 191, 192, 231, 45 to 49 | All "Checked, may go out". |

**C. Handover A, twice**

| # | Do | Must be seen |
|---|---|---|
| 13 | Inventory, Hand over to driver, Driver. Tick MOCK-0041, 42, 43, 161, 162, 163, 231. Scan out. | "0 of 7 moved": nothing moves on one scan. |
| 14 | Driver, Load the van, open the handover, "All expected", scan in | "7 of 7 moved" on the driver's handheld, and on the inventory handheld by itself. |
| 15 | Driver closes the handover | "Handover closed. 7 mats changed hands." |
| 16 | Outbound, Hand over to driver, Driver2, "All that fit", scan out | "Scan out 10 mats", then "0 of 10 moved". |

**D. Three deliveries**

| # | Do | Must be seen |
|---|---|---|
| 17 | Driver, My tasks | Three deliveries with address and the mats per zone. |
| 18 | Mock Supermarket Centrum: 161 and 162 at Entrance, 231 at Bakery counter, sample photo, signature, close | "Stop closed. The next swap is planned." The message stays until "Back to my tasks". |
| 19 | Mock Supermarket West: 163 at Entrance, 41 at Staff entrance, close | Stop closed. |
| 20 | Mock Supermarket Noord: 42 at Entrance, 43 "Customer refused it", close without a note | Refused: "The mats do not match the contract. Add a note to close this stop." |
| 21 | Add a note, close | Stop closed. |
| 22 | Driver, On my van | 1 mat: MOCK-0043, "Refused, on the van". |

**E. A refused mat and a rewash**

| # | Do | Must be seen |
|---|---|---|
| 23 | Driver, Return refused mats, to Inventory, scan out. Inventory, Receive returns, scan in, close. | MOCK-0043 is "Ready to use" again, without a wash. |
| 24 | Inventory, Send to rewash, scan MOCK-0050 out. Washing, Receive, scan in, close. | Washing, In washing: MOCK-0050. |

**F. The swap and the wash**

| # | Do | Must be seen |
|---|---|---|
| 25 | Office, Tasks | Three swaps, planned 7 days after the delivery (the contract's interval). |
| 26 | Give the swap at Mock Supermarket Centrum to Driver | "Driver assigned." |
| 27 | Pass MOCK-0167, 168 and 232 and hand them to Driver (by script) | Driver's van: three clean mats. |
| 28 | Driver, My tasks, the swap. Lay the three new mats. Under "Mats to pick up" the three old ones are listed with their zone: "All found". Photo, signature, close. | Stop closed. |
| 29 | Driver, Hand over to washing, "All that fit", scan out. Washing, Receive, scan in, close. | "3 of 3 moved". In washing: 4 mats. |
| 30 | Washing, Hand over to inventory, "All that fit", scan out | "4 of 4 moved" on this one scan. All four "Ready to use". |
| 31 | Washing closes the handover. Inventory does nothing. | Closed. The second scan is optional for Handover C. |

**G. What the office sees afterwards**

| # | Do | Must be seen |
|---|---|---|
| 32 | Mats, search MOCK-0161, open it | Washes 1, last wash today. History, newest first: scanned out to ready, scanned in at washing, scanned out by the driver, picked up at Entrance, laid at Entrance, loaded, scanned out by inventory, quality check. Each line with time and name. |
| 33 | Driver handheld: sign out, log in as `driver2`. Load the van, "Leave one out", scan in 9, close. | MOCK-0192 "Missing. The giver scanned it, you did not." |
| 34 | Office, Handovers | 7 handovers. Issues: MOCK-0192, "Outbound scanned it out, Driver2 never scanned it in", mat is now "Checked, may go out". |
| 35 | Mark handled, with a note | "No open issues." |
| 36 | Mats, MOCK-0044, Correct status, In repair, with a reason | The mat is "In repair". History: "Corrected by the office", with the name and the note. |
| 37 | Start a handover on a handheld and leave it open. Office, Handovers, open it, Close handover. | The dialog says what closing does. Closed. |
| 38 | Tasks, New collection for Mock Supermarket West, then Cancel it | The task appears, the dialog says what cancelling does, the task is cancelled. |
| 39 | Tasks, Done, Proof | Photo and signature of the stop, who signed, which driver. |
| 40 | Customers, Mock Garage BV: add a building and a zone. Users: add a driver. | Both are in their lists at once. |

**H. The end state**

| Dashboard | Number |
|---|---|
| In inventory | 224 |
| On a van | 9 |
| At customers | 6 |
| In washing | 0 |
| Scrapped (not "in use") | 1 |
| Total | 240 |
| Open tasks | 5, of which 3 without a driver |
| Handover issues | 0 |
| Active contracts | 2 |

Mats by status: ready 222, checked 1, in repair 1, scrapped 1, on the van clean 9, at the customer 6. That is 240.

### What the test found

Three things were wrong and were fixed during the test. All three were in the screens; the server was right every time.

| Found | Was | Is now |
|---|---|---|
| Tasks: after picking a driver the dropdown jumped back to "No driver yet" until the list was loaded again | The save had worked, but the screen said otherwise for seconds | The picked driver shows at once |
| Handheld: "Stop closed" vanished by itself after a few seconds | The task list refreshed underneath and dropped the closed stop | The message stays until the driver taps "Back to my tasks" |
| Mats and Handovers: a dialog opened from the side panel could not be clicked or typed in | The side panel kept the pointer and the keyboard to itself | The side panel steps aside while the dialog is open, and comes back after |

### The same data in the automated tests

`site/tests/Feature/DemoDataTest.php` loads the demo data inside a test and checks that it hangs together: 240 mats, 13 buildings, no building without a zone, no contract that puts a mat in another customer's building, and enough mats of every type for all contracts. It also pages through all mats, starts the contract with three sites, and moves one pile of 25 mats from inventory to a driver in a single scan on each side.

```bash
cd site
php artisan test --filter DemoDataTest
```
