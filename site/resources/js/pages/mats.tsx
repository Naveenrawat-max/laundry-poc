/** The mat list: every mat, where it is now, and behind each row everything that ever happened to it. */
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'

import { Empty, Fact, FormDialog, Load, PageHead, StatusChip, Toolbar } from '@/app/ui'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Drawer, DrawerContent } from '@/components/ui/drawer'
import { Field } from '@/components/ui/field'
import { Pagination } from '@/components/ui/pagination'
import { SearchInput } from '@/components/ui/search-input'
import { Select } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { useAction, useLoad } from '@/lib/hooks'
import { EVENT, HELD, STATUS, STATUSES, day, when } from '@/lib/labels'
import { useSession } from '@/lib/session'
import type { Building, Customer, ItemType, MatEvent, OfficeMat, Status, User, Zone } from '@/lib/types'

type Page = { items: OfficeMat[]; page: number; per_page: number; total: number }

function where(mat: OfficeMat): string {
  if (mat.holder) return `With ${mat.holder.name}`
  if (mat.location) return `${mat.location.customer}, ${mat.location.building}, ${mat.location.zone}`
  if (mat.status === 'in_washing') return 'Washing house'
  if (mat.status === 'scrapped') return 'Gone'
  return 'Inventory'
}

export function MatsPage() {
  const { api } = useSession()
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? ''
  const type = params.get('type') ?? ''
  const page = Number(params.get('page') ?? 1)
  const [q, setQ] = useState(params.get('q') ?? '')

  const change = (patch: Record<string, string>) => {
    const next = new URLSearchParams(params)
    for (const [key, value] of Object.entries(patch)) value ? next.set(key, value) : next.delete(key)
    if (!('page' in patch)) next.delete('page')
    setParams(next, { replace: true })
  }

  // The search waits until typing stops, so every letter is not its own request.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (q !== (params.get('q') ?? '')) change({ q })
    }, 300)
    return () => window.clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q])

  const mats = useLoad(() => api.get<Page>(`/items?${params.toString()}`), [api, params.toString()])
  const types = useLoad(() => api.get<{ rows: ItemType[] }>('/item-types').then((r) => r.rows), [api])
  const [openId, setOpenId] = useState<number | null>(null)

  return (
    <>
      <PageHead title="Mats">
        Every mat, where it is now, and <span className="highlight">every scan it ever had</span>. Open a row to see its history.
      </PageHead>

      <Toolbar
        info={
          mats.data && (
            <>
              <span className="font-semibold text-gray-900 dark:text-gray-100">{mats.data.total}</span> {mats.data.total === 1 ? 'mat' : 'mats'}
            </>
          )
        }
      >
        <SearchInput aria-label="Search by SKU or tag id" placeholder="SKU or tag id" value={q} onChange={(e) => setQ(e.target.value)} />
        <Select aria-label="Status" value={status} onChange={(e) => change({ status: e.target.value })}>
          <option value="">Every status</option>
          {STATUSES.map((s) => (
            <option key={s} value={s}>
              {STATUS[s]}
            </option>
          ))}
        </Select>
        <Select aria-label="Mat type" value={type} onChange={(e) => change({ type: e.target.value })}>
          <option value="">Every type</option>
          {types.data?.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name}
            </option>
          ))}
        </Select>
      </Toolbar>

      <Load state={mats} rows={8}>
        {(data) =>
          data.items.length === 0 ? (
            <Empty>No mat matches. Mats are registered on the inventory manager's handheld.</Empty>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>SKU</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Where</TableHead>
                    <TableHead className="text-right">Washes</TableHead>
                    <TableHead>Last wash</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.items.map((m) => (
                    <TableRow key={m.id} className="cursor-pointer" onClick={() => setOpenId(m.id)}>
                      <TableCell>
                        <button type="button" className="cursor-pointer font-mono text-xs font-medium whitespace-nowrap outline-none hover:text-brand focus-visible:underline" onClick={() => setOpenId(m.id)}>
                          {m.sku}
                        </button>
                      </TableCell>
                      <TableCell>{m.type}</TableCell>
                      <TableCell>
                        <StatusChip status={m.status} />
                      </TableCell>
                      <TableCell className="max-w-64 truncate">{where(m)}</TableCell>
                      <TableCell className="text-right tabular-nums">{m.wash_count}</TableCell>
                      <TableCell className="whitespace-nowrap">{day(m.current_wash_date) || <span className="text-muted-foreground">Never</span>}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <Pagination className="mt-4 justify-center" page={data.page} total={Math.ceil(data.total / data.per_page)} onChange={(p) => change({ page: String(p) })} />
            </>
          )
        }
      </Load>

      <MatDrawer id={openId} onClose={() => setOpenId(null)} onChanged={() => void mats.reload()} />
    </>
  )
}

/** One mat: its tags, its history, and the office's correction. */
function MatDrawer({ id, onClose, onChanged }: { id: number | null; onClose: () => void; onChanged: () => void }) {
  const { api } = useSession()
  const state = useLoad(() => (id === null ? Promise.resolve(null) : api.get<{ item: OfficeMat; events: MatEvent[] }>(`/items/${id}`)), [api, id])
  const [correcting, setCorrecting] = useState(false)
  const mat = state.data?.item

  return (
    <>
      <Drawer open={id !== null && !correcting} onOpenChange={(open) => !open && onClose()}>
        <DrawerContent title={mat?.sku ?? 'Mat'} description={mat ? `${mat.type}. ${where(mat)}.` : undefined} className="w-[min(30rem,95vw)]">
          {state.error && <Alert tone="danger">{state.error}</Alert>}
          {mat && state.data && (
            <>
              <div className="flex items-center justify-between gap-3">
                <StatusChip status={mat.status} />
                <Button size="sm" onClick={() => setCorrecting(true)}>
                  Correct status
                </Button>
              </div>
              <dl className="grid grid-cols-2 gap-3">
                <Fact label="Washes">{String(mat.wash_count)}</Fact>
                <Fact label="Last wash">{day(mat.current_wash_date)}</Fact>
                <Fact label="Wash before that">{day(mat.previous_wash_date)}</Fact>
                <Fact label="Tag id (EPC)">
                  <span className="font-mono text-xs">{mat.epc}</span>
                </Fact>
                <Fact label="First tag (TID)">
                  <span className="font-mono text-xs">{mat.tid_primary}</span>
                </Fact>
                <Fact label="Second tag (TID)">
                  <span className="font-mono text-xs">{mat.tid_secondary}</span>
                </Fact>
              </dl>

              <h3 className="mt-2 font-montserrat text-sm font-semibold">History</h3>
              {state.data.events.length === 0 && <p className="text-sm text-muted-foreground">Nothing has happened to this mat yet.</p>}
              <ol className="space-y-3 border-l border-border pl-4">
                {[...state.data.events].reverse().map((e) => (
                  <li key={e.id} className="relative text-sm">
                    <span className="absolute top-1.5 -left-[1.3rem] size-1.5 rounded-full bg-muted-foreground" aria-hidden="true" />
                    <p className="font-medium">
                      {EVENT[e.event_type] ?? e.event_type}
                      {e.from_status !== e.to_status && <span className="font-normal text-muted-foreground">: {STATUS[e.to_status]}</span>}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {when(e.created_at)} by {e.user_name}
                      {e.zone_name ? `, ${e.zone_name}` : ''}
                      {e.source === 'simulator' ? ', simulated scan' : ''}
                    </p>
                    {e.note && <p className="mt-0.5 text-xs">Note: {e.note}</p>}
                  </li>
                ))}
              </ol>
            </>
          )}
        </DrawerContent>
      </Drawer>
      {/* Outside the drawer, and the drawer steps aside while this is open: a drawer keeps the pointer and the keyboard to itself. */}
      <CorrectDialog
        mat={correcting ? (mat ?? null) : null}
        onClose={() => setCorrecting(false)}
        onDone={() => {
          setCorrecting(false)
          void state.reload()
          onChanged()
        }}
      />
    </>
  )
}

/** The office may put a mat into any status, but only with a note. The note lands in the mat's history. */
function CorrectDialog({ mat, onClose, onDone }: { mat: OfficeMat | null; onClose: () => void; onDone: () => void }) {
  const { api } = useSession()
  const [status, setStatus] = useState<Status>('ready')
  const [holder, setHolder] = useState('')
  const [customerId, setCustomerId] = useState('')
  const [buildingId, setBuildingId] = useState('')
  const [zoneId, setZoneId] = useState('')
  const [note, setNote] = useState('')
  const save = useAction()

  useEffect(() => {
    if (!mat) return
    setStatus(mat.status)
    setHolder(mat.holder ? String(mat.holder.id) : '')
    setZoneId(mat.location ? String(mat.location.zone_id) : '')
    setCustomerId('')
    setBuildingId('')
    setNote('')
    save.clear()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mat?.id])

  const held = HELD.includes(status)
  const atCustomer = status === 'at_customer'
  const open = mat !== null
  const drivers = useLoad(() => (open && held ? api.get<{ rows: User[] }>('/users').then((r) => r.rows.filter((u) => u.role === 'driver' && u.is_active)) : Promise.resolve([])), [api, open, held])
  const customers = useLoad(() => (open && atCustomer ? api.get<{ rows: Customer[] }>('/customers').then((r) => r.rows) : Promise.resolve([])), [api, open, atCustomer])
  const buildings = useLoad(() => (customerId ? api.get<{ rows: Building[] }>(`/customers/${customerId}/buildings`).then((r) => r.rows) : Promise.resolve([])), [api, customerId])
  const zones = useLoad(() => (buildingId ? api.get<{ rows: Zone[] }>(`/buildings/${buildingId}/zones`).then((r) => r.rows) : Promise.resolve([])), [api, buildingId])

  const submit = () =>
    save.run(async () => {
      await api.post(`/items/${mat!.id}/correct`, { status, holder_user_id: held && holder ? Number(holder) : null, zone_id: atCustomer && zoneId ? Number(zoneId) : null, note })
      onDone()
    })

  return (
    <FormDialog open={open} title={`Correct ${mat?.sku ?? ''}`} description="Use this when the system and the real world disagree. Your name and the note are saved in the mat's history." submitLabel="Save correction" busy={save.busy} error={save.error} onSubmit={() => void submit()} onClose={onClose}>
      <Field label="New status">
        <Select value={status} onChange={(e) => setStatus(e.target.value as Status)} wrapperClassName="w-full">
          {STATUSES.map((s) => (
            <option key={s} value={s}>
              {STATUS[s]}
            </option>
          ))}
        </Select>
      </Field>
      {held && (
        <Field label="Which driver carries it">
          <Select value={holder} onChange={(e) => setHolder(e.target.value)} wrapperClassName="w-full" required>
            <option value="" disabled>
              Choose a driver
            </option>
            {drivers.data?.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
      )}
      {atCustomer && (
        <>
          {mat?.location && !customerId && (
            <p className="text-sm text-muted-foreground">
              Stays at {mat.location.customer}, {mat.location.building}, {mat.location.zone}, unless you pick another spot.
            </p>
          )}
          <div className="grid gap-3 sm:grid-cols-3">
            <Field label="Customer">
              <Select
                value={customerId}
                onChange={(e) => {
                  setCustomerId(e.target.value)
                  setBuildingId('')
                  setZoneId('')
                }}
                wrapperClassName="w-full"
              >
                <option value="">Choose</option>
                {customers.data?.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Building">
              <Select
                value={buildingId}
                onChange={(e) => {
                  setBuildingId(e.target.value)
                  setZoneId('')
                }}
                wrapperClassName="w-full"
                disabled={!customerId}
              >
                <option value="">Choose</option>
                {buildings.data?.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Zone">
              <Select value={buildingId ? zoneId : ''} onChange={(e) => setZoneId(e.target.value)} wrapperClassName="w-full" disabled={!buildingId} required={!zoneId}>
                <option value="">Choose</option>
                {zones.data?.map((z) => (
                  <option key={z.id} value={z.id}>
                    {z.name}
                  </option>
                ))}
              </Select>
            </Field>
          </div>
        </>
      )}
      <Field label="Why" hint="Required. Say what really happened.">
        <Textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} required rows={3} />
      </Field>
    </FormDialog>
  )
}
