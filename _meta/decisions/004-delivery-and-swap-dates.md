---
title: "Separate delivery deadlines from mat swap dates"
tags:
  - kind/decision
  - area/orders
  - status/current
---

# Separate delivery deadlines from mat swap dates

Context: the driver's delivery deadline and a customer's replacement interval serve different purposes. The office needs a fixed date beside each mat identity when choosing a pickup, including mats delivered on different days. The practical workflow is in [[docs/delivery-swap-dates]].

Options considered were reusing the order deadline or contract visit date, deriving dates from historical audit timestamps, exposing editable per-mat dates, and recording accepted placement dates explicitly. Reused dates do not identify each placement; audit time cannot reliably recover simulated business dates or past contract intervals; editable dates contradict the requested fixed display.

Choice: keep `orders.due_date` as the driver deadline, defaulted to shared business today. Record nullable `items.delivered_on`, `items.swap_due_date` and `items.delivered_contract_id` for accepted placements only. Swap due is D plus the associated contract interval. Contract interval changes recalculate known dates for currently placed mats. Unknown legacy dates remain NULL. The frontend treats per-mat dates as outputs only.

The shared simulator clock changes business calendar calculations only and requires authenticated office authorization, enabled simulator configuration, a non-production environment and the applied migration for mutation. Advancing the clock makes scheduled work due; normal accepted completion creates successors. Reset clears the override without undoing workflow changes. Real UTC audit time, authentication, proof validation, mat identities, stored return reason `used`, and existing assignment checks remain unchanged.

The later [[_meta/decisions/006-safe-legacy-mat-date-recovery]] narrowly supersedes the blanket legacy-unknown policy: wholly missing snapshots with guarded accepted-delivery evidence may be recovered. Missing evidence remains unknown; existing snapshots and explicit simulated business dates remain authoritative.

Consequences: current placement snapshots cannot describe old orders after redelivery. API responses therefore expose dates only for active, unreceived pickup lines, and never to washing users. Existing [[_code/site/app/Http/Controllers/OrderController-php]] and [[_code/site/app/Http/Controllers/TaskController-php]] notes identify completion areas; [[_code/site/resources/js/pages/orders-tsx]] and [[_code/site/resources/js/pages/tasks-tsx]] identify office consumers. These companion notes remain stale until an existing vaultify helper is restored and sync is authorized. The additive migration must precede new SQL access. No migration, build or runtime verification was executed during this decision's implementation.
