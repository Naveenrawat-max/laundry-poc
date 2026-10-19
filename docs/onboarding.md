# Onboarding: how to work with the web app

Version: 0.5.9

This is the guide for a first day. It is written for every person who signs in: office staff, the inventory manager, delivery drivers, the washing house manager, outbound, and someone who is showing the app to a colleague.

Read the chapter for your role, then [A day when everyone works together](#a-day-when-everyone-works-together). The long reference, with every screen and the recorded test of 240 mats, stays in [walkthrough.md](walkthrough.md). The rules behind the steps are in [ARCHITECTURE.md](../ARCHITECTURE.md). The loop is drawn in [FLOW.md](../FLOW.md).

## Sign in

1. Open the address the office gave you, in a normal browser. A computer is the right place for the office. A phone is the right place for a handheld role: on a phone the handheld fills the screen.
2. The page says **Proti mats** and **Sign in**. Type the username and password that were made for you. Press **Sign in**.
3. Your role decides what opens. Typing another address by hand does not open someone else's screens. The server checks every step.

| Role | What opens | Where you work |
|---|---|---|
| Office | The office menu: Workflow, Dashboard, Operations, Setup, Handheld | A computer |
| Inventory manager | The storeroom handheld | Handheld, or a phone |
| Delivery driver | The van handheld | Handheld, or a phone |
| Washing house manager | The washing handheld | Handheld, or a phone |
| Outbound | Three screens: what drivers need, hand over to driver, look up | Handheld, or a phone |

Until the Chainway C72 scanner is connected, a scan is a box on the screen called **Pile in front of the scanner**. The server receives the same kind of read the real scanner will send later. [How a scan works today](#how-a-scan-works-today) explains that box.

A few rules that apply to everyone:

| Situation | What happens |
|---|---|
| Five wrong passwords for one username from one place within a minute | Sign-in is blocked for the rest of that minute |
| A handheld stays signed in | 12 hours |
| The same person signs in on a second handheld | The first handheld is signed out |
| Office, sign out | **Sign out** at the top right |
| Handheld, sign out | The door icon at the top right of the device |
| Light or dark | The circle at the top right. On a phone handheld the header is hidden; the office menu still has the switch |

Times on the screens are Dutch time.

## The idea, in one page

Proti rents door mats, delivers them with its own drivers, and washes them in its own washing house. Every mat has two RFID tags and exactly one status. The small label on the screen is that status.

| Label | Where the mat is |
|---|---|
| Registered | Inventory. Tagged, not released yet |
| Ready to use | Inventory. Clean, waiting for the quality check |
| Checked, may go out | Inventory. Passed the check, may go to a driver |
| Needs rewash | Inventory. Must be washed again |
| In repair | Inventory. Being repaired |
| Scrapped | Thrown away. The end for this mat |
| On the van, clean | With a driver, on the way to a customer |
| Refused, on the van | The customer did not want it. Still with the driver |
| At the customer | Lying in a zone of a building |
| On the van, used | Picked up, dirty, with the driver |
| In washing | In the washing house |

Words the screens use:

| Word | Meaning |
|---|---|
| Customer | The company that rents the mats |
| Location | One customer address. One location is one stop. Locations belong to that customer, not to a shared delivery area |
| Zone | The spot at the location, for example "Main area" |
| Contract | One location's mats, quantities, start date and D+n. The order screen shows the schedule, not a contract number |
| D+n | Days after a delivery or swap before the next swap. Set on that location's contract, from 1 to 365. It starts at 14 |
| Task | One visit to one building: a delivery, a swap, or a collection |
| Stop | The same visit, as the driver sees it |
| Handover | One person passes a pile to another. Both scan it |

The loop has two halves.

**Outbound** brings a clean mat to the customer.

1. The office starts a contract. One delivery task appears per building.
2. The office gives each task a driver.
3. The inventory manager checks the mats and hands the pile to the driver. That is Handover A. Outbound can do this hand-over when that is a separate person.
4. The driver lays the mats and closes the stop with a photo and a signature. That day is D.

**Inbound** brings the used mat back and makes it clean again.

5. Closing the stop creates the next task by itself: a swap for the same building, due on D plus the days between swaps. Nobody has to plan that swap by hand.
6. The office gives the swap a driver.
7. On that visit the driver lays clean mats and picks up the used ones.
8. The driver hands the used mats to the washing house. That is Handover B.
9. After washing, the washing house manager hands them back to inventory. That is Handover C. This scan counts the wash.
10. The mats are **Ready to use** again and wait for the next quality check.

A mat changes hands only when both sides have scanned it. A mat scanned by one side stays with the giver. When the handover is closed, that mat becomes an issue for the office. Scanning the same mat again changes nothing, so a wash is never counted twice.

## If you work in the office

You work on a computer. The menu on top has five entries.

| Menu | What you open it for |
|---|---|
| Workflow | The loop in plain words. Nothing to fill in. A good page for a new colleague |
| Dashboard | The first page every morning. It refreshes by itself every 30 seconds |
| Operations | Tasks, Mats, and Handovers. This is the daily work |
| Setup | Customers and their locations, Contracts, Categories and SKUs, and Users |
| Handheld | The scanners of the other roles, side by side, for review and for showing the system |

Your name is at the top right, next to **Sign out**.

### Set the company up once

Do this in order. Later pages pick from the lists you make here.

**1. Setup, Categories and SKUs**

Create a category, then a subcategory, then a product SKU with size, colour and untagged stock. There is no delivery-area list on this page. Locations belong to customers.

**2. Setup, Customers**

The left side lists the customers. Click one to see its locations and zones on the right.

| Do | How |
|---|---|
| Add a customer | **Add customer**. The name is required. Contact person, phone and email are optional. Add at least one location: name, street and number, postcode and city. More locations can be added in the same form |
| Change a customer, or switch it off | **Edit customer**. This does not change locations |
| Add another location | **Add location** on the customer. A Main area is created with it. Extra zones can be added later |
| Change a location, or switch it off | **Edit** on the location |
| Add a zone | **Add zone** on the location, then a name such as "Front door" |
| Rename a zone, or switch it off | Click the zone's name. A switched-off zone is shown struck through |

The contact person is the name the driver sees at the stop, and it is filled in as the person who signs.

**3. Setup, Contracts**

Each contract is a card for one location: the customer, the location, the start date, D+n, the lines, and a label Draft, Active, or Ended. The card does not show a contract number.

Make a contract:

1. **New contract**.
2. Pick the customer.
3. Pick the location. Add the location under Customers first. One active contract per location.
4. Fill in **D+n**. This may be 1 to 365. It starts at 14.
5. Fill in the start date. The first delivery is due on this day. From this date the mats will be swapped.
6. Under **Mats per zone**, add one line per zone and product: the zone, the product, and how many. **Add a line** gives another line. Only zones of this location can be picked.
7. Save. The contract is a draft and can still be changed.

Start it when the lines are right:

1. **Start contract** on the card.
2. Read the dialog. It says how many tasks are made, and what can no longer be changed.
3. Confirm. The contract becomes Active. One delivery task is created for that location, due on the start date.

A contract without a line cannot be started. After the start, the location, the mats per zone and the start date stay as they are. Only **D+n** can still change. When it changes, every open swap of this contract moves by the difference.

**4. Setup, Users**

One login per person, one role per login. The very first office user is made on the server, once. Every later user, including another office user, is made here.

| Do | How |
|---|---|
| Add a user | **Add user**: name, username, password, and role. The username is 3 to 60 letters, digits, dots, dashes or underscores, and it cannot be changed later. The password is 10 to 72 characters |
| Change a name or a role | **Edit** in the row |
| Give a new password | **Edit**, fill in **New password**. Leave it empty to keep the old one |
| Stop someone from signing in | **Edit**, switch off **Can log in**. That person is signed out at the next step |

A user is never deleted, only switched off. You cannot change your own role or switch your own login off.

### Every morning

A bar on top of every office page says what needs you today. It is only there when there is something to say.

| The bar says | Meaning |
|---|---|
| "2 tasks are late." | Open tasks whose due date has passed. The bar is red |
| "3 tasks are due today, 1 without a driver." | Open tasks for today, and how many still have no driver |
| "1 task is due tomorrow and has no driver yet." | Open tasks for tomorrow that still need a driver |

**Open the tasks** in the bar goes to Tasks. The same count stands in the title of the browser tab, for example "(3) Proti mat tracking", so you see it while another tab is in front. The bar looks for news once a minute. The app sends no email and no text message. Keep a tab open.

Then open **Dashboard**. The blocks are clickable.

| Block | What you use it for |
|---|---|
| In inventory, On a van, At customers, In washing | How many mats are in each place. A click opens that list |
| Open tasks, Not assigned yet | What still needs a driver. A click opens Tasks |
| Handover issues | A pile was closed and only one side scanned a mat. A click opens Handovers, view Issues |
| Needs attention | Late tasks first, then tasks without a driver, then handover issues. **All clear** when none of those are waiting |
| Mats by status | One bar per status. A click opens that list |

### During the day: Tasks

Operations, **Tasks**. Every row is one visit to one building. The list refreshes every 20 seconds and opens on Open tasks, oldest due date on top.

| Do | How |
|---|---|
| Give a task a driver | Pick the name in that row. The task is on that driver's handheld within seconds. Inventory and outbound see which mats that driver now needs |
| Give it to another driver | Pick the other name |
| Take it back | Pick **No driver yet** |
| Move the date | **Move**, pick the new date, save. Only this task moves |
| Cancel | **Cancel**, then confirm. The task leaves the driver's list. It cannot be opened again, and no next swap is planned from it |
| Make a collection | **New task**, kind Collection: the contract, the building, the due date. The driver only picks mats up. Only a contract that was started can be picked |
| Plan a swap by hand | **New task**, kind Swap. Same fields. Only an active contract, and only a building that has no open delivery or swap |
| Look at the proof | **Proof** on a done task: the photo, the signature, who signed, which driver, when, and the note |

A red **Late** label means the due date has passed and the task is still open. A red **Note** label means the driver wrote something at the stop. Read those notes. A missing mat stays **At the customer** until you correct it.

Cancelling a swap stops the repeat for that building. The next swap is only planned when a stop is closed. When only the date is wrong, use **Move**. To start the visits again after a cancel, plan a swap with **New task**.

There is no button to make a delivery by hand. A delivery comes from starting a contract. For a building that has no visit planned, plan a swap: at a swap the driver lays mats too.

### During the day: Mats and Handovers

Operations, **Mats**. Search by a part of the mat number or the tag id. Filter by status, by place, or by mat type. Click a row for the history. The newest line is on top.

Correct a status when the system and the floor disagree:

1. Open the mat and press **Correct status**.
2. Pick the new status.
3. For a status on a van, pick the driver. For **At the customer**, pick the customer, the building and the zone.
4. Write why. This is required.
5. Save. The history gets a line **Corrected by the office** with your name and the note.

Operations, **Handovers**. The page refreshes every 15 seconds.

| View | What it is |
|---|---|
| All handovers | One row per pile: Handover A, B, C, Return or Rewash, from whom, to whom, how many given, how many received, Open or Closed |
| Issues | A mat whose handover was closed after only one side scanned it |

| Do | How |
|---|---|
| Handle an issue | Find out where the mat really is. **Mark handled**, and write what was found |
| Put the mat right | Marking handled closes the issue. When the mat is somewhere else than the system thinks, also correct its status |
| Close a handover that was left open | Open it and press **Close handover**. Normally the receiver closes it on the handheld |

In Handover C the second scan is optional. A mat with only the washing house's scan is normal there and is no issue.

### End a contract

1. **End contract** on the card, then confirm. The contract ends today and its open swaps are cancelled. This cannot be undone.
2. Mats that still lie at the customer stay there.
3. Make a collection task for each building to get them back.

## If you are the inventory manager

You work in the storeroom. After sign-in you get a handheld menu, in the order of the day. The arrow at the top left goes back to the menu. Nothing is saved by leaving a screen: an open handover is still there when you come back.

| Screen | Used for |
|---|---|
| Register | Tag a new mat |
| Ready to use | Release new or repaired mats |
| Quality check | Pass, rewash, repair or scrap one mat |
| What drivers need | How many mats each driver still needs |
| Hand over to driver | Handover A: scan a pile out |
| Receive from washing | Handover C: the optional scan in |
| Receive returns | Take back mats a customer refused |
| Send to rewash | Send mats that failed the check |
| Mats in inventory | Everything on the shelf |
| Look up | What a mat is. Nothing changes |

A usual day, once the office has given drivers their tasks:

1. Open **What drivers need**. One card per driver who has an open delivery or swap. Per mat type it says how many are still to go and how many are already on the van. The screen refreshes by itself. A driver appears here as soon as the office assigns a task.
2. **Quality check**, one mat at a time. The mat must be **Ready to use**. Look at it. For a mat that fails, write what is wrong, then press one button.

| Button | The mat becomes | What you do next |
|---|---|---|
| Pass | Checked, may go out | Hand over to driver |
| Rewash | Needs rewash | Send to rewash |
| Repair | In repair | After the repair: Ready to use, then a new quality check |
| Scrap | Scrapped | Nothing. The mat is out of use |

3. **Hand over to driver**. Pick the driver. **Start the handover**. Scan the pile and press **Scan out**. Only mats that are **Checked, may go out** are accepted. The table shows a tick under **Given**. Nothing has moved yet.
4. The driver scans the same pile in on the driver's own handheld. The tick under **Received** appears on your screen by itself. The driver closes the handover. You cannot close Handover A.
5. A mat you scanned and the driver did not stays in inventory. After the driver closes, it is an issue for the office.

Other jobs, when they come up:

| Job | How |
|---|---|
| A new mat | **Register**. Read the two tags. Both must carry the same tag id. Fill in the mat number and the mat type. **Register mat**. The mat is **Registered** |
| Release it | **Ready to use**. Scan it and **Mark ready**. The same screen releases a mat that comes back from repair |
| Washed mats arrive | **Receive from washing**. They are already **Ready to use** from the washing house scan. Your scan is a count, and you may skip it. If you do it, open the pile, scan what arrived, and **Done, close the handover** |
| A customer refused a mat | **Receive returns**. The driver starts the return. You scan the mats in and close. They become **Ready to use** without a wash |
| A mat failed the check | **Send to rewash**. Pick the washing house manager, start, scan out. Only **Needs rewash** is accepted. The washing house scans them in |

The server refuses a tag, a mat number, or a tag number that is already in use, and it says on which mat.

## If you are a delivery driver

| Screen | Used for |
|---|---|
| My tasks | The stops: deliver, swap, collect |
| Load the van | Handover A: scan in the pile from inventory or outbound |
| Hand over to washing | Handover B: scan the used mats out |
| Return refused mats | Give refused mats back to inventory |
| On my van | Every mat you carry |
| Look up | What a mat is. Nothing changes |

A usual day: **Load the van**, then **My tasks** stop by stop, then **Hand over to washing**, and **Return refused mats** when a customer did not want one.

### Load the van

1. The pile shows up as soon as inventory or outbound starts the handover. It says from whom, and how many mats are given.
2. Open it. Scan the pile and press **Scan in**. **All expected** ticks every mat the giver scanned.
3. Each mat that both of you scanned is now **On the van, clean**.
4. A mat the giver scanned and you did not is **Missing**. A mat you scan and the giver did not is **Not on the list**. Both become issues for the office.
5. **Done, close the handover**. The receiver always closes.

### A stop

**My tasks** lists your open tasks, the soonest on top. Each row shows the kind of visit, the building, the address, the mats per zone, and a date label: the due date, **Today**, or a red **Late**. Tap the row.

| Task | Mats to lay | Mats to pick up |
|---|---|---|
| Delivery | Yes | No |
| Swap | Yes | Yes |
| Collection | No | Yes |

**Mats to lay.** Every clean mat on your van is listed. Per mat, pick **Lay at (zone)**, **Customer refused it**, or leave **Stays on the van**. When the van holds no clean mats, load first.

**Mats to pick up.** Every mat the system knows in this building, with its zone. Tick the ones you take, or **All found**.

**Proof.** **Take a photo** opens the camera. The name of the person who signs is filled in with the customer's contact person and can be changed. The customer signs in the white box. **Use a sample** gives a stand-in photo when you are only reviewing the app and have no camera. A real stop needs a real photo.

**Note.** Write one when something differs: a mat you could not find, fewer mats laid than agreed, a refused mat.

**Close the stop** saves everything at once. **Back, nothing is saved** leaves the stop as it was. The message **Stop closed** stays until you tap **Back to my tasks**.

The server checks the stop before it saves. Photo and signature are required. Every mat you lay or refuse must be **On the van, clean** with you. Every mat you pick up must lie in this building. The laid mats must match the contract, zone by zone and type by type, and every mat in the building must be picked up. When they do not, the stop waits until there is a note.

After a delivery or a swap, the next swap exists from that moment. The office assigns it. You do not pick your own next driver. After a collection, nothing further is planned for that building.

A mat you could not find stays unticked, with a note. It stays **At the customer**. The office reads the note and corrects the mat when it is really lost.

### After the round

**Hand over to washing.** Pick the washing house manager, **Start the handover**, scan the used mats out. Only **On the van, used** on your van is accepted. The washing house scans them in and closes. You cannot close this one.

**Return refused mats.** Pick the inventory manager, start, scan the refused mats out. Only **Refused, on the van** is accepted. Inventory scans them in and closes.

**On my van** shows clean, refused and used mats. **Your van is empty.** when there is none.

## If you are the washing house manager

Your screens show the mat number, the mat type, the wash date and the status. They show no customer and no address.

| Screen | Used for |
|---|---|
| Receive | Scan in what arrives: used mats from a driver, or a rewash from inventory |
| In washing | The mats in the washing house now |
| Hand over to inventory | Scan the washed mats out. This scan counts the wash |
| Look up | What a mat is. Nothing changes |

1. **Receive**. A pile shows up as soon as the driver or the inventory manager starts it. Open it, scan every mat that really arrives, **Scan in**. **All expected** ticks what the giver scanned. Each mat that both scanned is **In washing**. A mat the giver scanned that is not in the pile is **Missing**: leave it. It stays with the giver and becomes an issue for the office. **Done, close the handover**.
2. Wash the mats. **In washing** lists them. **Nothing is in washing.** when it is empty.
3. **Hand over to inventory**, when they are washed and go back, not before. Pick the inventory manager, start, scan them out. Only **In washing** is accepted. On this one scan each mat becomes **Ready to use**, its wash count goes up by one, and the wash date becomes today. **Done, close the handover**. You may close this one, because inventory's scan is optional.

Scanning a mat twice does not count two washes.

## If you are outbound

You only hand piles to drivers. You have three screens, and they work like the same screens of the inventory manager: **What drivers need**, **Hand over to driver**, and **Look up**.

You do not register mats, check them, or receive them. The inventory manager does that. The driver still scans the pile in and closes the handover.

## How a scan works today

Every screen that scans has **Pile in front of the scanner**.

| Part of the box | What it does |
|---|---|
| The list | The mats the scanner could hear. Tick the ones in the pile |
| Fits this step, All mats | The first shows only mats this step accepts. The second shows every mat, so you can see a wrong one refused |
| All that fit, All expected | Ticks every mat that fits |
| Leave one out | Ticks all but one, so you can see a missing mat |
| Add a stranger | Adds a mat that does not belong in this step |
| Unknown tag | Adds a tag no mat has |
| Clear | Unticks everything |
| The button | Sends the scan. It says how many, for example **Scan out 7 mats** |

**Last scan** shows what the server said, one line per mat: Found, Refused (with the reason), Not on the list, Unknown tag, or Missing. In a handover, a table shows **Given** and **Received**, and a line such as "7 of 7 moved". The other person's scans appear by themselves within a few seconds.

On the driver's stop the mats are not scanned in this simulation. The driver picks a zone per mat and ticks the mats that are picked up.

## A day when everyone works together

This is one building, from an empty contract to a washed mat. Each person only does the steps of their own role.

| When | Who | What they do in the app |
|---|---|---|
| Once | Office | Mat type, area, customer, building, zone, contract. **Start contract** |
| That morning | Office | Tasks: give the delivery to a driver |
| Storeroom | Inventory | Quality check: Pass. Hand over to driver: scan out |
| Van | Driver | Load the van: scan in, close the handover |
| At the customer | Driver | Lay each mat in its zone, photo, signature, close the stop |
| Same minute | The app | A swap appears, due after the contract's number of days, with no driver |
| Office | Office | The bar and the Dashboard say a task has no driver. Assign the swap |
| Swap day | Inventory, then driver | Check and load the clean mats. At the stop, lay the new ones and tick the used ones. Photo, signature, close |
| Back at the laundry | Driver, then washing | Driver scans the used mats out. Washing scans them in and closes |
| After the wash | Washing | Hand over to inventory: scan out, close. The wash count goes up by one |
| Office, any time | Office | Mats: open one mat and read the history. Tasks, Done, **Proof**: the photo and the signature |

If the customer refuses a mat, the driver marks **Customer refused it**, writes a note because the stop no longer matches the contract, and later uses **Return refused mats**. Inventory receives it. It does not go through the wash.

## When something looks wrong

| What you see | Who acts | What they do |
|---|---|---|
| A mat was scanned by one side only | Office | Handovers, Issues. Find the mat, mark the issue handled, correct the status if the place is wrong |
| The scanner refuses one mat | The person scanning | Read the reason under that line. The other mats of the scan still go through |
| A handover was left open | The receiver, or the office | Pick it up again on the handheld, or the office closes it under Handovers |
| The stop will not close | Driver | Add the photo, the signature, or the note the message asks for |
| A task is late | Office | Move the date, or give it to another driver. It stays open until someone closes it or you cancel it |
| A driver is ill | Office | Give that driver's open tasks to another driver, one by one |
| The floor and the screen disagree | Office | Correct the mat's status, with a note |
| Someone leaves | Office | Switch **Can log in** off. A task can only be given to an active driver |

## Showing the app to a colleague

Sign in as office and open **Workflow** first. It tells the loop with nothing to click, and **Open the dashboard** goes to the numbers.

Then open **Handheld**. You see the inventory manager, a driver and the washing house manager at the same time. **Outbound as a fourth** adds outbound. **One at a time** shows a single handheld and keeps the others where they were. Each device has its own sign-in, so a pile handed over on one shows up on the next. A device only accepts a user of its own role.

The test users and the click-by-click test with the demo mats are in [Part 2 of the walkthrough](walkthrough.md#part-2-the-end-to-end-test). How to start the app on a computer is in [Run it locally](../README.md#run-it-locally).

## What this version leaves to people

| Not in the app | What you do |
|---|---|
| No email or text when a swap is due or late | Keep the app open. The bar and the tab title say what is late and what is due today |
| A new swap gets no driver by itself | The office assigns every task |
| No route and no week calendar | The driver's list is sorted by due date. On Tasks, filter the type to see the swaps in date order |
| A started contract cannot get other mats or zones | End it, collect the mats, and start a new contract |
| Every office user sees every customer | There is one laundry in this version |
| The real C72 scanner is not connected | Scans are the pile box described above |

The same list, with the rest of the first-version choices, is in [walkthrough.md](walkthrough.md#what-the-app-does-not-do-yet).
