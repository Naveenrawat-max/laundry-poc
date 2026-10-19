/** Exact-mat order work: inventory QC, driver scans and proof, pickup receipt and washing. */
import { X } from 'lucide-react'
import { useEffect, useState } from 'react'

import { activeOrderItems, ORDER_STATUS, OrderProducts, OrderSummary } from '@/app/orders'
import { Empty, Load } from '@/app/ui'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { PilePanel } from '@/handheld/pile'
import { type Ctx, GiveScreen, HandoverLive, Hint } from '@/handheld/screens'
import { photoFromFile, samplePhoto, SignaturePad } from '@/handheld/stop'
import { useAction, useLoad } from '@/lib/hooks'
import { day } from '@/lib/labels'
import type { AvailableOrderItem, Order, OrderItem } from '@/lib/types'

type Mode = 'qc' | 'driver' | 'washing' | 'inventory-receive'
type ReturnedMat = { id: number; sku: string; type: string; epc: string }
const reads = (epcs: string[]) => ({ source: 'simulator', reads: epcs.map((epc) => ({ epc })) })

/** Mats inventory just received back. They stay here until Pass, Rewash, Repair or Scrap. */
function ReturnedMats({ api }: Pick<Ctx, 'api'>) {
  const state = useLoad(() => api.get<{ items: ReturnedMat[] }>('/items?status=registered').then((r) => r.items), [api])
  const [epc, setEpc] = useState('')
  const [pileTick, setPileTick] = useState(0)
  const act = useAction()
  const mat = state.data?.find((m) => m.epc.toUpperCase() === epc.toUpperCase())
  return <section className="space-y-3">
    <h2 className="font-montserrat text-sm font-semibold">Returned mats</h2>
    <Hint>A mat scanned in from a driver waits here. Check it before it can go out again.</Hint>
    <Load state={state}>{(rows) => rows.length ? <>
      <ul className="space-y-1">{rows.map((m) => <li key={m.id} className="flex items-center justify-between gap-2 text-xs"><span className="font-mono">{m.sku}</span><span className="text-muted-foreground">{m.type}</span></li>)}</ul>
      <PilePanel api={api} refreshToken={pileTick} single expected={rows.map((m) => m.sku)} scanLabel="Scan a returned mat" onScan={async ([one]) => { if (!rows.some((m) => m.epc.toUpperCase() === one.toUpperCase())) throw new Error('This mat is not waiting in quality check.'); setEpc(one); act.clear() }} />
    </> : <Empty>No returned mats waiting for a check.</Empty>}</Load>
    {mat && <div className="space-y-3 rounded-lg border border-border p-3"><p className="font-mono text-sm">{mat.sku}</p><div className="grid grid-cols-2 gap-2">{(['pass', 'rewash', 'repair', 'scrap'] as const).map((result) => <Button key={result} variant={result === 'pass' ? 'solid' : 'soft'} disabled={act.busy} onClick={() => void act.run(async () => {
      const answer = await api.post<{ results: { result: string; reason?: string }[] }>('/items/qc', { source: 'simulator', epc, result })
      const line = answer.results[0]
      if (!line || line.result === 'rejected' || line.result === 'unknown') throw new Error(line?.reason ?? 'This mat cannot be checked.')
      setEpc('')
      setPileTick((n) => n + 1)
      await state.reload()
    })}>{result === 'pass' ? 'Pass' : result === 'rewash' ? 'Rewash' : result === 'repair' ? 'Repair' : 'Scrap'}</Button>)}</div></div>}
    {act.error && <Alert tone="danger">{act.error}</Alert>}
  </section>
}

/** Lists role-scoped orders. Polling is also the incoming-delivery notification on this demo. */
export function OrderTasksScreen({ api, user, mode }: Ctx & { mode: Mode }) {
  const state = useLoad(() => api.get<{ orders: Order[] }>('/orders').then((r) => r.orders), [api], 5000)
  const [selected, setSelected] = useState<Order[]>([])
  const leave = () => { setSelected([]); void state.reload() }
  if (selected.length === 1) return <OrderWork key={selected[0].id} api={api} user={user} mode={mode} id={selected[0].id} onLeave={leave} />
  if (selected.length > 1) return <>
    <Button variant="ghost" onClick={leave}>Back to visits</Button>
    <div className="card-chai space-y-1 p-3"><h2 className="font-montserrat text-sm font-semibold">Customer name: {selected[0].customer_name}</h2><p className="text-xs">{selected[0].building_name} · {day(selected[0].due_date)}</p><Hint>Delivery and pickup in one visit. Each order keeps its own scans and proof.</Hint></div>
    {selected.map((o) => <section key={o.id} className="space-y-3 border-t border-border pt-3" aria-label={`${o.type === 'delivery' ? 'Delivery' : 'Pickup'} ${o.number}`}><h2 className="font-montserrat text-sm font-semibold">{o.type === 'delivery' ? 'Delivery' : 'Pickup'} · {o.number}</h2><OrderWork api={api} user={user} mode={mode} id={o.id} onLeave={leave} /></section>)}
  </>
  const fits = (o: Order) => mode === 'qc' ? o.type === 'delivery' && !o.dispatch_handover_id && (o.status === 'pending_qc' || o.status === 'ready') : mode === 'inventory-receive' ? o.type === 'pickup' && o.status === 'awaiting_receipt' && o.destination === 'inventory' : mode === 'washing' ? o.type === 'pickup' && o.destination === 'washing' && !['done', 'cancelled'].includes(o.status) : !['done', 'cancelled'].includes(o.status)
  return <>
    <Hint>{mode === 'qc' ? 'Open a delivery order, or check a mat that just came back from a driver. Passed stock is handed to the driver.' : mode === 'driver' ? 'Office orders name the exact mats. A checklist mark appears only after that mat is scanned successfully.' : mode === 'washing' ? 'Incoming pickups notify this washing house as soon as the office creates them. Open the driver’s order to receive its mats.' : 'Receive customer pickups sent to inventory and refused delivery orders returned by their driver.'}</Hint>
    {mode === 'qc' && <ReturnedMats api={api} />}
    <Load state={state}>{(all) => {
      const orders = all.filter(fits)
      const grouped = new Map<string, Order[]>()
      for (const o of orders) {
        const key = mode === 'driver' && o.customer_id && o.building_id && o.assigned_to === user.id && o.due_date ? `${o.customer_id}:${o.building_id}:${o.assigned_to}:${o.due_date}` : `order:${o.id}`
        const group = grouped.get(key) ?? []
        group.push(o)
        grouped.set(key, group)
      }
      const visits = [...grouped.values()].flatMap((group) => group.some((o) => o.type === 'delivery') && group.some((o) => o.type === 'pickup') ? [group] : group.map((o) => [o]))
      return orders.length ? <><Alert title={`${orders.length} ${mode === 'washing' ? 'incoming washing deliveries' : 'orders'}`} >Updates arrive automatically.</Alert><ul className="space-y-2">{visits.map((visit) => {
        const o = visit[0]
        return <li key={o.id}><button type="button" onClick={() => setSelected(visit)} className="card-chai w-full cursor-pointer space-y-1 p-3 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"><span className="block font-montserrat text-sm font-semibold">{visit.length > 1 ? 'Delivery + pickup visit' : `${o.number} · ${o.type === 'delivery' ? 'Delivery' : 'Pickup'}`}</span>{mode === 'driver' ? <span className="block text-xs">{o.type === 'pickup' || visit.length > 1 ? 'Customer name:' : 'Recipient'} {o.customer_name} · {o.building_name}</span> : mode !== 'washing' && <span className="block text-xs">{o.customer_name} · {o.building_name}</span>}<span className="block text-xs text-muted-foreground">{mode === 'washing' ? `Driver: ${o.assigned_name} · ` : ''}{day(o.due_date)}{visit.length === 1 ? ` · ${ORDER_STATUS[o.status]}` : ''}</span>{visit.map((order) => <span key={order.id} className="block text-xs">{visit.length > 1 ? `${order.number} · ${order.type === 'delivery' ? 'Delivery' : 'Pickup'} · ${ORDER_STATUS[order.status]} · ` : ''}{activeOrderItems(order).length} exact mats</span>)}</button></li>
      })}</ul></> : <Empty>No orders waiting for this step.</Empty>
    }}</Load>
  </>
}

function Replacement({ api, order, line, onDone }: Pick<Ctx, 'api'> & { order: Order; line: OrderItem; onDone: () => Promise<void> }) {
  const choices = useLoad(() => api.get<{ items: AvailableOrderItem[] }>(`/orders/available-items?type=delivery&customer_id=${order.customer_id}&building_id=${order.building_id}`).then((r) => r.items.filter((m) => m.item_type_id === line.item_type_id && m.catalog_sku_id === line.catalog_sku_id)), [api, order.id, line.id])
  const [replacement, setReplacement] = useState('')
  const act = useAction()
  return <div className="rounded-lg border border-red-300 p-3 dark:border-red-800">
    <p className="mb-2 text-xs font-medium">Replace failed mat {line.sku}</p>
    {line.qc_note && <p className="mb-2 text-xs text-muted-foreground">{line.qc_note}</p>}
    <Load state={choices}>{(rows) => rows.length ? <div className="space-y-2"><Select aria-label={`Replacement for ${line.sku}`} value={replacement} onChange={(e) => setReplacement(e.target.value)} wrapperClassName="w-full"><option value="">Choose same-type replacement</option>{rows.map((m) => <option key={m.id} value={m.id}>{m.sku} · {m.catalog_sku_code || m.type_name}</option>)}</Select><Button disabled={!replacement || act.busy} onClick={() => void act.run(async () => { await api.post(`/orders/${order.id}/replace`, { item_id: line.item_id, replacement_item_id: Number(replacement) }); await onDone() })}>Replace mat</Button></div> : <Hint>No replacement available. The office must map more stock or release a repaired mat of this subcategory.</Hint>}</Load>
    {act.error && <Alert tone="danger" className="mt-2">{act.error}</Alert>}
  </div>
}

/** Proof mounts only once persisted scans cover every active line. */
function OrderProof({ api, order, onDone }: Pick<Ctx, 'api'> & { order: Order; onDone: () => Promise<void> }) {
  const [photo, setPhoto] = useState<Blob | null>(null)
  const [photoUrl, setPhotoUrl] = useState<string | null>(null)
  const [signature, setSignature] = useState<Blob | null>(null)
  const [name, setName] = useState('')
  const [refused, setRefused] = useState<Set<string>>(new Set())
  const act = useAction()
  const lines = activeOrderItems(order)
  const bulk = refused.size === 0 ? 'accepted' : refused.size === lines.length ? 'refused' : ''
  const markRefused = async (epcs: string[]) => {
    if (order.type === 'delivery' && epcs.length) await api.post(`/orders/${order.id}/refusal-scan`, reads(epcs))
    setRefused(new Set(epcs))
  }
  useEffect(() => {
    if (!photo) { setPhotoUrl(null); return }
    const url = URL.createObjectURL(photo)
    setPhotoUrl(url)
    return () => URL.revokeObjectURL(url)
  }, [photo])
  return <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void act.run(async () => {
    if (!photo || !signature) throw new Error(`Photo and ${order.type === 'pickup' ? 'customer' : 'recipient'} signature are required.`)
    const response = refused.size > 0 ? 'refused' : 'accepted'
    await api.form(`/orders/${order.id}/complete`, { source: 'simulator', signed_name: name, customer_response: response, ...(order.type === 'delivery' && refused.size > 0 ? { refused_epcs: [...refused] } : {}) }, { photo, signature })
    await onDone()
  }) }}>
    <h3 className="font-montserrat text-sm font-semibold">Proof</h3>
    <Hint>Every ordered mat is scanned. Accept all, refuse all, or cross out a single mat.</Hint>
    {order.type === 'delivery' && <section className="space-y-3 rounded-lg border border-border p-3">
      <Field label="All mats"><Select value={bulk} disabled={act.busy} onChange={(e) => { const value = e.target.value; void act.run(() => markRefused(value === 'refused' ? lines.map((m) => m.epc) : [])) }} wrapperClassName="w-full"><option value="" disabled>Some refused</option><option value="accepted">Accept all</option><option value="refused">Refuse all</option></Select></Field>
      <ul className="space-y-1">{lines.map((m) => <li key={m.id} className="flex items-center gap-2 py-1 text-xs"><span className="min-w-0 flex-1 font-mono">{m.sku}</span><span className={refused.has(m.epc) ? 'text-destructive' : 'text-muted-foreground'}>{refused.has(m.epc) ? 'Refused' : 'Accepted'}</span><button type="button" className="inline-flex size-8 cursor-pointer items-center justify-center rounded-md text-destructive outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50" aria-pressed={refused.has(m.epc)} aria-label={refused.has(m.epc) ? `Accept ${m.sku}` : `Refuse ${m.sku}`} disabled={act.busy} onClick={() => void act.run(async () => { const next = new Set(refused); if (next.has(m.epc)) next.delete(m.epc); else { await api.post(`/orders/${order.id}/refusal-scan`, reads([m.epc])); next.add(m.epc) } setRefused(next) })}><X className="size-4" /></button></li>)}</ul>
    </section>}
    {photoUrl && <img src={photoUrl} alt="Order proof" className="aspect-video w-full rounded-md object-cover" />}
    <div className="grid grid-cols-2 gap-2"><label className="inline-flex min-h-9 cursor-pointer items-center justify-center rounded-md bg-primary px-3 text-sm font-semibold text-primary-foreground focus-within:ring-[3px] focus-within:ring-ring/50">Take a photo<input type="file" accept="image/*" capture="environment" className="sr-only" onChange={(e) => { const file = e.target.files?.[0]; if (file) void act.run(async () => setPhoto(await photoFromFile(file))) }} /></label><Button type="button" variant="ghost" onClick={() => void act.run(async () => setPhoto(await samplePhoto(order.number)))}>Use a sample</Button></div>
    <Field label={order.type === 'pickup' ? 'Customer name' : 'Recipient name'}><Input required maxLength={120} value={name} onChange={(e) => setName(e.target.value)} /></Field>
    <SignaturePad onChange={setSignature} />
    {act.error && <Alert tone="danger">{act.error}</Alert>}
    <Button type="submit" variant="solid" iconRight={null} disabled={act.busy || !photo || !signature}>{act.busy ? 'Saving proof' : `Confirm ${order.type === 'pickup' ? 'customer' : 'recipient'} response`}</Button>
  </form>
}

/** A linked return uses the existing two-sided handover scanner, never direct stock movement. */
function ReturnOrder({ api, order, onLeave }: Pick<Ctx, 'api'> & { order: Order; onLeave: () => void }) {
  const receivers = useLoad(() => api.get<{ rows: { id: number; name: string }[] }>('/handover-receivers?type=driver_to_inventory').then((r) => r.rows), [api])
  const [receiver, setReceiver] = useState('')
  const [handoverId, setHandoverId] = useState<number | null>(order.return_handover_id)
  const act = useAction()
  if (handoverId) return <HandoverLive api={api} id={handoverId} side="give" onLeave={onLeave} />
  return <>
    <OrderSummary order={order} />
    <OrderProducts order={{ ...order, items: order.items.filter((m) => m.customer_response === 'refused') }} stage="receive" />
    <Hint>Choose the inventory receiver. Scan the refused pieces out, then inventory scans the same pieces in. The office is notified; no office approval is required.</Hint>
    <Load state={receivers}>{(rows) => <Field label="Inventory receiver"><Select required value={receiver} onChange={(e) => setReceiver(e.target.value)} wrapperClassName="w-full"><option value="">Choose inventory receiver</option>{rows.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</Select></Field>}</Load>
    {act.error && <Alert tone="danger">{act.error}</Alert>}
    <Button disabled={!receiver || act.busy} onClick={() => void act.run(async () => { const { order: next } = await api.post<{ order: Order }>(`/orders/${order.id}/return`, { receiver_id: Number(receiver) }); setHandoverId(next.return_handover_id) })}>Start refused-mat handover</Button>
    <Button variant="ghost" onClick={onLeave}>Back</Button>
  </>
}

/** Driver returns choose the affected order, then share the normal give/receive handover. */
export function OrderReturnsScreen({ api, user }: Ctx) {
  const state = useLoad(() => api.get<{ orders: Order[] }>('/orders').then((r) => r.orders.filter((o) => o.type === 'delivery' && (o.return_pending_count ?? 0) > 0)), [api], 5000)
  const [order, setOrder] = useState<Order | null>(null)
  if (order) return <ReturnOrder key={order.id} api={api} order={order} onLeave={() => { setOrder(null); void state.reload() }} />
  return <>
    <Hint>Select the delivery order whose refused mats you are returning to inventory.</Hint>
    <Load state={state}>{(rows) => rows.length ? <ul className="space-y-2">{rows.map((o) => <li key={o.id}><button type="button" className="card-chai w-full cursor-pointer p-3 text-left text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50" onClick={() => setOrder(o)}><span className="block font-medium">{o.number} · {o.return_pending_count} refused mats</span><span className="block text-xs">Recipient {o.customer_name} · Task #{o.task_id}</span></button></li>)}</ul> : <Empty>No order mats await return.</Empty>}</Load>
    <details className="space-y-3"><summary className="cursor-pointer text-xs text-muted-foreground">Other refused mats from contract tasks</summary><GiveScreen api={api} user={user} type="driver_to_inventory" /></details>
  </>
}

function OrderWork({ api, mode, id, onLeave }: Ctx & { mode: Mode; id: number; onLeave: () => void }) {
  const state = useLoad(() => api.get<{ order: Order }>(`/orders/${id}`).then((r) => r.order), [api, id], 5000)
  const receivers = useLoad(() => mode === 'washing' ? api.get<{ rows: { id: number; name: string }[] }>('/handover-receivers?type=washing_to_inventory').then((r) => r.rows) : Promise.resolve([]), [api, mode])
  const [receiverId, setReceiverId] = useState('')
  const [washHandoverId, setWashHandoverId] = useState<number | null>(null)
  const [epc, setEpc] = useState('')
  const [note, setNote] = useState('')
  const [rejected, setRejected] = useState<{ epc: string; reason: string }[]>([])
  const [showLoad, setShowLoad] = useState(false)
  const [showReturn, setShowReturn] = useState(false)
  const [pileTick, setPileTick] = useState(0)
  const act = useAction()
  const order = state.data
  if (!order) return <Load state={state}>{() => null}</Load>
  const lines = activeOrderItems(order)
  const allScanned = lines.length > 0 && lines.every((m) => m.scanned_at)
  const allPassed = lines.length > 0 && lines.every((m) => m.qc_status === 'passed')
  const mat = lines.find((m) => m.epc === epc)
  const qc = mode === 'qc' && !order.dispatch_handover_id && !['done', 'cancelled'].includes(order.status)
  const driving = mode === 'driver' && (order.type === 'pickup' ? ['ready', 'in_progress'].includes(order.status) : order.status === 'in_progress')
  const receiving = (mode === 'washing' || mode === 'inventory-receive') && order.type === 'pickup' && order.status === 'awaiting_receipt'
  const washing = mode === 'washing' && order.status === 'received'
  const receiver = !receivers.loading && !receivers.error && receivers.data?.find((r) => String(r.id) === receiverId)
  if (showLoad && order.dispatch_handover_id) return <HandoverLive api={api} id={order.dispatch_handover_id} side="receive" onLeave={() => { setShowLoad(false); void state.reload() }} />
  if (showReturn) return <ReturnOrder api={api} order={order} onLeave={() => { setShowReturn(false); void state.reload() }} />
  if (washHandoverId !== null) return <HandoverLive api={api} id={washHandoverId} side="give" onLeave={() => { setWashHandoverId(null); void state.reload() }} />

  return <>
    <OrderSummary order={order} washing={mode === 'washing'} />
    {state.error && <Alert tone="warning">Could not refresh: {state.error}</Alert>}
    <OrderProducts order={order} stage={qc ? 'qc' : mode === 'washing' || mode === 'inventory-receive' ? 'receive' : 'scan'} />
    {qc && <>
      <Hint>Scan one ordered mat, then record its quality result. A failed mat must be replaced before dispatch.</Hint>
      <PilePanel api={api} refreshToken={pileTick} single expected={lines.map((m) => m.sku)} scanLabel="Scan mat for quality check" onScan={async ([one]) => {
        if (!lines.some((m) => m.epc === one)) throw new Error('This mat is not on this order.')
        setEpc(one); setNote(''); act.clear()
      }} />
      {mat && <div className="space-y-3 rounded-lg border border-border p-3"><p className="font-mono text-sm">{mat.sku}</p><Field label="QC note" aside="Optional"><Textarea value={note} maxLength={500} rows={2} onChange={(e) => setNote(e.target.value)} /></Field><div className="grid grid-cols-2 gap-2">{(['pass', 'rewash', 'repair', 'scrap'] as const).map((result) => <Button key={result} variant={result === 'pass' ? 'solid' : 'soft'} disabled={act.busy} onClick={() => void act.run(async () => { const { order: next } = await api.post<{ order: Order }>(`/orders/${id}/qc`, { source: 'simulator', epc, result, note: note || null }); state.adopt(next); setEpc(''); setPileTick((n) => n + 1) })}>{result === 'pass' ? 'Pass' : result === 'rewash' ? 'Rewash' : result === 'repair' ? 'Repair' : 'Scrap'}</Button>)}</div></div>}
      {lines.filter((m) => m.qc_status === 'failed').map((line) => <Replacement key={line.id} api={api} order={order} line={line} onDone={async () => { await state.reload(); setPileTick((n) => n + 1) }} />)}
      {order.items.some((m) => m.replaced_at) && <Hint>Replaced: {order.items.filter((m) => m.replaced_at).map((m) => m.sku).join(', ')}. Replacement mats need their own QC scan.</Hint>}
      {allPassed && <><Alert tone="success" title="All mats passed QC">The only next step is Hand over to driver. {order.assigned_name} then scans the same mats in on Load the van.</Alert><Button variant="solid" disabled={act.busy} onClick={() => void act.run(async () => { const { order: next } = await api.post<{ order: Order }>(`/orders/${id}/dispatch`, reads(lines.map((m) => m.epc))); state.adopt(next) })}>{act.busy ? 'Handing over' : 'Hand over to driver'}</Button></>}
    </>}
    {mode === 'driver' && order.type === 'delivery' && !order.dispatch_handover_id && ['pending_qc', 'ready'].includes(order.status) && <Alert>Inventory is preparing this delivery order. After QC and inventory scan-out, open Load the van to scan receipt before customer delivery.</Alert>}
    {mode === 'driver' && order.type === 'delivery' && order.dispatch_handover_id && order.status === 'ready' && <><Alert title="Load receipt required">Inventory scanned this order out. Open Load the van and scan the exact mats in before the customer-delivery scanner unlocks.</Alert><Button onClick={() => setShowLoad(true)}>Load the van · scan receipt</Button></>}
    {driving && <>
      {rejected.length > 0 && <Alert tone="warning" title="Tags outside this order were ignored"><ul className="space-y-1">{rejected.map((read) => <li key={read.epc}><span className="break-all font-mono text-xs">{read.epc}</span>: {read.reason}</li>)}</ul></Alert>}
      {!allScanned ? <>
        <Hint>Scan the delivery or pickup pile together. The scanner can read multiple mats; only matching order mats are checked automatically. Proof stays hidden until every ordered mat has been scanned. In this simulator, choose All expected, then scan the batch.</Hint>
        <PilePanel api={api} expected={lines.filter((m) => !m.scanned_at).map((m) => m.sku)} scanLabel={order.type === 'delivery' ? 'Scan delivery batch' : 'Scan pickup batch'} onScan={async (epcs) => {
          const result = await api.post<{ order: Order; rejected: { epc: string; reason: string }[] }>(`/orders/${id}/scan`, reads(epcs))
          setRejected(result.rejected)
          await state.reload()
        }} />
      </> : <OrderProof key={order.id} api={api} order={order} onDone={state.reload} />}
    </>}
    {receiving && <><Alert title={`Delivery from ${order.assigned_name}`}>Scan the mats received from this driver, then confirm receipt.</Alert><PilePanel api={api} expected={lines.map((m) => m.sku)} scanLabel="Confirm received mats" onScan={async (epcs) => { await api.post(`/orders/${id}/receive`, reads(epcs)); await state.reload() }} /></>}
    {mode === 'washing' && !receiving && !washing && !['done', 'cancelled'].includes(order.status) && <Hint>Incoming from {order.assigned_name}. Receipt opens after the driver completes the pickup.</Hint>}
    {washing && <><Alert title="Receipt confirmed">Choose the inventory receiver, then scan the clean order to confirm washing and hand it back. Each mat gets one wash added at handover. Inventory can acknowledge receipt from Receive from washing.</Alert><Load state={receivers}>{(rows) => rows.length ? <Field label="Inventory receiver"><Select value={receiverId} onChange={(e) => setReceiverId(e.target.value)} wrapperClassName="w-full"><option value="">Choose inventory receiver</option>{rows.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</Select></Field> : <Empty>No inventory receiver available. The office adds users.</Empty>}</Load>{receiver ? <PilePanel api={api} expected={lines.map((m) => m.sku)} scanLabel="Confirm washed · hand over to inventory" onScan={async (epcs) => { const result = await api.post<{ order: Order; handover_id: number | null }>(`/orders/${id}/wash`, { ...reads(epcs), receiver_id: receiver.id }); setWashHandoverId(result.handover_id); await state.reload() }} /> : <Hint>Select an inventory receiver to open the handover scanner.</Hint>}</>}
    {order.status === 'awaiting_receipt' && mode === 'driver' && <><Alert tone="success">{order.type === 'delivery' ? `${order.return_pending_count} refused mats must return to inventory. Scan them out through a return handover; inventory scans them in` : `Pickup confirmed. Deliver this order to ${order.destination === 'washing' ? order.washing_name : 'inventory'}; the recipient confirms receipt on their handheld`}.</Alert>{order.type === 'delivery' && <Button onClick={() => setShowReturn(true)}>Return refused mats</Button>}</>}
    {mode === 'qc' && order.dispatch_handover_id && <Alert tone="success">Order scanned out to {order.assigned_name}. {order.status === 'ready' ? 'Awaiting the driver’s Load the van receipt scan.' : 'Driver receipt confirmed.'}</Alert>}
    {order.status === 'done' && <Alert tone="success">Order completed{order.customer_response === 'refused' ? ' · customer refused' : ''}.</Alert>}
    {act.error && <Alert tone="danger">{act.error}</Alert>}
    <Button variant="ghost" onClick={onLeave} disabled={act.busy}>Back to orders</Button>
  </>
}

/** Compact menu notice for new office orders and washing-house deliveries. */
export function OrderNotice({ api, user }: Ctx) {
  const orders = useLoad(() => api.get<{ orders: Order[] }>('/orders').then((r) => r.orders.filter((o) => !['done', 'cancelled'].includes(o.status))), [api], 8000)
  if (!orders.data?.length) return null
  return <p className="rounded-lg bg-accent/50 px-3 py-2 text-xs" role="status">{orders.data.length} {user.role === 'washing' ? 'incoming washing orders — open Incoming deliveries' : user.role === 'inventory' ? 'active orders — open Quality check or Receive order returns' : 'office orders — open My tasks'}.</p>
}
