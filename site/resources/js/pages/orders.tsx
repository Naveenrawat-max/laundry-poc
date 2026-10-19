/** Office delivery and pickup orders reserve exact mats before handheld work begins. */
import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router'

import { ORDER_STATUS, RETURN_REASON, OrderProducts, OrderSummary, SwapDue, serviceSchedule } from '@/app/orders'
import { Empty, Load, PageHead } from '@/app/ui'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Dialog, DialogActions } from '@/components/ui/dialog'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { ScanScreen } from '@/handheld/screens'
import { useAction, useLoad } from '@/lib/hooks'
import { day, today } from '@/lib/labels'
import { useSession } from '@/lib/session'
import type { AvailableOrderItem, Building, Contract, Customer, Order, User, Zone } from '@/lib/types'

export function OrdersPage({ type }: { type: Order['type'] }) {
  const { api } = useSession()
  const [params] = useSearchParams()
  const delivery = type === 'delivery'
  const customers = useLoad(() => api.get<{ rows: Customer[] }>('/customers').then((r) => r.rows.filter((c) => c.is_active)), [api])
  const users = useLoad(() => api.get<{ rows: User[] }>('/users').then((r) => r.rows.filter((u) => u.is_active)), [api])
  const orders = useLoad(() => api.get<{ orders: Order[] }>('/orders').then((r) => r.orders.filter((o) => o.type === type)), [api, type], 8000)
  const [customer, setCustomer] = useState('')
  const [building, setBuilding] = useState('')
  const [driver, setDriver] = useState('')
  const [due, setDue] = useState(today())
  const [reason, setReason] = useState('')
  const [returnReason, setReturnReason] = useState<NonNullable<Order['return_reason']> | ''>('')
  const destination = returnReason === 'used' ? 'washing' : 'inventory'
  const [washing, setWashing] = useState('')
  const [picked, setPicked] = useState<Record<number, number>>({})
  const [created, setCreated] = useState<Order | null>(null)
  const [removing, setRemoving] = useState<Order | null>(null)
  const save = useAction()
  const remove = useAction()
  const contracts = useLoad(async () => ({ customer, building, rows: delivery && customer && building ? (await api.get<{ rows: Contract[] }>(`/contracts?customer_id=${customer}&building_id=${building}&status=active`)).rows : [] }), [api, delivery, customer, building], 8000)
  const buildings = useLoad(() => customer ? api.get<{ rows: Building[] }>(`/customers/${customer}/buildings`).then((r) => r.rows.filter((b) => b.is_active)) : Promise.resolve([]), [api, customer])
  const zones = useLoad(() => building ? api.get<{ rows: Zone[] }>(`/buildings/${building}/zones`).then((r) => r.rows.filter((z) => z.is_active)) : Promise.resolve([]), [api, building])
  const available = useLoad(async () => ({ customer, building, items: building && customer ? (await api.get<{ items: AvailableOrderItem[] }>(`/orders/available-items?type=${type}&customer_id=${customer}&building_id=${building}`)).items : [] }), [api, type, customer, building])
  const selectedLocation = buildings.data?.find((b) => String(b.id) === building && String(b.customer_id) === customer)
  const matchingContracts = contracts.data?.customer === customer && contracts.data.building === building ? contracts.data.rows.filter((c) => c.status === 'active' && String(c.customer_id) === customer && (c.building_id !== null ? String(c.building_id) === building : c.lines.some((l) => String(l.building_id) === building))) : []
  const contract = selectedLocation && !contracts.loading && !contracts.error && matchingContracts.length === 1 ? matchingContracts[0] : undefined
  const dueContext = useRef('')
  const dueEdited = useRef(false)
  const now = today()
  useEffect(() => { if (!dueEdited.current) setDue(now) }, [now])
  useEffect(() => {
    if (!customer || !buildings.data) return
    const rows = buildings.data.filter((b) => String(b.customer_id) === customer)
    if (rows.length !== 1 || String(rows[0].id) === building) return
    setBuilding(String(rows[0].id))
    setPicked({})
    dueContext.current = ''
    dueEdited.current = false
    setDue(today())
  }, [customer, building, buildings.data])
  useEffect(() => {
    if (!contract) return
    const key = `${customer}:${building}:${contract.id}`
    if (dueContext.current === key) return
    dueContext.current = key
    setPicked({})
  }, [customer, building, contract])
  const requirements = contract?.lines.filter((l) => String(l.building_id) === building) ?? []
  const products = (available.data?.customer === customer && available.data.building === building ? available.data.items : []).filter((m) => !delivery || requirements.some((l) => l.item_type_id === m.item_type_id))
  const selectedCount = (zoneId: number, typeId: number) => products.filter((m) => Object.hasOwn(picked, m.id) && picked[m.id] === zoneId && m.item_type_id === typeId).length
  const matchesContract = !delivery || (requirements.length > 0 && requirements.every((l) => selectedCount(l.zone_id, l.item_type_id) === l.quantity) && Object.keys(picked).length === requirements.reduce((n, l) => n + l.quantity, 0))

  return <>
    <PageHead title={delivery ? 'Ready to deliver · Delivery orders' : 'Pickup orders'}>{delivery ? 'Choose a customer and location. Its active contract supplies the service schedule and exact mat quantities. Assign a driver; inventory checks quality before dispatch.' : 'Choose a customer and location to see delivered mats. Select exact mats, a return reason and a driver. The reason determines the receiving workflow.'}</PageHead>
    {delivery && <details className="card-chai mt-8 p-4"><summary className="cursor-pointer font-medium">Release repaired mats</summary><div className="mt-4 space-y-3"><ScanScreen api={api} path="/items/ready" fits={['registered', 'repair']} intro="Scan repaired mats after inspection to make them ready. Legacy registered mats are also supported; newly mapped mats are ready automatically." scanLabel="Mark ready" /><Button variant="ghost" onClick={() => void available.reload()}>Refresh available inventory</Button></div></details>}
    <form className="card-chai mt-6 space-y-5 p-4 sm:p-6" onSubmit={(e) => { e.preventDefault(); void save.run(async () => {
      if (!Object.keys(picked).length) throw new Error('Select at least one mat.')
      if (delivery && !contract) throw new Error('This location must have exactly one active contract before creating a delivery order.')
      if (!matchesContract) throw new Error('Match every contract quantity for this building and zone before creating the order.')
      const { order } = await api.post<{ order: Order }>('/orders', { type, customer_id: Number(customer), building_id: Number(building), assigned_to: Number(driver), due_date: due, items: Object.entries(picked).map(([item_id, zone_id]) => ({ item_id: Number(item_id), zone_id })), destination: delivery ? 'customer' : destination, ...(delivery ? { contract_id: contract!.id } : { return_reason: returnReason, reason: reason || null, washing_user_id: destination === 'washing' ? Number(washing) : null }) })
      setCreated(order); setPicked({}); await Promise.all([orders.reload(), available.reload()])
    }) }}>
      <h2 className="font-montserrat text-lg font-semibold">Create {delivery ? 'delivery' : 'pickup'} order</h2>
      <div className="grid gap-4 sm:grid-cols-2">
        <Load state={customers}>{(rows) => <Field label="Customer"><Select required value={customer} disabled={save.busy} onChange={(e) => { setCustomer(e.target.value); setBuilding(''); setPicked({}); dueContext.current = ''; dueEdited.current = false; setDue(today()) }} wrapperClassName="w-full"><option value="">Choose customer</option>{rows.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select></Field>}</Load>
        <Load state={buildings}>{(rows) => <Field label="Location" hint={customer && !rows.some((b) => String(b.customer_id) === customer) ? 'Add an active location under Customers first.' : undefined}><Select required value={building} disabled={!customer || save.busy} onChange={(e) => { setBuilding(e.target.value); setPicked({}); dueContext.current = ''; dueEdited.current = false; setDue(today()) }} wrapperClassName="w-full"><option value="">Choose location</option>{rows.filter((b) => String(b.customer_id) === customer).map((b) => <option key={b.id} value={b.id}>{b.name} · {b.street}, {b.city}</option>)}</Select></Field>}</Load>
        <Load state={users}>{(rows) => <Field label="Driver"><Select required value={driver} onChange={(e) => setDriver(e.target.value)} wrapperClassName="w-full"><option value="">Choose driver</option>{rows.filter((u) => u.role === 'driver').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</Select></Field>}</Load>
        <Field label={delivery ? 'Driver delivery due date' : 'Driver pickup due date'} hint={delivery ? 'Defaults to today. Each accepted mat starts its contract swap interval after delivery.' : undefined}><Input required type="date" value={due} onChange={(e) => { dueEdited.current = true; setDue(e.target.value) }} /></Field>
        {!delivery && <>
          <Field label="Return reason"><Select required value={returnReason} disabled={save.busy} onChange={(e) => { setReturnReason(e.target.value as typeof returnReason); setWashing('') }} wrapperClassName="w-full"><option value="">Choose reason</option>{Object.entries(RETURN_REASON).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</Select></Field>
          <Field label="Note (optional)"><Input value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} /></Field>
          <Field label="Destination"><Input readOnly value={!returnReason ? 'Choose a return reason first' : destination === 'washing' ? 'Washing house · requires washing' : returnReason === 'damaged' ? 'Inventory · requires inspection / repair' : 'Inventory · ready after receipt'} /></Field>
          {destination === 'washing' && <Load state={users}>{(rows) => <Field label="Washing house recipient"><Select required value={washing} onChange={(e) => setWashing(e.target.value)} wrapperClassName="w-full"><option value="">Choose recipient</option>{rows.filter((u) => u.role === 'washing').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</Select></Field>}</Load>}
        </>}
      </div>
      {zones.error && <Alert tone="danger">{zones.error}</Alert>}
      {delivery && building && <Load state={contracts}>{() => contract ? <section className="space-y-2 rounded-lg border border-border p-3"><h3 className="text-sm font-medium">{selectedLocation?.name} · service requirements</h3><p className="text-sm">{serviceSchedule(contract.start_date, contract.swap_interval_days)}</p><p className="text-sm text-muted-foreground">{contract.next_swap_date ? `Next swap ${day(contract.next_swap_date)}` : 'Next swap is scheduled after delivery / swap completion.'}</p><ul className="space-y-1 text-sm">{requirements.map((l) => <li key={l.id}>{selectedCount(l.zone_id, l.item_type_id)} / {l.quantity} × {l.type_name} · {l.zone_name}</li>)}</ul><p className="text-xs text-muted-foreground">Fill every quantity exactly. Zones and products follow this location’s contract.</p></section> : <Alert tone="warning" title={matchingContracts.length > 1 ? 'Overlapping active contracts' : 'No active contract for this location'}>{matchingContracts.length > 1 ? 'End the overlapping contracts before creating a delivery order.' : 'Create and start this location’s contract before creating a delivery order.'} <Link to="/contracts" className="underline">Open Contracts</Link></Alert>}</Load>}
      {building ? <Load state={available}>{() => products.length ? <fieldset className="space-y-2"><legend className="mb-2 font-medium">{delivery ? 'Available contract inventory' : 'Mats delivered to this customer'} · {Object.keys(picked).length} selected</legend><div className="max-h-96 divide-y divide-border overflow-y-auto">{products.map((m) => <div key={m.id} className="flex flex-wrap items-center gap-3 py-3">
        <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-3"><Checkbox checked={Object.hasOwn(picked, m.id)} disabled={delivery && !Object.hasOwn(picked, m.id) && !requirements.some((l) => l.item_type_id === m.item_type_id && selectedCount(l.zone_id, l.item_type_id) < l.quantity)} onCheckedChange={(on) => setPicked((old) => { const next = { ...old }; if (on) next[m.id] = delivery ? requirements.find((l) => l.item_type_id === m.item_type_id && selectedCount(l.zone_id, l.item_type_id) < l.quantity)?.zone_id ?? 0 : m.zone_id ?? 0; else delete next[m.id]; return next })} /><span><span className="flex flex-wrap items-center gap-x-3 gap-y-1"><span className="font-mono text-sm">{m.sku}</span>{!delivery && <SwapDue date={m.swap_due_date} deliveredOn={m.delivered_on} />}</span><span className="block text-xs text-muted-foreground">{m.catalog_sku_code || m.type_name} · {m.type_name}</span></span></label>
        {delivery && Object.hasOwn(picked, m.id) && <Select required aria-label={`Delivery zone for ${m.sku}`} value={picked[m.id] || ''} onChange={(e) => setPicked((old) => ({ ...old, [m.id]: Number(e.target.value) }))}><option value="">Choose zone</option>{requirements.filter((l) => l.item_type_id === m.item_type_id).map((l) => <option key={l.id} value={l.zone_id} disabled={picked[m.id] !== l.zone_id && selectedCount(l.zone_id, l.item_type_id) >= l.quantity}>{l.zone_name}</option>)}</Select>}
        {!delivery && <span className="text-xs text-muted-foreground">{zones.data?.find((z) => z.id === m.zone_id)?.name}</span>}
      </div>)}</div></fieldset> : <Empty>{delivery ? 'No unreserved ready mats match this location’s active contract. Map stock in Registration, release repaired mats above, or wait for inventory.' : 'No available delivered mats for this customer and location.'}</Empty>}</Load> : <p className="text-sm text-muted-foreground">Choose customer and location to see products.</p>}
      {save.error && <Alert tone="danger">{save.error}</Alert>}
      <Button type="submit" variant="solid" disabled={save.busy || !Object.keys(picked).length || !matchesContract || (!delivery && !returnReason) || available.loading || zones.loading}>{save.busy ? 'Creating order' : `Create ${delivery ? 'delivery' : 'pickup'} order`}</Button>
    </form>
    <h2 className="mt-8 mb-3 text-xl font-medium">{delivery ? 'Delivery' : 'Pickup'} orders</h2>
    <Load state={orders}>{(rows) => rows.length ? <div className="space-y-3">{rows.map((order) => <details key={order.id} id={`order-${order.id}`} open={params.get('order') === String(order.id) || undefined} className="card-chai scroll-mt-8 p-4"><summary className="cursor-pointer text-sm font-medium">{order.number} · {order.customer_name} · {ORDER_STATUS[order.status]}{order.task_id ? ` · Task #${order.task_id}` : ''}</summary><div className="mt-3 space-y-3"><div className="flex justify-end"><Button variant="ghost" size="sm" onClick={() => { remove.clear(); setRemoving(order) }}>Remove</Button></div><OrderSummary order={order} /><OrderProducts order={order} stage={order.status === 'pending_qc' ? 'qc' : 'scan'} /></div></details>)}</div> : <Empty>No {type} orders yet.</Empty>}</Load>
    <ConfirmDialog open={removing !== null} title={`Remove ${removing?.number ?? 'this order'}?`} confirmLabel="Remove" busyLabel="Removing" busy={remove.busy} error={remove.error} onCancel={() => { setRemoving(null); remove.clear() }} onConfirm={() => void remove.run(async () => { await api.del(`/orders/${removing!.id}`); setRemoving(null); await orders.reload() })}>
      An order that has not been checked or scanned can be removed. One that has already started stays on record.
    </ConfirmDialog>
    <Dialog open={Boolean(created)} onClose={() => setCreated(null)} title={`Order ${created?.number ?? ''} created`} description={created ? `${created.assigned_name} is assigned. ${delivery ? `${serviceSchedule(created.contract_start_date, created.swap_interval_days)}${created.next_swap_date ? ` · Next swap ${day(created.next_swap_date)}` : ''}. Inventory must quality-check and scan the order out, then the driver scans receipt in Load the van.` : `The driver will pick up these exact mats and take them to ${created.destination === 'washing' ? created.washing_name : 'inventory'}.`}` : ''}><DialogActions><Button onClick={() => setCreated(null)}>Done</Button></DialogActions></Dialog>
  </>
}
