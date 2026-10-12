# Proposed flow

Version: 0.1.3

Proposed mat lifecycle for the RFID laundry POC. Defaults that still need client confirmation are listed under the diagram.

![Proposed flow](flow.png)

<details>
<summary>Mermaid source for flow.png</summary>

```mermaid
flowchart TD
    SETUP["SETUP (webapp)<br/>Customer → Building → Floor/Zone<br/>mat type, qty, n days"]

    subgraph INV[" "]
        T1["1 INVENTORY"]:::phase ~~~ REG
        REG["Register<br/>2 tags, same EPC, 2 TIDs<br/>primary + secondary"] --> READY["Ready to use"]
        READY --> QC{"QC"}
        QC -- pass --> OUT["Outbound"]
    end

    subgraph SITE[" "]
        T2["2 SITE"]:::phase ~~~ DEL
        DEL["Driver delivers<br/>scan list, photo, signature"] --> ATC["At customer<br/>D = today, due = D+n"]
    end

    subgraph LOG[" "]
        T3["3 LOGISTICS (reverse)"]:::phase ~~~ SWAP
        SWAP["Swap task<br/>drop clean, pick dirty<br/>scan list, photo, signature"] --> PICKED["Picked up"]
    end

    subgraph WASH[" "]
        T4["4 WASHING"]:::phase ~~~ INW
        INW["In washing<br/>previous date = old current date<br/>current date = today<br/>cycle +1"]
    end

    FIX["Rewash / Repair / Scrap"]

    SETUP -- "creates delivery task" --> OUT
    OUT -- "Handover A<br/>inventory scans out, driver scans in" --> DEL
    ATC -- "D+n reached" --> SWAP
    SWAP -. "clean mat placed" .-> ATC
    PICKED -- "Handover B<br/>driver scans out, washing scans in" --> INW
    INW -- "Handover C<br/>washing scans out, inventory scans in (optional)" --> READY

    QC -- fail --> FIX
    FIX -. "rewash" .-> INW
    DEL -. "refused or undelivered, no wash" .-> READY

    classDef phase fill:none,stroke:none,font-weight:bold,font-size:18px
```

</details>

## Rules the diagram does not show

- A mat moves at a handover only when both sides have scanned it. A mat scanned by one side stays with the giver and is flagged.
- Wash count goes up once, at the washing manager's scan in Handover B. A re-scan adds nothing.
- Either tag heard means the mat is present. Primary and secondary matter only at registration and tag replacement.

## Defaults to confirm with the client

- Swap happens in one visit, so each customer mat needs a second one in circulation.
- Mats are a pool per type, not tied to one customer.
- One inventory location and one washing location.
- Scanners are three roles: inventory, driver, washing. A fourth is needed only if outbound is a separate person doing the scan-out in Handover A.
