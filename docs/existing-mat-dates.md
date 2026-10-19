---
title: "Existing mat dates and compact office Mats list"
tags:
  - kind/doc
  - area/mats
  - status/current
---

# Existing mat dates and compact office Mats list

The office Mats screen extends [[docs/delivery-swap-dates]] with read-only delivery and swap dates for existing mats. Its five list columns are Mat, Status, Location, Delivery / swap and Washing. Rows show human mat type, size and colour; open the Mat ID button for complete SKU identifiers, EPC/TID tags, dates, corrections and history. Search, filters, pagination and keyboard access remain available.

Dates describe the current placement only while a mat is `at_customer`; labels are **Delivered / Swap due**, and overdue styling applies only then. Elsewhere labels are **Last delivery / Last swap due**, describing its last placement rather than an active swap obligation. Missing dates remain unknown rather than editable. Office `/items` and `/items/{id}` return nullable ISO `delivered_on` and `swap_due_date`; scanner, inventory and washing projections remain unchanged.

The recovery uses the latest accepted delivery's real UTC event time converted to `Europe/Amsterdam` for D, plus the currently linked contract interval n. It requires a completed linked task/contract, all three placement fields missing, no later customer-placement correction and no current placement-zone mismatch. Existing or partial snapshots are preserved. Historical simulator business dates and old contract intervals cannot be reconstructed from audit timestamps; current linked n is an explicit recovery limit.

The reviewed recovery migration is `site/database/migrations/2026_10_16_000004_recover_legacy_mat_delivery_dates.php`. It locks eligible rows, writes private JSON before-images under `site/storage/app/private/date-recovery/` before changing dates, and preserves `updated_at`. A failed backup aborts before changes; database failure rolls back the repair. There is no reset, reseed, deletion, or change outside the missing placement-date snapshots. Its `down()` deliberately does nothing; any restoration requires reviewing private before-images against later genuine activity.

The [[_meta/decisions/006-safe-legacy-mat-date-recovery]] records this choice and the provider contracts.

The recovery migration completed successfully. Private before-images remain under `site/storage/app/private/date-recovery/`. The following commands completed from `site/`:

```sh
php artisan migrate --path=database/migrations/2026_10_16_000004_recover_legacy_mat_delivery_dates.php --no-interaction
npm.cmd run build
```

Frontend assets were generated successfully. The build reported its existing JavaScript chunk-size warning and a Tailwind plugin timing advisory; bundle optimization was outside scope. Tests remain skipped at human request. No lint, typecheck, browser/HTTP checks, assertions or runtime behavior verification were run.

The existing [[_code/00 Code map]] remains stale because the vaultify helper was unavailable during this change. No generated map contents were hand-edited.
