---
title: "laundry-poc"
tags:
  - kind/map
  - role/entrypoint
  - status/current
---

# laundry-poc

Start with [[docs/sku-inventory]] for categories, SKU stock and individual mat mapping, then [[docs/office-orders]] for the office-to-handheld workflow. Existing project setup remains in [[README]].

See [[docs/delivery-swap-dates]] for driver deadlines, fixed per-mat swap dates and the shared demo clock, including deployment status.

See [[docs/washing-driver-visits]] for both washing handover entry points, inventory receipt and combined driver delivery/pickup visits.

See [[docs/existing-mat-dates]] for safe legacy date recovery and the compact office Mats list.

## Maps

- [[_code/00 Code map]] — selected source files and the import parser's documented limits
- [[_meta/Conventions]] — how notes in this vault are written

## Decisions

- [[_meta/decisions/001-exact-mat-office-orders]] — exact mat reservations, QC, scans and proof
- [[_meta/decisions/002-batch-order-scans]] — driver pile scans with persisted exact-mat checks
- [[_meta/decisions/003-sku-stock-and-tag-mapping]] — untagged SKU stock, one-mat mapping and return routing
- [[_meta/decisions/004-delivery-and-swap-dates]] — accepted placement dates and shared business-clock boundaries
- [[_meta/decisions/005-shared-washing-handovers]] — shared washing movement and visible inventory receipt
- [[_meta/decisions/006-safe-legacy-mat-date-recovery]] — guarded accepted-delivery recovery and last-placement dates

Existing deployment instructions are in [[docs/deploy]]. Apply the new Laravel migration before using office orders on an existing installation; see [[docs/office-orders]].
