# Feature list

Version: 0.2.1

RFID mat tracking for Proti. Proti rents door mats to its customers, delivers and collects them with its own drivers, and washes them in its own washing house. The flow these features follow is drawn in [FLOW.md](FLOW.md).

## What the client told us

| Topic | Answer | Effect on the build |
|---|---|---|
| Logistics | Done by Proti itself | Delivery guy is a Proti user with his own login |
| Washing | Done by Proti itself | Washing house manager is a Proti user with his own login |
| Change frequency | Fixed per customer in the contract, on average every 2 to 4 weeks | Number of days is set per customer and drives the swap tasks |
| Existing software | None, no ERP | Standalone system. It holds the customer data itself, no integration |
| Number of mats | 40 to 50 now, 4,000 to 5,000 in a few years | Bulk scanning and searchable lists from day one |
| Number of users | Unknown, the company is growing | Users are added by the office, no fixed limit |
| Registration at the factory | Wanted: mats come from a supplier in Belgium | Registration must also work away from Proti's own inventory (see Later) |
| Workwear | Planned as a second service | Item type must not be hard-wired to "mat" (see Later) |

## Platform

| Part | Used by | Built with |
|---|---|---|
| Mobile app | Inventory manager, delivery guy, washing house manager, outbound (optional) | Android app for the Chainway C72 handheld |
| Web app | Office staff | Web app, PHP backend, hosted at TransIP |

## Mobile app

Every user logs in on the C72. The role of the user decides which screens open and what a scan is allowed to change.

### Who can do what

| Action | Inventory manager | Delivery guy | Washing house manager | Outbound (optional) |
|---|:-:|:-:|:-:|:-:|
| Register a mat | ✓ | | | |
| Mark ready to use, quality check | ✓ | | | |
| Hand over to delivery guy (scan out) | ✓ | | | ✓ |
| Receive from inventory (scan in) | | ✓ | | |
| Deliver, swap or collect at the customer | | ✓ | | |
| Hand over to washing (scan out) | | ✓ | | |
| Receive in washing (scan in) | | | ✓ | |
| Hand over to inventory (scan out) | | | ✓ | |
| Receive from washing (scan in) | ✓ | | | |
| Look up a mat by scanning it | ✓ | ✓ | ✓ | ✓ |

### For every role

- Login with own username and password.
- Scan a pile at once and see every mat that was read.
- Scan against an expected list: each mat shows as found, missing or not on the list.
- A handover is complete only when both sides have scanned. A mat scanned by one side stays with the giver and is flagged for the office.

### Inventory manager

- **Register:** link two tags to one mat. Both tags carry the same EPC; one TID is saved as primary, the other as secondary. Add the SKU or unique ID supplied by Proti.
- **Ready to use:** mark registered or returned mats as ready.
- **Quality check:** pass or fail. A failed mat goes to rewash, repair or scrap.
- **Outbound:** prepare the mats for a delivery task and scan them out to the delivery guy.
- **Return from washing:** scan in washed mats. This second scan is optional.

### Delivery guy

- **Task list:** deliveries, swaps and collections assigned to him, with customer, building, floor or zone, and the mats expected.
- **Load:** scan in the mats received from inventory.
- **Deliver:** scan the mats at the customer, take a photo as proof, capture a signature. The delivery date starts the count to the next swap.
- **Swap or collect:** scan the mats he drops and the mats he takes, photo and signature each time.
- **Refused or undelivered:** mark the mat so it returns to inventory without a wash.
- **Hand over to washing:** scan out the collected mats at the washing house.

### Washing house manager

- **Receive:** scan in the mats the delivery guy hands over. This scan moves the old "current washing date" to "previous washing date", sets the current washing date to today, and adds 1 to the washing cycle count. Scanning the same handover again adds nothing.
- **In washing:** list of mats currently in the washing house.
- **Hand over to inventory:** scan out the washed mats.

### Outbound (optional)

- Only scans mats out to the delivery guy. Used when outbound is a separate person; otherwise the inventory manager does this.

## Web app

| Area | Features |
|---|---|
| Customers | Add a customer, its buildings, the floors or zones inside each building, and the area each building lies in |
| Contracts | Per customer: mat type, quantity per location, and the number of days between swaps |
| Mats | List of all mats with status, current holder or location, both tags, SKU, washing cycle count, previous and current washing date |
| Mat history | Every scan of a mat: who, when, where, and what changed |
| Tasks | Delivery task for a new contract, swap task created automatically when the number of days is reached, assignment to a delivery guy, proof photo and signature per stop |
| Handover issues | Mats flagged at a handover, with the option to correct the status. Every correction is logged |
| Users | Add users and give each one a role |

## Later

Not in the first build, but the client raised them, so the design must not block them.

- **Registration at the factory:** the supplier in Belgium tags and registers each mat before shipping, using a registration station connected to this system.
- **Workwear:** the same flow for rented work clothing, managed in the same platform.

## Still open

Asked, but not answered in the call.

- How the current process runs end to end.
- Which service level agreements Proti has with its customers.
- How a customer asks for an extra change or wash outside the contract.
- How a new customer is onboarded.

The defaults assumed in the flow (swap in one visit, mats pooled per type, one inventory and one washing location) are listed in [FLOW.md](FLOW.md) and also still need the client's confirmation.
