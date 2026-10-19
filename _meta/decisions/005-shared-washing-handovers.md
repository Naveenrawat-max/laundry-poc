---
title: "Share washing handovers across entry points"
tags:
  - kind/decision
  - area/handovers
  - status/current
---

# Share washing handovers across entry points

Context: order washing released mats directly without creating an inventory-visible handover, while the generic handover rejected the same reserved mats. [[docs/washing-driver-visits]] describes the resulting workflow.

Options were separate order/menu movements, a schema change to make every washing handover require two scans, or reuse of existing give/move logic with a narrowly authorized washing reservation release. Separate movements preserve divergent state transitions. Making every historical handover two-sided would reinterpret earlier optional receipts.

Choice: order washing requires an active inventory receiver and creates or reuses a normal washing-to-inventory handover. Both entry points share the same movement, wash count and exact pickup reservation release. Keep washing scan-out as the one-sided ready/wash transition. Inventory can acknowledge a closed handover only for its previously given mats that remain ready, unreserved and associated with that latest handover event.

Consequences: `/orders/{id}/wash` callers must supply `receiver_id`; response adds nullable `handover_id`. Handover APIs add `pending_receipt` and per-item `receivable`. Existing historical issue classification, delivery/refusal links, assignment checks, washing privacy, scanner source identifiers and proof requirements remain fixed. No new schema is needed. Existing [[_code/site/app/Http/Controllers/HandoverController-php]] and [[_code/site/app/Http/Controllers/OrderController-php]] notes identify implementation areas but remain stale until vault sync is available. Testing is deferred until the human asks.
