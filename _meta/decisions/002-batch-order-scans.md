---
title: "002: Driver pile scans preserve exact mat checks"
tags:
  - kind/decision
  - area/orders
  - status/current
---

# Driver pile scans preserve exact mat checks

The delivery and pickup workflow in [[docs/office-orders]] must accept a pile of RFID reads while preserving the exact-mat contract in [[_meta/decisions/001-exact-mat-office-orders]]. A pile can contain duplicate reads, already-scanned mats and stray tags; those reads must not check unrelated order lines.

Options considered were sending one request for each EPC or submitting the pile in one request. The selected contract uses `POST /orders/{id}/scan` with `{source, reads: [{epc}, ...]}` and returns `{order, rejected: [{epc, reason}]}`. [[_code/site/app/Http/Controllers/OrderController-php]] normalizes and deduplicates 1–5,000 reads, marks only exact current-order mats and reports stray EPCs as rejected. Partial matches persist, and retries preserve scanned progress. A matched mat at the wrong holder or location produces an atomic `409` rollback. Legacy `{source, epc}` requests retain their existing behavior and errors.

[[_code/site/resources/js/handheld/orders-tsx]] uses the existing [[_code/site/resources/js/handheld/pile-tsx]] panel for driver delivery and pickup, sends one request with the pile, and reports rejected reads. Checkmarks are derived from persisted `scanned_at` values. Inventory QC keeps its single-mat scanner. [[_code/site/tests/Feature/OrdersTest-php]] covers the batch contract.

Consequences: assignment, ownership, reservations, stop location, QC, dispatch and the all-lines-scanned proof gate remain unchanged. Pile reads increase scan throughput without permitting an unlisted mat to satisfy a delivery or pickup. Both a JPEG photo and a PNG signature plus the JSON payload remain required for completion.
