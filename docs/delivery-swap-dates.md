---
title: "Delivery deadlines and fixed mat swap dates"
tags:
  - kind/doc
  - area/orders
  - status/current
---

# Delivery deadlines and fixed mat swap dates

The [[docs/office-orders]] workflow now separates the driver's delivery deadline from each mat's replacement date. Source changes are written, the date migration is applied to the configured local application database, and frontend assets are built. Runtime behavior remains unverified. The contract choice is recorded in [[_meta/decisions/004-delivery-and-swap-dates]].

Delivery orders default to the shared application business date, today, instead of a future contract visit. Office staff can still explicitly schedule the driver's deadline. Changing customer or location resets that default to current business today.

When a delivery is accepted, its business day is D. Each accepted mat receives a fixed swap date of D plus its customer's contract interval, n days. Different delivery days can produce different swap dates. Refused mats receive no new schedule; a wholly refused delivery creates no successor task. Existing completion creates successor tasks after accepted delivery. Editing a contract interval recalculates known dates for its currently placed mats. No per-mat date editing is available.

In Pickup orders, the office manager sees the fixed swap date beside the mat number to choose which mat needs a manual swap. Active pickup checklists show the same date until that mat is received. Legacy dates with guarded accepted-delivery evidence can be recovered through [[docs/existing-mat-dates]]; remaining unknown dates show **Swap date unavailable** rather than being guessed. Completed or cancelled orders and received pickup lines do not expose the current placement dates, which could belong to a later delivery. Washing views never expose these date fields.

Pickup return reason **Dirty** keeps the stored code `used` and existing washing route. Expanded tasks show customer and location details, with linked order and mat details where available. Driver pickup forms say **Customer name**; delivery forms retain recipient wording.

For an authorized office user in a non-production environment with the existing simulator enabled, **Skip to next due date** beside **Sign out** advances the shared business date to the next strictly future due date. The screen shows the effective date and provides reset. Skip makes existing scheduled work due; it does not complete deliveries or fabricate successor tasks. Reset returns to real Netherlands calendar today; it does not undo orders, deliveries or mat dates recorded while simulation was advanced. Real audit timestamps and authentication/session clocks remain unchanged. Other sessions use the same business date.

## Activation status

The Pickup orders error `Unknown column 'i.delivered_on'` identified the missing date schema. The exact migration was subsequently applied from `site/`:

```sh
php artisan migrate --path=database/migrations/2026_10_16_000003_add_mat_delivery_dates_and_demo_clock.php --no-interaction
```

The migration command completed with `250.77ms DONE`; no other migrations or business-record deletions were performed. This supersedes the earlier unapplied-migration status in the source handoffs. No post-migration database, HTTP or browser verification was run. Frontend assets were subsequently built from `site/` using:

```sh
npm.cmd run build
```

The build exited successfully and generated `site/public/build/manifest.json`, CSS, JavaScript and font assets. Vite reported its standard chunk-size warning; bundle splitting was outside scope. This supersedes earlier pending-build status. Tests were skipped at human request. No lint, typecheck, browser/HTTP/reload checks, assertions or runtime behavior verification were run.

## Provider contracts and handoff

The database contract defines nullable `delivered_on`, `swap_due_date`, the placement's contract link and the singleton shared clock. The API publishes authenticated clock reads and office-only simulator mutation, office task detail, and date redaction for historical/received pickups and washing users. The frontend consumes those outputs without accepting per-mat date inputs.

The [[_code/00 Code map]] remains stale. A targeted search of existing helper filenames, README and known helper paths found no `vaultify.py`; no replacement helper was created, no auto-marker contents were edited, and sync/open scripts were not run. Existing code notes can show affected areas, but do not certify current dependency wiring. This change does not establish release readiness.
