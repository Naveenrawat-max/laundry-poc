---
title: "Recover legacy mat dates only from accepted delivery evidence"
tags:
  - kind/decision
  - area/mats
  - status/current
---

# Recover legacy mat dates only from accepted delivery evidence

Context: [[docs/existing-mat-dates]] needs delivery and swap dates for existing mats, while [[_meta/decisions/004-delivery-and-swap-dates]] originally left legacy snapshots unknown. Some missing snapshots have accepted delivery evidence; others do not.

Options were leaving every missing snapshot unknown, copying planned/successor task dates, or recovering only guarded accepted placements. Planned dates can be rescheduled and do not prove delivery. Unconditional recovery risks overwriting newer placement facts.

Choice: the database contract permits recovery only when all placement fields are NULL, using the latest accepted `deliver` event linked to a completed task/contract. Reject later customer-placement corrections and mismatching current placement zones. Convert legacy UTC event time to Netherlands calendar D, then add the current linked contract interval n. Preserve existing and partial snapshots; mats without delivery evidence stay unknown. Outside `at_customer`, retained dates mean last placement only.

Consequences: historical simulator D and past contract intervals remain unavailable. Current linked n is a disclosed limit, not proof of an old interval. Lock eligible rows and save private before-images before changes; failed backup aborts and database failure rolls back. Preserve identities, tags, statuses, holders, zones, wash counts, audit events, `updated_at`, existing date facts, clock and order/task state. `down()` is a no-op to avoid erasing recovered history after later activity.

The API restricts date outputs to office mat projections; the frontend consumes them as read-only dates in a compact list and drawer. Existing [[_code/site/app/Http/Controllers/ItemController-php]] and [[_code/site/resources/js/pages/mats-tsx]] notes identify affected areas, but the map remains stale until vault sync is available. Tests remain skipped at human request; source approval does not prove runtime behavior.
