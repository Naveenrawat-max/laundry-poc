---
title: "001: Office orders reserve exact mats"
tags:
  - kind/decision
  - area/orders
  - status/current
---

# Office orders reserve exact mats

The office workflow in [[docs/office-orders]] needs an order number, stock selection, inventory QC, assigned-driver scans, customer proof and washing receipt. Existing contract tasks have separate behavior and must remain usable. The contract is owned by [[_code/site/database/migrations/2026_10_15_000001_create_office_orders-php]] and [[_code/site/app/Http/Controllers/OrderController-php]].

Options considered were extending the legacy task lifecycle or adding standalone office orders alongside it. The selected contract adds `orders` and `order_items`, preserving legacy task and item enums, unique `items.sku`, RFID tag uniqueness and existing customer/holder constraints. `orders` holds customer/building/driver, destination, washing recipient, lifecycle and proof/acceptance. `order_items` holds exact mat/zone assignments, QC, scans, receipt and replacement history.

An active line uniquely reserves its mat with `reserved_item_id = item_id`. Replacement, completion or cancellation releases that reservation. Current lines have `replaced_at IS NULL`. Consumers must preserve those reservation rules, assigned-driver checks, recipient ownership and once-only wash increments. Driver proof remains unavailable until every active line has a persisted `scanned_at`; washing responses omit customer/location/signatory/free-text fields. [[_code/site/resources/js/handheld/orders-tsx]] consumes that contract without changing its ownership.

The fixed `sku_catalog` supplies ten demo SKUs per active mat type. Nullable `items.catalog_sku_id` selects a catalog entry while preserving the existing unique physical mat identity; see [[_code/site/database/seeders/SkuCatalogSeeder-php]]. Registration remains office-only. Choosing a scanned common EPC updates demo records; no physical writer is introduced.

Consequences: existing installations must migrate before using orders, legacy tasks and office orders remain distinct, and replacements require another QC pass. The backend must enforce exact scans, reservations and proof even if a client bypasses the interface. These constraints bind [[_code/site/resources/js/pages/orders-tsx]] and [[_code/site/resources/js/pages/register-tsx]].
