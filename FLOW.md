# Proposed flow

Version: 0.1.7

Proposed mat lifecycle for the RFID laundry POC. Defaults that still need client confirmation are listed under the diagram.

![Proposed flow](flow-0.1.6.png)

> The picture still shows the wash count at Handover B. It moves to Handover C; the Mermaid source below is right. Render the picture again before it is shown to the client.

<details>
<summary>Mermaid source for flow-0.1.6.png</summary>

```mermaid
flowchart LR
    SETUP["SETUP (webapp)<br/>Customer → Building → Floor/Zone<br/>mat type, qty, n days"]

    subgraph INV["1 INVENTORY"]
        direction TB
        REG["Register<br/>2 tags, same EPC, 2 TIDs<br/>primary + secondary"] --> READY["Ready to use"]
        READY --> QC{"QC"}
        QC -- pass --> OUT["Outbound"]
        QC -- fail --> FIX["Rewash (to 4)<br/>Repair<br/>Scrap"]:::side
    end

    subgraph SITE["2 SITE"]
        direction TB
        DEL["Driver delivers<br/>scan list, photo, signature"] --> ATC["At customer<br/>D = today, due = D+n"]
        DEL -. "refused or<br/>undelivered" .-> REF["Back to 1<br/>Ready to use<br/>no wash"]:::side
    end

    subgraph LOG["3 LOGISTICS (reverse)"]
        direction TB
        SWAP["Swap task<br/>drop clean, pick dirty<br/>scan list, photo, signature"] --> PICKED["Picked up"]
    end

    subgraph WASH["4 WASHING"]
        direction TB
        INW["In washing"]
    end

    BACK(["Back to 1 INVENTORY<br/>Ready to use"])

    SETUP -- "creates<br/>delivery task" --> INV
    INV -- "Handover A<br/>inventory scans out<br/>driver scans in" --> SITE
    SITE -- "D+n reached" --> LOG
    LOG -- "Handover B<br/>driver scans out<br/>washing scans in" --> WASH
    WASH -- "Handover C<br/>washing scans out (required)<br/>previous date = old current date<br/>current date = today, cycle +1<br/>inventory scans in (optional)" --> BACK

    classDef side stroke-dasharray:4 3
```

</details>

## Rules the diagram does not show

- A mat moves at a handover only when both sides have scanned it. A mat scanned by one side stays with the giver and is flagged.
- Wash count goes up once, at the washing manager's scan after washing, in Handover C. That scan is required. A re-scan adds nothing.
- Either tag heard means the mat is present. Primary and secondary matter only at registration and tag replacement.

## Defaults to confirm with the client

- Swap happens in one visit, so each customer mat needs a second one in circulation.
- Mats are a pool per type, not tied to one customer.
- One inventory location and one washing location.
- Scanners are three roles: inventory, driver, washing. A fourth is needed only if outbound is a separate person doing the scan-out in Handover A.
