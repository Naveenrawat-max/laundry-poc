/** The handheld's screens that every scanning role shares: scan a pile, give and receive a handover. */
import { Check, Minus } from 'lucide-react'
import { useEffect, useState } from 'react'

import { Empty, StatusChip } from '@/app/ui'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import { Select } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { PilePanel, ScanResults } from '@/handheld/pile'
import type { Api } from '@/lib/api'
import { useAction, useLoad } from '@/lib/hooks'
import { HANDOVER, STATUS, day } from '@/lib/labels'
import type { DriverNeeds, Handover, HandoverRow, HandoverType, Mat, ScanAnswer, Status, User } from '@/lib/types'

export type Ctx = { api: Api; user: User }

/** What each handover type takes and who closes it, as HandoverController::TYPES has it. */
const RULE: Record<HandoverType, { from: Status; twoSided: boolean }> = {
  inventory_to_driver: { from: 'qc_passed', twoSided: true },
  driver_to_washing: { from: 'collected', twoSided: true },
  washing_to_inventory: { from: 'in_washing', twoSided: false },
  driver_to_inventory: { from: 'refused', twoSided: true },
  inventory_to_washing: { from: 'rewash', twoSided: true },
}

const reads = (epcs: string[]) => ({ source: 'simulator', reads: epcs.map((epc) => ({ epc })) })

export function Hint({ children }: { children: React.ReactNode }) {
  return <p className="text-sm leading-relaxed text-muted-foreground">{children}</p>
}

/** Scan a pile and show what the server made of it. Used for "ready to use" and for the lookup. */
export function ScanScreen({ api, path, fits, intro, scanLabel }: { api: Api; path: string; fits?: Status[]; intro: string; scanLabel?: string }) {
  const [answer, setAnswer] = useState<ScanAnswer | null>(null)
  return (
    <>
      <Hint>{intro}</Hint>
      <PilePanel api={api} fits={fits} scanLabel={scanLabel} onScan={async (epcs) => setAnswer(await api.post<ScanAnswer>(path, reads(epcs)))} />
      <ScanResults answer={answer} />
    </>
  )
}

function Tick({ on }: { on: boolean }) {
  return on ? <Check className="size-4 text-green-700 dark:text-green-400" aria-label="Scanned" /> : <Minus className="size-4 text-muted-foreground" aria-label="Not scanned" />
}

/** One open handover, live: its pile with both scans, the scanner, and the button that closes it. */
export function HandoverLive({ api, id, side, onLeave }: { api: Api; id: number; side: 'give' | 'receive'; onLeave: () => void }) {
  // No push on the hosting, so the other side's scans arrive by asking again every few seconds.
  const state = useLoad(() => api.get<{ handover: Handover }>(`/handovers/${id}`).then((r) => r.handover), [api, id], 3000)
  const [answer, setAnswer] = useState<ScanAnswer | null>(null)
  const close = useAction()
  const h = state.data
  if (!h) return state.error ? <Alert tone="danger">{state.error}</Alert> : <Skeleton className="h-24" />

  const rule = RULE[h.type]
  const other = side === 'give' ? h.receiver.name : h.giver.name
  const mayClose = side === 'receive' || !rule.twoSided
  const moved = h.items.filter((i) => i.given_at && (i.received_at || !rule.twoSided)).length
  const pendingReceipt = h.type === 'washing_to_inventory' && h.pending_receipt

  return (
    <>
      <div className="card-chai p-3">
        <p className="font-montserrat text-sm font-semibold">
          {HANDOVER[h.type].name} {side === 'give' ? 'to' : 'from'} {other}
        </p>
        {h.order_number && <p className="mt-1 font-mono text-xs font-medium">Order {h.order_number}</p>}
        <p className="text-xs text-muted-foreground">
          {h.status === 'closed' ? `Closed.${pendingReceipt ? ' Inventory receipt pending.' : ''}` : rule.twoSided ? 'A mat moves the moment both of you have scanned it.' : 'A mat is ready the moment you scan it out. The other scan is optional.'} {moved} of{' '}
          {h.items.length} moved.
        </p>
        {h.items.length > 0 && (
          <table className="mt-2 w-full text-left text-sm">
            <thead>
              <tr className="text-[10px] tracking-wide text-muted-foreground uppercase">
                <th className="py-1 font-medium">Mat</th>
                <th className="py-1 font-medium">Given</th>
                <th className="py-1 font-medium">Received</th>
              </tr>
            </thead>
            <tbody>
              {h.items.map((item) => (
                <tr key={item.id} className="border-t border-border">
                  <td className="py-1.5 font-mono text-xs">{item.sku}</td>
                  <td className="py-1.5">
                    <Tick on={Boolean(item.given_at)} />
                  </td>
                  <td className="py-1.5">
                    <Tick on={Boolean(item.received_at)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {h.status === 'open' || (side === 'receive' && pendingReceipt) ? (
        <>
          <PilePanel
            api={api}
            fits={side === 'give' ? [rule.from] : undefined}
            expected={side === 'receive' && h.type === 'washing_to_inventory' ? h.items.filter((i) => i.receivable).map((i) => i.sku) : h.order_id ? h.items.filter((i) => side === 'give' || i.given_at).map((i) => i.sku) : side === 'receive' ? h.items.filter((i) => i.given_at).map((i) => i.sku) : undefined}
            scanLabel={side === 'give' ? 'Scan out' : 'Scan in'}
            onScan={async (epcs) => {
              setAnswer(await api.post<ScanAnswer>(`/handovers/${id}/scan`, reads(epcs)))
              await state.reload()
            }}
          />
          <ScanResults answer={answer} />
          {close.error && <Alert tone="danger">{close.error}</Alert>}
          {h.order_id && rule.twoSided ? <Hint>Both people must scan every listed mat. The order updates automatically after the final receipt.</Hint> : h.status === 'open' && mayClose ? (
            <Button
              disabled={close.busy}
              onClick={() =>
                void close.run(async () => {
                  await api.post(`/handovers/${id}/close`)
                  await state.reload()
                })
              }
            >
              {close.busy ? 'Closing' : 'Done, close the handover'}
            </Button>
          ) : h.status === 'open' ? (
            <Hint>{other} closes this handover after scanning in. A mat only you scanned stays with you and is flagged for the office.</Hint>
          ) : <><Hint>The wash was counted when washing scanned out. Scan receipt to acknowledge the mats without adding another wash.</Hint><Button onClick={onLeave}>Back</Button></>}
        </>
      ) : (
        <>
          <Alert tone="success" title="Handover closed">
            {moved} {moved === 1 ? 'mat' : 'mats'} changed hands.
            {h.items.length > moved ? ` ${h.items.length - moved} had only one scan and went to the office as issues.` : ''}
            {pendingReceipt ? ' Inventory can still acknowledge receipt from Receive from washing.' : ''}
          </Alert>
          <Button onClick={onLeave}>Back</Button>
        </>
      )}
    </>
  )
}

/** Hand a pile to someone: pick the receiver, then scan the mats out. */
export function GiveScreen({ api, user, type }: Ctx & { type: HandoverType }) {
  // A handover of this type that is still open is picked up again, so a reload loses nothing.
  const open = useLoad(() => api.get<{ handovers: HandoverRow[] }>('/handovers').then((r) => r.handovers.find((h) => h.status === 'open' && h.type === type && h.giver_id === user.id) ?? null), [api, type, user.id])
  const receivers = useLoad(() => api.get<{ rows: { id: number; name: string }[] }>(`/handover-receivers?type=${type}`).then((r) => r.rows), [api, type])
  const needs = useLoad(() => (type === 'inventory_to_driver' ? api.get<{ drivers: DriverNeeds[] }>('/tasks').then((r) => r.drivers) : Promise.resolve([])), [api, type])
  const [id, setId] = useState<number | null>(null)
  const [receiverId, setReceiverId] = useState('')
  const start = useAction()

  useEffect(() => {
    if (id === null && open.data) setId(open.data.id)
  }, [open.data, id])
  useEffect(() => {
    if (!receiverId && receivers.data?.[0]) setReceiverId(String(receivers.data[0].id))
  }, [receivers.data, receiverId])

  if (id !== null) {
    return (
      <HandoverLive
        api={api}
        id={id}
        side="give"
        onLeave={() => {
          setId(null)
          void open.reload()
        }}
      />
    )
  }
  if (open.loading || receivers.loading) return <Skeleton className="h-24" />

  const need = needs.data?.find((d) => String(d.driver.id) === receiverId)
  return (
    <>
      <Hint>
        {HANDOVER[type].name}: {HANDOVER[type].route.toLowerCase()}. Only mats that are "{STATUS[RULE[type].from].toLowerCase()}" can go.
      </Hint>
      {receivers.data?.length === 0 ? (
        <Empty>Nobody can receive this yet. The office adds users.</Empty>
      ) : (
        <>
          <Field label="Hand over to">
            <Select value={receiverId} onChange={(e) => setReceiverId(e.target.value)} wrapperClassName="w-full">
              {receivers.data?.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                </option>
              ))}
            </Select>
          </Field>
          {need && (
            <div className="card-chai p-3 text-sm">
              <p className="mb-1 font-montserrat font-semibold">{need.driver.name} still needs</p>
              <ul className="space-y-0.5">
                {need.needs.map((n) => (
                  <li key={n.item_type_id} className="flex justify-between gap-2">
                    <span className="truncate text-muted-foreground">{n.type}</span>
                    <span className="tabular-nums">
                      {n.remaining} of {n.needed}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {start.error && <Alert tone="danger">{start.error}</Alert>}
          <Button
            variant="solid"
            iconRight={null}
            disabled={start.busy || !receiverId}
            onClick={() => void start.run(async () => setId((await api.post<{ handover: Handover }>('/handovers', { type, receiver_id: Number(receiverId) })).handover.id))}
          >
            {start.busy ? 'Opening' : 'Start the handover'}
          </Button>
        </>
      )}
    </>
  )
}

/** Receive a pile: the open handovers waiting for this person, then scan the mats in. */
export function ReceiveScreen({ api, user, types }: Ctx & { types: HandoverType[] }) {
  const waiting = useLoad(() => api.get<{ handovers: HandoverRow[] }>('/handovers').then((r) => r.handovers.filter((h) => (h.status === 'open' || (h.type === 'washing_to_inventory' && h.pending_receipt)) && h.receiver_id === user.id && types.includes(h.type))), [api, user.id, types.join()], 4000)
  const [id, setId] = useState<number | null>(null)

  if (id !== null) {
    return (
      <HandoverLive
        api={api}
        id={id}
        side="receive"
        onLeave={() => {
          setId(null)
          void waiting.reload()
        }}
      />
    )
  }
  if (!waiting.data) return waiting.error ? <Alert tone="danger">{waiting.error}</Alert> : <Skeleton className="h-24" />

  return (
    <>
      <Hint>Open a pile someone is handing you, then scan every mat you really get.</Hint>
      {waiting.data.length === 0 ? (
        <Empty>Nothing is waiting for you. A pile shows up here as soon as the giver starts the handover.</Empty>
      ) : (
        <ul className="space-y-2">
          {waiting.data.map((h) => (
            <li key={h.id}>
              <button type="button" onClick={() => setId(h.id)} className="card-chai flex w-full cursor-pointer items-center justify-between gap-3 p-3 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <span>
                  <span className="block font-montserrat text-sm font-semibold">
                    {HANDOVER[h.type].name} from {h.giver_name}
                  </span>
                  {h.order_number && <span className="block font-mono text-xs">Order {h.order_number}</span>}
                  <span className="block text-xs text-muted-foreground">
                    {h.given} given, {h.received} received
                  </span>
                </span>
                <span className="text-xs text-muted-foreground">{h.status === 'closed' ? 'Awaiting receipt' : 'Open'}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

/** The mats that are with this role right now. The server decides which ones those are. */
export function MatListScreen({ api, empty }: Ctx & { empty: string }) {
  const state = useLoad(() => api.get<{ items: Mat[]; total: number }>('/items'), [api], 8000)
  if (!state.data) return state.error ? <Alert tone="danger">{state.error}</Alert> : <Skeleton className="h-24" />
  if (state.data.total === 0) return <Empty>{empty}</Empty>
  return (
    <>
      <Hint>
        {state.data.total} {state.data.total === 1 ? 'mat' : 'mats'}
        {state.data.total > state.data.items.length ? `, the first ${state.data.items.length} shown` : ''}.
      </Hint>
      <ul className="divide-y divide-border">
        {state.data.items.map((m) => (
          <li key={m.id} className="flex items-center justify-between gap-2 py-2 text-sm">
            <span>
              <span className="block font-mono text-xs font-medium">{m.sku}</span>
              <span className="block text-xs text-muted-foreground">
                {m.type}
                {m.current_wash_date ? `, washed ${day(m.current_wash_date)}` : ''}
              </span>
            </span>
            <StatusChip status={m.status} />
          </li>
        ))}
      </ul>
    </>
  )
}

/** How many mats of each type every driver still needs for his open deliveries and swaps. */
export function NeedsScreen({ api }: Ctx) {
  const state = useLoad(() => api.get<{ drivers: DriverNeeds[] }>('/tasks').then((r) => r.drivers), [api], 8000)
  if (!state.data) return state.error ? <Alert tone="danger">{state.error}</Alert> : <Skeleton className="h-24" />
  if (state.data.length === 0) return <Empty>No driver has an open delivery or swap. The office assigns tasks.</Empty>
  return (
    <>
      <Hint>Mats each driver still has to get from you, after what is on his van already.</Hint>
      {state.data.map((d) => (
        <div key={d.driver.id} className="card-chai p-3 text-sm">
          <p className="mb-1.5 font-montserrat font-semibold">{d.driver.name}</p>
          <ul className="space-y-1">
            {d.needs.map((n) => (
              <li key={n.item_type_id} className="flex items-baseline justify-between gap-2">
                <span className="truncate text-muted-foreground">{n.type}</span>
                <span className="shrink-0 tabular-nums">
                  <span className="font-semibold">{n.remaining}</span> to go, {n.carried} on the van
                </span>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </>
  )
}
