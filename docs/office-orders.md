---
title: "Office orders and exact mat handoffs"
tags:
  - kind/doc
  - area/orders
  - status/current
---

# Office orders and exact mat handoffs

The workflow linked from [[00 Index]] follows [[_meta/decisions/001-exact-mat-office-orders]]. Start with [[docs/sku-inventory]] for current category, stock, registration and return-reason rules. Office staff register mats and create orders; handheld users perform assigned QC, delivery, pickup and washing work. Legacy contract tasks remain separate.

Current washing-to-inventory entry points and combined driver visits are described in [[docs/washing-driver-visits]]. In particular, `/orders/{id}/wash` now requires an inventory `receiver_id` and returns a nullable `handover_id` alongside `order`; inventory can acknowledge that handover from Receive from washing.

1. In office setup, create a category, subcategory and size/colour SKU with numeric untagged stock. In registration, select the subcategory and SKU before scanning both tag EPC/TID pairs, then choose **Map**. The backend uses the first tag's EPC as common EPC and atomically consumes one untagged unit for one immediately ready mat. The selected SKU remains selected until changed; changing it clears pending scans. The simulator does not physically rewrite RFID tags. Catalog SKU and each mat's unique identity remain separate; see [[docs/sku-inventory]] and [[_code/site/resources/js/pages/register-tsx]].
2. In office Ready to deliver / Delivery orders, choose customer, building, exact inventory mats and driver. Creating the order reserves those exact mats and produces its order number. Registration and Ready to deliver are office screens, removed from the inventory handheld menus. Inventory staff then QC each reserved line in [[_code/site/resources/js/handheld/orders-tsx]]. A failed mat must be replaced before dispatch; its replacement must pass QC.
3. The assigned driver sees the order number and product checklist. Delivery and pickup use the pile scanner: one submission can scan multiple mats, but only exact current-order mats get checked. Stray tags are reported and ignored; partial scans persist, and retries preserve existing checks. Checkmarks come from the server's persisted `scanned_at` values. Proof becomes available only after every active line has a persisted scan. Completion requires both a JPEG photo and a PNG signature plus a JSON payload recording acceptance or refusal; the backend enforces this gate as well as the screen. See [[_meta/decisions/002-batch-order-scans]] and [[_code/site/app/Http/Controllers/OrderController-php]].
4. In office Pickup orders, choose a customer and select only mats currently delivered to that customer. Choose the driver and structured return reason: used routes to washing, unused clean to ready inventory, damaged to repair inventory. The note is optional; a washing recipient is required for used returns. The driver follows the same exact-mat scan and proof process.
5. For a washing destination, the assigned washing recipient sees incoming work and its driver, receives the selected mats, confirms washing, then returns them to inventory. Washing views omit customer, building, reason, signatory and proof details. Wash increments occur once on the confirmed return. See [[_code/site/resources/js/pages/handheld-tsx]].

The API contract uses paths relative to the existing API root. Office-only `GET /sku-catalog` optionally filters by mat type; office registration uses `POST /items` with `catalog_sku_id`, `source` and two scanned EPC/TID pairs. Common EPC derives server-side from the first tag. `POST /items/ready` remains for legacy registered or repaired mats; newly mapped mats are already ready. Role-scoped `GET /orders`, order detail and `/available-items` support selection and assigned work. `POST /orders` creates exact-mat orders with customer/building/zone, driver and due date; pickups require `return_reason`, a matching destination and a washing recipient only for used returns, with an optional `reason` note. Order mutations `/qc`, `/replace`, `/dispatch`, `/complete`, `/receive` and `/wash` return `{order}`. `/complete` requires a multipart JPEG photo, PNG signature and JSON payload. Washing responses redact customer/location/signatory/free-text fields.

`POST /orders/{id}/scan` accepts `{source, reads: [{epc}, ...]}` and returns `{order, rejected: [{epc, reason}]}`. The backend normalizes and deduplicates 1–5,000 reads, persists only exact current-order matches, and reports stray EPCs without checking them. A matched mat at the wrong holder or location causes an atomic `409` rollback. The existing single-EPC `{source, epc}` request and its error behavior remain compatible. Inventory QC retains its single-mat scanner.

Existing installations require the Laravel migration in [[_code/site/database/migrations/2026_10_15_000001_create_office_orders-php]]. Updating `db/schema.sql` alone does not create the new order tables. From `site/`, apply the reviewed migration during deployment:

```sh
php artisan migrate --force
```

The migration seeds the fixed catalog for existing active mat types. Deploy the rebuilt frontend assets with the application. Do not run the full demo/database seeder against an existing installation: it is not required for this upgrade. This task did not run production migrations or deploy the application. Existing deployment context remains in [[docs/deploy]].

Historical validation before the SKU changes: the local API gate reported 159 passing tests and 1,665 assertions, including the batch-scan contract; its source security review passed. These results do not validate the current SKU implementation. New tests remain unrun at the user's request; release readiness remains unverified. Standard `php artisan test`, run from `site/`, requires a dedicated `.env.testing` database and permission to reset it. Never run destructive tests against production data, and do not execute these commands while the no-tests instruction remains active. See [[_code/site/tests/Feature/OrdersTest-php]] for order coverage.
