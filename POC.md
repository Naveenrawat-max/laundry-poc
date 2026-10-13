# Laundry RFID POC

Version: 0.3.1

Proti rents door mats to its customers. Proti owns the mats, delivers and collects them itself, and washes them itself. One mat carries two RFID tags with the same EPC. The Chainway C72 only returns tag ids. The apps store everything else: SKU, status, customer location, washing dates, and washing cycle count.

The flow is drawn in [FLOW.md](FLOW.md). The full feature list is in [FEATURES.md](FEATURES.md). The system design and database are in [ARCHITECTURE.md](ARCHITECTURE.md).

## Apps

- **Mobile (Kotlin, Chainway C72):** one login per user. The role decides the screens: inventory manager, delivery guy, washing house manager, and outbound (optional).
- **Web (PHP backend + React, hosted at TransIP):** office staff manage customers, contracts, mats, tasks, and users.

## Flow

1. The office adds a customer, its buildings, the floors or zones, and the contract: mat type, quantity, and days between swaps (n).
2. The inventory manager registers each mat, marks it ready to use, and runs the quality check.
3. **Handover A:** inventory scans the mats out, the delivery guy scans them in.
4. The delivery guy delivers: scan, photo, signature. That date is D.
5. At D+n the system creates a swap task. The delivery guy drops a clean mat and takes the used one: scan, photo, signature.
6. **Handover B:** the delivery guy scans the used mats out, the washing house manager scans them in.
7. **Handover C:** after washing, the washing house manager scans the washed mats out. This scan is required: it confirms the mats are ready to use and counts one washing cycle. Inventory scans them in; that second scan is optional.

A mat moves at a handover only when both sides have scanned it. A mat scanned by one side stays with the giver and is flagged for the office.

## Features

### 1. Register

Scan the two tags of one mat. Both carry the same EPC. One TID is saved as primary, the other as secondary. Add the SKU or unique ID supplied by Proti.

### 2. Pile scan

The C72 reads a stack, not one mat. The screen lists every mat it heard and compares it with the expected list: found, missing, or not on the list.

### 3. Delivery proof

Every delivery, swap, and collection at a customer is closed with a scan, a photo, and a signature.

### 4. Swap tasks

The contract sets the days between swaps per customer. The system creates the swap task at D+n and the office assigns it to a delivery guy.

### 5. Washing count

The washing house manager's scan after washing, in Handover C, moves the current washing date to the previous washing date, sets the current washing date to today, and adds 1 to the cycle count. Scanning the same handover again adds nothing.

### 6. Mat list

The web app lists every mat: status, current holder or location, both tags, SKU, cycle count, and washing dates. Open a row to see every scan of that mat.

## Not in this POC

- Registration at the mat factory in Belgium.
- Workwear.
- Links to other software. Proti has none.
- A login for Proti's customers.
