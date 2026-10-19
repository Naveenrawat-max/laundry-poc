---
title: "003: Separate untagged SKU stock from mapped mats"
tags:
  - kind/decision
  - area/inventory
  - status/current
---

# Separate untagged SKU stock from mapped mats

The workflow in [[docs/sku-inventory]] needs categories, size/colour variants and numeric receipts without treating ten untagged mats as ten ready RFID assets. The existing two-tag mat identity and exact-mat order flow in [[docs/office-orders]] must remain intact.

## Options considered

Options were to create an individual ready mat for every received unit, or keep untagged quantity on the SKU and create individual mats only when mapped. A recursive category tree would allow more levels than requested; a fixed root category plus existing mat type represents the approved Indoor/Outdoor → Rubber mat structure. A stock receipt ledger was outside the agreed minimal scope.

## Choice

The database contract fixes `categories` as root and `item_types.category_id` as the second level. `sku_catalog` holds dimensions in centimetres, colour and unsigned `untagged_quantity`, with unique complete variants per mat type. The API fixes server-generated `MAT-` plus ULID SKU codes and additive stock receipts.

`POST /items` consumes one selected active SKU unit, creates exactly one ready two-tag physical mat and its event, and decrements stock within one transaction. Common EPC always derives from the first scanned tag. SKU row locks and a database-scoped MySQL/MariaDB named lock serialize registration and protect cross-column TID checks. The frontend requires explicit SKU selection before scanning, persistent selection between mappings, numeric quantities and the **Map** action. Existing tag storage and individual asset identity remain unchanged.

Current item status/holder state supplies stock movement counts. Ready excludes reservations; reserved overlaps location counts. Active ownership is untagged plus tagged minus scrapped. Structured pickup reasons route used returns to washing, unused clean returns to ready inventory and damaged returns to repair inventory. Legacy contract collections remain used returns.

## Consequences

Receiving stock cannot manufacture ready mats, and delivering a mat cannot consume untagged quantity. Stock receipts remain non-idempotent and have no receipt ledger; uncertain retries require reconciliation. Named locks assume MySQL/MariaDB with one primary server/connection model. Database transactions cannot undo physical RFID writes; the current simulator does not rewrite physical tags.

The approved business reset preserves users, tokens, sessions, migration/framework tables and private proof files, with a retained external recovery snapshot. Legacy nullable metadata is tolerated by migration, while the API requires complete active metadata for new stock and mapping. No extra category depth or changes to two-tag EPC/TID invariants are permitted downstream.

Tests remain deferred at the user's request. Security review covered source only. This decision records implemented contracts, not a fully verified release.
