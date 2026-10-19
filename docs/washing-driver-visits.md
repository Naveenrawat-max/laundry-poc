---
title: "Washing handovers and combined driver visits"
tags:
  - kind/doc
  - area/handovers
  - status/current
---

# Washing handovers and combined driver visits

The [[docs/office-orders]] workflow now uses the same washing handover from both **Incoming deliveries** and **Hand over to inventory**. For an incoming order, receive its exact mats, choose an active inventory receiver, then scan the washed mats out. The returned handover opens on the washing handheld. The standalone menu can hand over those same received, reserved pickup mats; only their assigned washing user can release them.

The selected inventory user opens **Receive from washing** to acknowledge the mats. Washing scan-out makes a mat ready and counts its wash once, as before. Inventory receipt records acknowledgement without another wash. Closing the washing handover does not hide a receipt still pending for that inventory user. Mats that have since moved elsewhere or entered another order are not receivable through the old handover; old rows remain visible as history. This choice is recorded in [[_meta/decisions/005-shared-washing-handovers]].

On the driver handheld, a delivery and pickup with the same customer, location, assigned driver and due date appear as one visit. Open that visit to work on both orders. Each retains its own number, exact mat checklist, scans, signature and completion. Completing one does not discard the other order's in-progress proof. Different locations or dates remain separate visits.

Office pickup choices and active driver pickup checklists show each mat's actual **Delivered** date beside its fixed **Swap due** date. Missing legacy dates remain unavailable; dates are not guessed or made editable. Historical and received pickup lines retain the privacy limits from [[docs/delivery-swap-dates]]. The contract zone selector now says **Mat type**, and the handheld frame omits the C72 label while hardware protocol identifiers remain supported.

## API and activation

`POST /api/v1/orders/{id}/wash` now requires `receiver_id` along with existing `source` and `reads`. It returns `{order, handover_id}`, with a null handover id on an already-completed retry. Handover list/detail responses expose `pending_receipt`; detail items expose `receivable`. Receipt scanners and missing-mat warnings use the same current-placement eligibility rule.

No schema migration is required. Frontend assets were generated successfully using `npm.cmd run build` from `site/`; Laravel's updated bundle is `site/public/build/assets/main-F2bis3Ae.js`. The build reports a chunk-size warning; no bundle restructuring was added.

Tests, assertions, lint, typechecks, browser/HTTP checks and business-data probes were not run. Seven existing washing test request payloads received only the newly required inventory receiver field; no new tests or assertions were added. Runtime behavior and concurrency remain unverified.

The existing [[_code/00 Code map]] remains stale because the previously missing vaultify helper was not replaced; no generated map regions were edited.
