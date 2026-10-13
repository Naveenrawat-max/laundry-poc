# Walkthrough: how the web app works, and how to test it end to end

Version: 0.3.0

This document has two parts. The first says how the web app is used: every page of the office, every screen of each handheld, and the way back of a mat from the customer on day D + n. The second is the end-to-end test that was run on 13 October 2026 with 240 mats and 13 sites, written down so that anyone can run it again and compare.

The rules behind every step are in [ARCHITECTURE.md](../ARCHITECTURE.md). The loop itself is drawn in [FLOW.md](../FLOW.md).

## Part 1: the workflow of the web app

Part 1 has these chapters:

1. [Who gets what after login](#who-gets-what-after-login)
2. [The words on the screens](#the-words-on-the-screens)
3. [The loop in two halves: outbound and inbound](#the-loop-in-two-halves-outbound-and-inbound)
4. [The office, page by page](#the-office-page-by-page)
5. [Inbound: the swap on day D + n](#inbound-the-swap-on-day-d--n)
6. [The inventory manager, screen by screen](#the-inventory-manager-screen-by-screen)
7. [Outbound, screen by screen](#outbound-screen-by-screen)
8. [The delivery driver, screen by screen](#the-delivery-driver-screen-by-screen)
9. [The washing house manager, screen by screen](#the-washing-house-manager-screen-by-screen)
10. [How a scan works on the simulated handheld](#how-a-scan-works-on-the-simulated-handheld)
11. [What can go wrong, and what then happens](#what-can-go-wrong-and-what-then-happens)
12. [What the app does not do yet](#what-the-app-does-not-do-yet)

### Who gets what after login

There is one login page. The role of the user decides what opens.

| Role | Opens | Used on |
|---|---|---|
| Office | The office console with its menu | A computer |
| Inventory manager | The inventory handheld screens, nothing else | The handheld |
| Delivery driver | The driver handheld screens, nothing else | The handheld |
| Washing house manager | The washing handheld screens, nothing else | The handheld |
| Outbound | The outbound handheld screens, nothing else | The handheld |

A user who is not office and types an office address by hand still gets only the handheld screens. The server checks every call, so hiding a screen is never the protection.

Five wrong passwords for one username from one place within a minute block that login for the rest of the minute. A handheld login lasts 12 hours. One user is logged in on one handheld at a time: a new login on a second handheld ends the first.

### The words on the screens

A mat always has exactly one status. The screens show it as a small label.

| Label on the screen | Meaning | Where the mat is |
|---|---|---|
| Registered | Tagged and known, not released yet | Inventory |
| Ready to use | Clean and on the shelf, waiting for its quality check | Inventory |
| Checked, may go out | Passed the quality check | Inventory |
| Needs rewash | Failed the check, must be washed again | Inventory |
| In repair | Failed the check, is being repaired | Inventory |
| Scrapped | Thrown away. The end for this mat. | Gone |
| On the van, clean | Loaded, on its way to a customer | With a driver |
| Refused, on the van | The customer did not want it | With a driver |
| At the customer | Lying in a zone of a building | At the customer |
| On the van, used | Picked up at a customer, dirty | With a driver |
| In washing | In the washing house | Washing house |

Other words:

| Word | Meaning |
|---|---|
| Customer | The company that rents the mats |
| Building | One address of a customer. One building is one stop for the driver. |
| Zone | The spot in a building where a mat lies, for example "Main entrance" |
| Contract | Which mat types lie in which zones, how many, and after how many days they are swapped |
| n, "Days between swaps" | The number of days a mat lies at the customer before it is swapped. Set per contract, 1 to 365. |
| D | The day a driver closes a stop |
| Task | One visit to one building: a delivery, a swap or a collection |
| Stop | The same visit, as the driver sees it |
| Handover | One person passes a pile of mats to another, and both scan it |

### The loop in two halves: outbound and inbound

**Outbound** is everything that brings a clean mat to the customer.

1. The office starts a contract. One delivery task appears per building.
2. The office gives each task a driver.
3. The inventory manager checks mats (Quality check) and hands the pile to the driver (Handover A).
4. The driver lays the mats at the stop and closes it with a photo and a signature. This day is D.

**Inbound** is everything that brings the used mat back and makes it clean again.

5. Closing the stop creates the next task by itself: a swap for the same building, due on D + n.
6. The office gives the swap a driver.
7. On the day the driver lays clean mats and picks up the used ones in the same stop.
8. The driver hands the used mats to the washing house (Handover B).
9. After washing, the washing house manager hands them to inventory (Handover C). This counts the wash.
10. The mats are "Ready to use" again and wait for their next quality check. The loop starts over.

The inbound half has its own chapter below, because it is the part that runs on a date: [Inbound: the swap on day D + n](#inbound-the-swap-on-day-d--n).

### The office, page by page

The office works on a computer. The menu on top has five entries: Workflow, Dashboard, Operations, Setup and Handheld.

The usual order for a new customer is: Mat types and areas, Customers, Contracts, then Tasks every day.

#### The reminder on every page

A bar on top of every office page says what needs the office today. It is only there when there is something to say.

| The bar says | When |
|---|---|
| "2 tasks are late." | Open tasks whose due date has passed. The bar is red. |
| "3 tasks are due today, 1 without a driver." | Open tasks that are due today, and how many of them nobody drives yet |
| "1 task is due tomorrow and has no driver yet." | Open tasks for tomorrow that still need a driver |

"Open the tasks" in the bar goes to the Tasks page. The number of late tasks and tasks due today also stands in the title of the browser tab, for example "(3) Proti mat tracking", so it is seen while the office works in another tab. The bar looks for news once a minute.

#### Workflow

A page that explains the loop in plain words, for someone who is new. Nothing to click.

#### Dashboard

The first page to open every morning. It refreshes by itself every 30 seconds.

| Block | What it shows | Click |
|---|---|---|
| In inventory, On a van, At customers, In washing | How many mats are in each place, out of all mats that are not scrapped | Opens the mat list with exactly the mats in that place |
| Open tasks | All open tasks. Below it: how many are due today. | Opens Tasks |
| Not assigned yet | Open tasks without a driver | Opens Tasks |
| Handover issues | Mats that only one side scanned in a closed handover | Opens Handovers, view Issues |
| Active contracts | Running contracts. Below it: how many handovers are open right now. | Opens Contracts |
| Mats by status | One bar per status with its number | Opens the mat list with that status |
| Needs attention | Late tasks first, then tasks without a driver (six at most), then handover issues (four at most) | Opens Tasks or Handovers |

A task in "Needs attention" carries a red label "Late" when its due date has passed, and a label "No driver" when nobody is assigned. When nothing is late, every task has a driver and every handover added up, the block says "All clear".

#### Setup, Mat types and areas

Two short lists that the other pages pick from.

| Do | How |
|---|---|
| Add a mat type | "Add mat type", type the name, save. For example "Mat 85x150 grey". |
| Rename a mat type or switch it off | "Edit" in its row. A type that is switched off can no longer be picked for new mats and contracts. |
| Add a delivery area | "Add area", type the name, save. Every building belongs to one area. |
| Rename an area | "Edit" in its row. An area cannot be switched off. |

Nothing here is ever deleted.

#### Setup, Customers

The left side lists the customers. Click one to see it on the right, with its buildings and zones.

| Do | How |
|---|---|
| Add a customer | "Add customer". Name is needed; contact person, phone and email are optional. |
| Change a customer or switch it off | "Edit customer" |
| Add a building | "Add building": name, street and number, postcode, city and delivery area. There must be at least one area first. |
| Change a building or switch it off | "Edit" on the building |
| Add a zone | "Add zone" on the building, then a name such as "Front door" |
| Rename a zone or switch it off | Click the zone's name. A zone that is switched off is shown struck through. |

The contact person of the customer is what the driver sees at the stop, and is filled in as the name of the person who signs.

#### Setup, Contracts

A contract says which mats lie where and how often they are swapped. Each contract is a card: the customer, "Swap every n days", the start date, the lines, and a label Draft, Active or Ended.

**Make a contract**

1. "New contract".
2. Pick the customer.
3. Fill in "Days between swaps". This is n. It may be 1 to 365. It starts at 14.
4. Fill in the start date. The first delivery is due on this day.
5. Under "Mats per zone" add one line per zone and mat type: the zone, the mat type and how many. "Add a line" gives another line. Only zones of this customer can be picked.
6. Save. The contract is a draft. A draft can be changed as often as needed.

**Start a contract**

1. "Start contract" on the card.
2. A dialog says how many tasks are made and what can no longer be changed.
3. Confirm. The contract becomes Active. One delivery task is created for each building that has a line, due on the start date. The message says how many tasks now wait for a driver.

After the start, the mats per zone and the start date are fixed. Only "Days between swaps" can still change. When it changes, every open swap task of this contract moves by the difference: from 14 to 7 days, every open swap comes 7 days earlier.

A contract without a line cannot be started.

**End a contract**

1. "End contract" on the card.
2. Confirm. The contract ends today and its open swap tasks are cancelled. This cannot be undone.
3. The mats that still lie at the customer stay there. Make a collection task for each building to get them back (see Tasks).

#### Operations, Tasks

The page the office uses most. Every row is one visit to one building. The list refreshes by itself every 20 seconds.

| Column | What it shows |
|---|---|
| Due | The due date. A red label "Late" when the task is open and the date has passed. |
| Task | Delivery, Swap or Collection |
| Stop | The building, and below it the customer, the city and the area |
| Driver | For an open task a dropdown with all active drivers. For a closed task the name. |
| Status | Open, Done or Cancelled. A red label "Note" when the driver wrote a note at the stop. |

Above the list: a filter on type (all, deliveries, swaps, collections) and on status (Open, Done, Cancelled, All). The page opens on "Open". Open tasks are sorted by due date, the oldest on top. The list shows 200 tasks at most.

| Do | How | What happens |
|---|---|---|
| Give a task a driver | Pick the name in the dropdown of the row | "Driver assigned." The task is on that driver's handheld within seconds. Inventory and outbound see which mats the driver now needs. |
| Give it to another driver | Pick the other name | The task moves from one handheld to the other |
| Take it back | Pick "No driver yet" | "Task taken back from the driver." It disappears from the handheld. |
| Move the date | "Move", pick the new date, save | Only this one task moves |
| Cancel | "Cancel". A dialog says what cancelling does. | The task disappears from the driver's list. It cannot be opened again, and no next swap is planned from it. |
| Make a collection | "New task", kind "Collection": the contract, the building, the due date | A task where the driver only picks mats up. Only a contract that was started can be picked. |
| Plan a swap by hand | "New task", kind "Swap": the contract, the building, the due date | A swap like any other: the driver lays clean mats and picks up the used ones, and closing it plans the next swap. Only an active contract can be picked, and only for a building that has no open delivery or swap. |
| Look at the proof | "Proof" in the row of a done task | The photo, the signature, who signed, which driver, when, and the driver's note |

> [!IMPORTANT]
> Cancelling a swap stops the repeat for that building: the next swap is only planned when a stop is closed. When only the date is wrong, use "Move", not "Cancel". To start the visits again after a cancel, plan a swap by hand with "New task".

#### Operations, Mats

Every mat, where it is now, and everything that ever happened to it.

| Do | How |
|---|---|
| Find a mat | Type a part of the SKU or of the tag id in the search box. The search starts when the typing stops. |
| Filter | Pick a status or a place ("In inventory" and "On a van" are several statuses at once), a mat type, or both |
| Page through | 50 mats per page, the page numbers are below the list |
| See where a mat is | The column "Where": "With (driver)", or customer, building and zone, or "Washing house", "Inventory", "Gone" |
| See how often it was washed | The columns "Washes" and "Last wash" |
| Open one mat | Click its row. A panel opens on the right. |

The panel shows the status, the number of washes, the last wash and the one before, the tag id (EPC) and the two tag numbers (TID), and the history. The history has the newest line on top. Each line says what happened, the new status, the time, the person, the zone when there is one, and the note when there is one.

**Correct a status.** Use this when the system and the real world disagree, for example a mat that is lost or was put back on the shelf without a scan.

1. Open the mat and press "Correct status".
2. Pick the new status.
3. For a status on a van, pick the driver who carries it. For "At the customer", pick the customer, the building and the zone.
4. Write why. This is required.
5. Save. The history gets a line "Corrected by the office" with the name of the office user and the note.

#### Operations, Handovers

Every pile that changed hands. The page refreshes by itself every 15 seconds.

**View "All handovers".** One row per handover: which one (Handover A, B, C, Return or Rewash), from whom, to whom, how many mats were given and how many received, Open or Closed, and when it was opened. The newest 100 are shown, the open ones on top. Click a row for its pile: per mat a tick for "Given" and a tick for "Received", and the status the mat has now.

**View "Issues".** A mat lands here when its handover was closed and only one side scanned it. The row says which: "(giver) scanned it out, (receiver) never scanned it in", or the other way round. It also shows the status the mat has now.

| Do | How |
|---|---|
| Handle an issue | Find out where the mat really is. Press "Mark handled" and write what was found. This is required. |
| Put the mat right | Marking handled only closes the issue. When the mat is somewhere else than the system thinks, also correct its status in the mat list. |
| Close a handover that was left open | Open it and press "Close handover". Normally the receiver closes it on the handheld. After closing nobody can scan into it, and mats with one scan become issues. |

In Handover C the second scan is optional, so a mat with only the washing house's scan is normal there and is no issue.

#### Setup, Users

One login per person, one role per login.

| Do | How |
|---|---|
| Add a user | "Add user": name, username, password and role. The username is 3 to 60 letters, digits, dots, dashes or underscores and cannot be changed later. The password is 10 to 72 characters. |
| Change a name or a role | "Edit" in the row |
| Give a new password | "Edit", fill in "New password". Leave it empty to keep the old one. |
| Stop someone from logging in | "Edit", switch off "Can log in". The person is logged out at the next step. |

A user is never deleted, only switched off. An office user cannot change the own role or switch the own login off, so the office can never lock itself out.

The very first office user is made on the server, once, with `php artisan laundry:office-user`. Every later user, also another office user, is made on this page.

#### Handheld

This page is for reviewing and demonstrating. It shows three handhelds side by side (inventory manager, delivery driver, washing house manager), or four with the switch "Outbound as a fourth". "One at a time" shows a single handheld and keeps the others where they were.

Each handheld on this page has its own login, like the real devices. So the inventory manager, a driver and the washing house manager can be logged in at the same moment in one browser window, and a pile handed over on one handheld shows up on the next within seconds. A handheld only accepts a user of its own role.

### Inbound: the swap on day D + n

This chapter follows one mat back from the customer: how the office gets to know that it must be changed, how the swap gets a driver, and how the driver picks the mat up.

#### The rule

- n is "Days between swaps" on the contract.
- D is the day the driver closes a stop at a building.
- At that moment the server makes one new task: a **swap** for the same building, due on **D + n**, without a driver.

Nobody plans the swap by hand, and no nightly job runs. The next visit is born the moment the previous one is closed.

Example with n = 7:

| Date | What happens | Result |
|---|---|---|
| Mon 19 Oct | The driver closes the delivery at "Mock Supermarket Centrum". D = 19 Oct. | A swap for this building appears, due Mon 26 Oct, no driver |
| 19 to 25 Oct | The office gives the swap a driver | It is on the driver's handheld, with its date |
| Mon 26 Oct | The driver closes the swap. D = 26 Oct. | The next swap appears, due Mon 2 Nov |
| Mon 2 Nov | And so on, every 7 days, for as long as the contract is active | |

#### 1. How the office gets to know

The app sends no email and no text message. The office sees it inside the app, in three places.

**On every page**, the reminder bar on top: it says how many tasks are late, how many are due today, and how many are due tomorrow and still have no driver. The same number stands in the title of the browser tab.

**On the Dashboard**, from the minute the stop is closed:

- "Open tasks" goes up by one.
- "Not assigned yet" goes up by one.
- "Needs attention" gets a row "Swap at (building)", with the customer, "due (date)" and the label "No driver". The block shows six tasks at most, late ones first.

So a new swap asks for attention at once, n days before it is due. That is on purpose: the office can give it a driver right away, and it then waits on the handheld with its date.

**On the day itself**, D + n:

- "Open tasks" on the Dashboard says how many are "due today".
- On the Tasks page the swap stands near the top of the open tasks, because the list is sorted by due date, the oldest first.

**When the day has passed and the stop is still open:**

- The Tasks page shows a red label "Late" next to the date.
- The Dashboard lists it first under "Needs attention", with "Late".
- The driver's handheld shows "Late" on the task.

Nothing else happens by itself. A late task stays open until a driver closes it or the office cancels it.

To see all coming swaps: Operations, Tasks, filter the type on "Swaps". The list is in the order of the due dates.

#### 2. How the swap gets a driver

1. Operations, Tasks.
2. Find the swap. It has "No driver yet" in the column Driver.
3. Pick a driver in that dropdown. Only active drivers are listed.
4. The message "Driver assigned." appears. Nothing else is needed.

The swap does not go to the driver who did the delivery by itself. The office picks, every time. That way another driver can take over a building from one visit to the next.

What the assignment sets in motion:

| Who | Screen | What changes |
|---|---|---|
| The driver | My tasks | The swap is in the list: "Swap: (building)", the address, and which mats go to which zone. A label shows the date, "Today" on the day, or "Late" after it. |
| The inventory manager | What drivers need | The clean mats this swap needs are added to that driver's line, per mat type |
| Outbound | What drivers need | The same |

The office can still change things while the task is open: another driver, "No driver yet" to take it back, "Move" for another date.

#### 3. Getting the clean mats ready

A swap needs clean mats first: the same number and types the contract names for that building.

1. The inventory manager looks at "What drivers need". Per driver and mat type it says how many are still "to go" and how many are already on the van.
2. The inventory manager passes that many mats in "Quality check".
3. The inventory manager (or outbound) hands them over in "Hand over to driver". The driver scans them in under "Load the van". This is Handover A.

The mats are now "On the van, clean" with this driver.

#### 4. How the driver picks the mats up

1. The driver opens "My tasks" and taps the swap.
2. The top of the screen shows the building, the address and the contact person with the phone number.
3. **Mats to lay.** The contract's lines are listed, for example "2 x (mat type) at Entrance". Below them is every clean mat on the van, each with a dropdown: "Stays on the van", "Lay at (zone)" or "Customer refused it". The driver picks the zone for every mat that is laid.
4. **Mats to pick up.** This list holds every mat the system knows in this building, with the zone it lies in. The driver ticks each mat that is really found and taken. "All found" ticks them all.
5. **Proof.** "Take a photo" opens the camera. The name of the person who signs is filled in with the customer's contact person and can be changed. The customer signs in the white box with a finger.
6. **Note.** Needed when something differs: a mat that could not be found, fewer mats laid than agreed, a refused mat.
7. "Close the stop".

Nothing is saved until the stop is closed. The server then checks everything and saves all of it or nothing.

| The server checks | When it fails |
|---|---|
| There is a photo and a signature | The stop is refused |
| Every mat that is laid or refused is on this driver's van as "On the van, clean" | The stop is refused, the message names the mat |
| Every mat that is picked up lies in this building | The stop is refused, the message names the mat |
| The laid mats match the contract, zone by zone and type by type, and every mat in the building is picked up | The stop is refused until there is a note: "The mats do not match the contract. Add a note to close this stop." |

When the stop is closed:

| Mat | New status | Where |
|---|---|---|
| Laid | At the customer | In the zone the driver picked |
| Picked up | On the van, used | With this driver |
| Refused | Refused, on the van | With this driver |

The handheld says "Stop closed. The next swap is planned. The office will assign it." The next swap exists from this moment, due n days from today. The office finds the photo, the signature and the note under "Proof" on the Tasks page.

A mat that could not be found is left unticked, with a note. It stays "At the customer" in the system. The office reads the note (the task has the label "Note") and corrects the mat's status when it is really lost.

#### 5. From the van to the washing house

1. The driver opens "Hand over to washing", picks the washing house manager and starts the handover. This is Handover B.
2. The driver scans the used mats out. Only mats that are "On the van, used" on this van are accepted.
3. The washing house manager opens "Receive", opens the pile and scans the mats in.
4. A mat moves to "In washing" the moment both have scanned it.
5. The washing house manager closes the handover.

#### 6. From the washing house back to the shelf

1. After washing, the washing house manager opens "Hand over to inventory", picks the inventory manager and starts the handover. This is Handover C.
2. The washing house manager scans the washed mats out. On this one scan each mat becomes "Ready to use", its wash count goes up by one and its wash date becomes today.
3. The washing house manager closes the handover. The inventory manager may scan the mats in under "Receive from washing", but does not have to.

The mat is back on the shelf. Before it goes out again it passes the quality check once more.

#### The rules of the date

| Situation | What happens |
|---|---|
| The driver closes the stop a day late | D is the day the stop is really closed, not the due date. The next swap is n days after that day. |
| The driver closes the stop before its due date | Allowed. The next swap is n days after the day of closing. |
| The office moves the due date of a swap | Only that task moves. The swap after it is still counted from the day the stop is closed. |
| The office changes "Days between swaps" on an active contract | Every open swap of that contract moves by the difference |
| The office cancels a swap | No next swap is planned from it. The repeat for that building stops and the mats stay at the customer, until the office plans a swap by hand with "New task". |
| The office ends the contract | Its open swaps are cancelled. The mats stay at the customer until a collection task brings them back. |
| A collection is closed | The driver only picks up. No next swap is planned. The handheld says "Nothing further is planned for this building." |

### The inventory manager, screen by screen

The inventory manager works on the handheld, in the storeroom. The menu follows the working day.

| Screen | Used for |
|---|---|
| Register | Tag a new mat |
| Ready to use | Release new or repaired mats |
| Quality check | Pass, rewash, repair or scrap one mat |
| What drivers need | See how many mats each driver still needs |
| Hand over to driver | Handover A: scan a pile out to a driver |
| Receive from washing | Handover C: the optional scan in |
| Receive returns | Take back mats a customer refused |
| Send to rewash | Send mats that failed the check to the washing house |
| Mats in inventory | See everything on the shelf |
| Look up | See what a mat is |

#### Register

A new mat gets two tags that carry the same tag id.

1. "Read the two tags". The handheld reads both.
2. The screen shows the tag id that is on both tags, and the number of the first and of the second tag.
3. Fill in the mat number (SKU) and pick the mat type.
4. "Register mat". The mat is "Registered".

The server refuses a tag id, a SKU or a tag number that is already in use, and says on which mat.

#### Ready to use

A registered mat, and a mat that comes back from repair, must be released before it can be checked.

1. Scan the mats that are ready.
2. "Mark ready". They become "Ready to use".

A mat in any other status is refused, with the reason.

#### Quality check

One mat at a time. Only a mat that passes may go to a customer.

1. Scan one mat. It must be "Ready to use".
2. The screen shows its SKU, its type and how often it was washed.
3. Look at the mat. For a mat that fails, write what is wrong.
4. Press one of four buttons.

| Button | New status | What comes next |
|---|---|---|
| Pass | Checked, may go out | "Hand over to driver" |
| Rewash | Needs rewash | "Send to rewash" |
| Repair | In repair | After the repair: "Ready to use", then a new quality check |
| Scrap | Scrapped | Nothing. The mat is out of use. |

#### What drivers need

One card per driver who has an open delivery or swap. Per mat type it says how many mats are still "to go" and how many are already on the van. The numbers come from the contracts of the driver's open tasks. The screen refreshes by itself.

A driver appears here as soon as the office gives the driver a task.

#### Hand over to driver (Handover A)

1. Pick the driver under "Hand over to". The card "(driver) still needs" shows what to give, per mat type.
2. "Start the handover".
3. Scan the pile and press "Scan out". Only mats that are "Checked, may go out" are accepted.
4. The table shows each mat with a tick under "Given". Nothing has moved yet.
5. The driver scans the pile in on the driver's own handheld. The tick under "Received" appears here by itself, and the mat is now with the driver.
6. The driver closes the handover.

The inventory manager cannot close this handover. A mat that only the inventory manager scanned stays in inventory and becomes an issue for the office once the handover is closed.

Leaving the screen loses nothing: an open handover is picked up again when the screen is opened.

#### Receive from washing (Handover C)

Washed mats are "Ready to use" the moment the washing house manager scans them out. This screen is the optional count on arrival.

1. Open the pile that is waiting.
2. Scan what really arrived.
3. "Done, close the handover".

#### Receive returns

For mats a customer refused. They come back without a wash.

1. The driver starts the return on the driver's handheld and scans the mats out.
2. Open the pile here and scan the mats in. They become "Ready to use".
3. "Done, close the handover".

#### Send to rewash

For mats that got "Rewash" in the quality check.

1. Pick the washing house manager and "Start the handover".
2. Scan the mats out. Only mats that are "Needs rewash" are accepted.
3. The washing house manager scans them in and closes the handover. The mats are "In washing".

They come back through Handover C like every washed mat, and that wash is counted.

#### Mats in inventory

A list of every mat on the shelf: registered, ready to use, checked, needing rewash and in repair. Each with its type, its last wash date and its status. The first 50 are shown.

#### Look up

Scan any mat to see what it is and which status it has. Nothing changes.

### Outbound, screen by screen

Outbound is the person who only hands piles to drivers. The role has three screens, and they work exactly like the same screens of the inventory manager.

| Screen | Used for |
|---|---|
| What drivers need | See how many mats each driver still needs |
| Hand over to driver | Handover A: scan a pile out to a driver |
| Look up | See what a mat is |

Outbound cannot register, check or receive mats.

### The delivery driver, screen by screen

| Screen | Used for |
|---|---|
| My tasks | The stops to drive: deliver, swap, collect |
| Load the van | Handover A: scan in the pile from inventory |
| Hand over to washing | Handover B: scan the used mats out |
| Return refused mats | Give refused mats back to inventory |
| On my van | See every mat on the van |
| Look up | See what a mat is |

A usual day: Load the van, My tasks stop by stop, Hand over to washing, and Return refused mats when there are any.

#### Load the van (Handover A)

1. The pile shows up here as soon as the inventory manager or outbound starts the handover. It says from whom, and how many mats are given.
2. Open it.
3. Scan the pile and press "Scan in". "All expected" ticks every mat the giver scanned.
4. Each mat that both scanned is now "On the van, clean".
5. A mat the giver scanned and the driver did not is listed as "Missing". A mat the driver scans and the giver did not is "Not on the list". Both become issues for the office.
6. "Done, close the handover". The receiver always closes.

#### My tasks

The open tasks of this driver, the soonest on top. The list refreshes by itself. Each task shows:

- what it is and where: "Delivery: (building)", "Swap: (building)" or "Collection: (building)";
- a label with the due date, "Today", or a red "Late";
- the customer and the address;
- which mats go to which zone, or "Pick up only" for a collection.

Tap a task to open the stop. What the stop asks depends on the task:

| Task | Mats to lay | Mats to pick up |
|---|---|---|
| Delivery | Yes | No |
| Swap | Yes | Yes |
| Collection | No | Yes |

**Mats to lay.** Every clean mat on the van is listed. Per mat the driver picks "Lay at (zone)", "Customer refused it", or leaves "Stays on the van". When the van holds no clean mats, the screen says to load first.

**Mats to pick up.** Every mat the system knows in this building, with its zone. Tick the ones that are taken, or "All found".

**Proof.** A photo, the name of the person who signs, and the signature. Without a photo and a signature the handheld refuses before anything is sent. "Use a sample" gives a stand-in photo, so a review needs no camera.

**Note.** Needed when the mats do not match the contract or a mat in the building is not picked up.

"Close the stop" saves everything at once. "Back, nothing is saved" leaves the stop as it was. After closing, the message "Stop closed" stays until the driver taps "Back to my tasks".

The swap stop, with every check the server does, is described step by step under [How the driver picks the mats up](#4-how-the-driver-picks-the-mats-up).

#### Hand over to washing (Handover B)

1. Pick the washing house manager and "Start the handover".
2. Scan the used mats out. Only mats that are "On the van, used" on this van are accepted.
3. The washing house manager scans them in. A mat that both scanned is "In washing".
4. The washing house manager closes the handover. The driver cannot.

#### Return refused mats

1. Pick the inventory manager and "Start the handover".
2. Scan the refused mats out. Only mats that are "Refused, on the van" on this van are accepted.
3. The inventory manager scans them in under "Receive returns" and closes. The mats are "Ready to use" again, without a wash.

#### On my van

Every mat this driver carries: clean, refused and used, each with its status. "Your van is empty." when there is none.

#### Look up

Scan any mat to see what it is. Nothing changes.

### The washing house manager, screen by screen

| Screen | Used for |
|---|---|
| Receive | Handover B and rewash: scan in what arrives |
| In washing | See the mats in the washing house |
| Hand over to inventory | Handover C: scan the washed mats out |
| Look up | See what a mat is |

The washing house screens show the mat number, the mat type, the wash date and the status. They show no customer and no address.

#### Receive

Two kinds of pile arrive here: used mats from a driver (Handover B) and mats from inventory that must be washed again (Rewash).

1. A pile shows up as soon as the giver starts the handover. It says from whom, and how many mats are given.
2. Open it.
3. Scan every mat that really arrives and press "Scan in". "All expected" ticks every mat the giver scanned.
4. Each mat that both scanned is "In washing".
5. A mat the giver scanned that is not in the pile is listed as "Missing". Leave it: it stays with the giver and becomes an issue for the office.
6. "Done, close the handover".

#### In washing

The mats that are in the washing house now, each with its type and its last wash date. The first 50 are shown. The list refreshes by itself. "Nothing is in washing." when it is empty.

#### Hand over to inventory (Handover C)

This is the scan that counts the wash. Do it when the mats are washed and go back, not before.

1. Pick the inventory manager and "Start the handover".
2. Scan the washed mats out. Only mats that are "In washing" are accepted.
3. On this one scan each mat becomes "Ready to use". Its wash count goes up by one, the wash date becomes today, and the old wash date is kept as "wash before that".
4. "Done, close the handover". In Handover C the washing house manager may close, because the inventory manager's scan is optional.

Scanning a mat twice does not count two washes: the second scan changes nothing.

#### Look up

Scan any mat to see what it is. Nothing changes.

### How a scan works on the simulated handheld

Until the real C72 scanner is connected, a scan is simulated. The server gets exactly what the real scanner will send, so every rule in this document already works as it will later.

Every screen that scans has a box "Pile in front of the scanner".

| Part of the box | What it does |
|---|---|
| The list | The mats the scanner could hear. Tick the ones that are in the pile. |
| "Fits this step" and "All mats" | The first shows only mats this step accepts. The second shows every mat, to try a wrong one. |
| All that fit, All expected | Ticks every mat that fits |
| Leave one out | Ticks all but one, to see what a missing mat does |
| Add a stranger | Adds a mat that does not belong in this step |
| Unknown tag | Adds a tag no mat has |
| Clear | Unticks everything |
| The button | "Scan out 7 mats", "Scan in 7 mats", "Mark ready 3 mats" and so on. It sends the scan. |

Below the box, "Last scan" shows what the server said, one line per mat:

| Line | Meaning |
|---|---|
| Found | The mat was accepted |
| Refused | The mat has the wrong status for this step. The reason is below it. |
| Not on the list | The receiver scanned a mat the giver did not scan |
| Unknown tag | No mat has this tag |
| Missing | The giver scanned it, the receiver did not |

In a handover the screen also shows a table with every mat and two ticks, "Given" and "Received", and a line such as "7 of 7 moved". The other side's scans appear by themselves within a few seconds.

On the driver's stop the mats are not scanned in the simulation: the driver picks a zone per mat and ticks the mats that are picked up.

### What can go wrong, and what then happens

| Situation | What the system does | Who acts |
|---|---|---|
| The receiver does not scan a mat the giver scanned | The mat stays with the giver. After closing it is an issue. | The office: Handovers, Issues |
| The receiver scans a mat the giver did not scan | The scan is recorded, the mat does not move. After closing it is an issue. | The office |
| A mat with the wrong status is scanned | That mat is refused with the reason. The other mats of the scan go through. | The person scanning |
| A mat is scanned twice | Nothing changes the second time | Nobody |
| A mat is already in another open handover | It is refused here | The person scanning |
| A handover is left open | It stays open and can be picked up again | The receiver, or the office closes it |
| The customer refuses a mat | The driver marks it refused. It stays on the van and goes back to inventory without a wash. | The driver: Return refused mats |
| Fewer mats laid than the contract says | The stop cannot be closed without a note | The driver |
| A mat that should be picked up is not found | The stop cannot be closed without a note. The mat stays "At the customer". | The driver writes the note, the office corrects the mat |
| A stop without photo or signature | The handheld refuses before anything is sent | The driver |
| A swap is not done on its day | It gets the label "Late", stays open, and the red reminder bar counts it on every office page | The office: move it, or give it to another driver |
| A driver is ill | Nothing by itself | The office gives the open tasks to another driver, one by one |
| The system and the real world disagree | Nothing by itself | The office corrects the mat's status, with a note |
| Someone leaves the company | Nothing by itself | The office switches the user off. A task can only be given to an active driver. |

### What the app does not do yet

These are choices of this first version, written down so that nobody waits for them.

| Not there | What to do now |
|---|---|
| No email or text message when a swap comes due or is late. The reminder is inside the app only. | Keep the app open in a browser tab: the bar and the number in the tab title say what is late and what is due today |
| A new swap gets no driver by itself | The office assigns every task |
| No button to make a delivery task by hand | A delivery comes from starting a contract. For a building that has no visit planned, plan a swap by hand: at a swap the driver lays mats too. |
| No route or order for a driver's stops | The driver's list is sorted by due date |
| No calendar or week view of the tasks | Filter the Tasks page on "Swaps" and read the due dates |
| A started contract cannot get other mats or zones | End it, collect the mats, and start a new contract |
| No separation between clients of the laundry | Every office user sees every customer |
| The real C72 scanner is not connected | Scans are simulated, as described above |

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
| 38 | Tasks, New task, kind Collection, for Mock Supermarket West, then Cancel it | The task appears, the dialog says what cancelling does, the task is cancelled. |
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
