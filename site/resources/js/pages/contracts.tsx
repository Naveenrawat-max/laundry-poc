/** Contracts: how many mats of which type lie in which zone, and after how many days they are swapped. */
import { Plus, X } from 'lucide-react'
import { useEffect, useState } from 'react'

import { Count, Empty, FormDialog, Load, PageHead, Toolbar } from '@/app/ui'
import { Button } from '@/components/ui/button'
import { Chip } from '@/components/ui/chip'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Field } from '@/components/ui/field'
import { IconButton } from '@/components/ui/icon-button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { useToast } from '@/components/ui/toast'
import { useAction, useLoad } from '@/lib/hooks'
import { day, today } from '@/lib/labels'
import { useSession } from '@/lib/session'
import type { Building, Category, Contract, Customer, ItemType, Zone } from '@/lib/types'

type Line = { zone_id: number | ''; item_type_id: number | ''; quantity: number }
type Draft = { id?: number; status: Contract['status']; customer_id: number | ''; building_id: number | ''; swap_interval_days: number; start_date: string; lines: Line[] }

const TONE = { draft: 'default', active: 'success', ended: 'default' } as const
const WORD = { draft: 'Draft', active: 'Active', ended: 'Ended' } as const

export function ContractsPage() {
  const { api } = useSession()
  const toast = useToast()
  const contracts = useLoad(() => api.get<{ rows: Contract[] }>('/contracts').then((r) => r.rows), [api])
  const customers = useLoad(() => api.get<{ rows: Customer[] }>('/customers').then((r) => r.rows), [api])
  const types = useLoad(() => api.get<{ rows: ItemType[] }>('/item-types').then((r) => r.rows), [api])
  const categories = useLoad(() => api.get<{ rows: Category[] }>('/categories').then((r) => r.rows), [api])

  const [draft, setDraft] = useState<Draft | null>(null)
  const [confirm, setConfirm] = useState<{ what: 'activate' | 'end' | 'remove'; contract: Contract } | null>(null)
  const save = useAction()
  const act = useAction()
  const set = (patch: Partial<Draft>) => setDraft((d) => (d ? { ...d, ...patch } : d))
  const setLine = (i: number, patch: Partial<Line>) => set({ lines: draft!.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)) })

  const locations = useLoad(() => draft?.customer_id ? api.get<{ rows: Building[] }>(`/customers/${draft.customer_id}/buildings`).then((r) => r.rows.filter((b) => b.is_active)) : Promise.resolve([]), [api, draft?.customer_id])
  const zones = useLoad(
    async () => {
      const buildingId = draft?.building_id ?? ''
      const rows = buildingId ? (await api.get<{ rows: Zone[] }>(`/buildings/${buildingId}/zones`)).rows.filter((z) => z.is_active) : []
      return { buildingId, rows }
    },
    [api, draft?.building_id],
  )
  const currentZones = zones.data && zones.data.buildingId === draft?.building_id ? zones.data.rows : []
  useEffect(() => {
    if (!draft?.building_id || zones.loading || zones.error || zones.data?.buildingId !== draft.building_id || zones.data.rows.length !== 1) return
    const zoneId = zones.data.rows[0].id
    setDraft((d) => d && d.building_id === zones.data?.buildingId && d.lines.some((l) => !l.zone_id) ? { ...d, lines: d.lines.map((l) => l.zone_id ? l : { ...l, zone_id: zoneId }) } : d)
  }, [draft?.building_id, zones.data, zones.loading, zones.error])

  const isDraft = !draft?.id || draft.status === 'draft'

  const submit = () =>
    save.run(async () => {
      const d = draft!
      if (isDraft && !d.building_id) throw new Error('Choose a customer location for this contract.')
      const lines = d.lines.map((l) => ({ zone_id: Number(l.zone_id), item_type_id: Number(l.item_type_id), quantity: Number(l.quantity) }))
      if (!d.id) {
        await api.post('/contracts', { customer_id: Number(d.customer_id), building_id: Number(d.building_id), swap_interval_days: Number(d.swap_interval_days), start_date: d.start_date, lines })
      } else if (d.status === 'draft') {
        await api.patch(`/contracts/${d.id}`, { building_id: Number(d.building_id), swap_interval_days: Number(d.swap_interval_days), start_date: d.start_date, lines })
      } else {
        await api.patch(`/contracts/${d.id}`, { swap_interval_days: Number(d.swap_interval_days) })
      }
      setDraft(null)
      await contracts.reload()
    })

  const runConfirm = () =>
    act.run(async () => {
      const { what, contract } = confirm!
      if (what === 'remove') {
        await api.del(`/contracts/${contract.id}`)
        toast('Contract removed.', { tone: 'success' })
      } else {
        const out = await api.post<{ task_ids?: number[] }>(`/contracts/${contract.id}/${what}`)
        toast(what === 'activate' ? `Contract started. ${out.task_ids?.length ?? 0} delivery tasks are waiting for a driver.` : 'Contract ended.', { tone: 'success' })
      }
      setConfirm(null)
      await contracts.reload()
    })

  return (
    <>
      <PageHead title="Contracts">
        A contract says which mats lie where, and <span className="highlight">how often they are swapped</span>. Starting it creates the first deliveries.
      </PageHead>

      <Toolbar info={<Count n={contracts.data?.length ?? 0} noun="contract" />}>
        <Button
          variant="solid"
          iconRight={null}
          onClick={() => { save.clear(); setDraft({ status: 'draft', customer_id: '', building_id: '', swap_interval_days: 14, start_date: today(), lines: [{ zone_id: '', item_type_id: '', quantity: 1 }] }) }}
        >
          New contract
        </Button>
      </Toolbar>

      <Load state={contracts}>
        {(rows) =>
          rows.length === 0 ? (
            <Empty>No contracts yet.</Empty>
          ) : (
            <ul className="grid gap-4 md:grid-cols-2">
              {rows.map((c) => (
                <li key={c.id} className="card-chai flex flex-col p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <h2 className="truncate font-montserrat text-base font-semibold">{c.customer_name} · {c.building_name ?? 'Legacy multiple locations'}</h2>
                      <p className="text-sm text-muted-foreground">
                        From {day(c.start_date)} the mats will be swapped · D+{c.swap_interval_days} (every {c.swap_interval_days} days after a delivery or swap)
                        {c.end_date ? `, ended ${day(c.end_date)}` : ''}.
                      </p>
                      {c.next_swap_date && <p className="text-sm text-muted-foreground">Next swap {day(c.next_swap_date)}</p>}
                    </div>
                    <Chip tone={TONE[c.status]}>{WORD[c.status]}</Chip>
                  </div>
                  <ul className="mt-3 space-y-1 text-sm">
                    {c.lines.map((l) => (
                      <li key={l.id} className="flex justify-between gap-3">
                        <span className="min-w-0 truncate text-muted-foreground">
                          {l.building_name}, {l.zone_name}
                        </span>
                        <span className="shrink-0">
                          {l.quantity} × {l.type_name}
                        </span>
                      </li>
                    ))}
                    {c.lines.length === 0 && <li className="text-muted-foreground">No mats on this contract yet.</li>}
                  </ul>
                  <div className="mt-auto flex flex-wrap justify-end gap-2 pt-4">
                    {c.status !== 'ended' && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                          setDraft({ id: c.id, status: c.status, customer_id: c.customer_id, building_id: c.building_id ?? '', swap_interval_days: c.swap_interval_days, start_date: c.start_date, lines: c.lines.map((l) => ({ zone_id: l.zone_id, item_type_id: l.item_type_id, quantity: l.quantity })) })
                        }
                      >
                        Edit
                      </Button>
                    )}
                    {c.status === 'draft' && (
                      <Button size="sm" onClick={() => setConfirm({ what: 'activate', contract: c })}>
                        Start contract
                      </Button>
                    )}
                    {c.status === 'active' && (
                      <Button variant="ghost" size="sm" onClick={() => setConfirm({ what: 'end', contract: c })}>
                        End contract
                      </Button>
                    )}
                    {c.status !== 'ended' && (
                      <Button variant="ghost" size="sm" onClick={() => { act.clear(); setConfirm({ what: 'remove', contract: c }) }}>
                        Remove
                      </Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )
        }
      </Load>

      <FormDialog
        open={draft !== null}
        title={draft?.id ? 'Edit contract' : 'New contract'}
        description={isDraft ? 'It stays a draft until you start it.' : 'This contract has started. Only the days between swaps can still change; open swap tasks move with it.'}
        busy={save.busy}
        error={save.error}
        onSubmit={() => void submit()}
        onClose={() => {
          setDraft(null)
          save.clear()
        }}
        className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl"
      >
        {!draft?.id && (
          <Field label="Customer">
            <Select value={draft?.customer_id ?? ''} onChange={(e) => set({ customer_id: Number(e.target.value), building_id: '', lines: [{ zone_id: '', item_type_id: '', quantity: 1 }] })} wrapperClassName="w-full" required>
              <option value="">Choose customer</option>
              {customers.data?.filter((c) => c.is_active).map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          </Field>
        )}
        {isDraft ? <Load state={locations}>{(rows) => <Field label="Location" hint={!draft?.customer_id ? 'Choose the customer first.' : !rows.some((b) => b.customer_id === draft.customer_id) ? 'Add a location under Customers first.' : 'One active contract per location.'}><Select required disabled={!draft?.customer_id || save.busy} value={draft?.building_id ?? ''} onChange={(e) => set({ building_id: Number(e.target.value), lines: [{ zone_id: '', item_type_id: '', quantity: 1 }] })} wrapperClassName="w-full"><option value="">Choose location</option>{rows.filter((b) => b.customer_id === draft?.customer_id).map((b) => <option key={b.id} value={b.id}>{b.name} · {b.street}, {b.city}</option>)}</Select></Field>}</Load> : <p className="text-sm">{customers.data?.find((c) => c.id === draft?.customer_id)?.name} · {contracts.data?.find((c) => c.id === draft?.id)?.building_name ?? 'Legacy multiple locations'}</p>}
        {isDraft && draft?.id && !draft.building_id && <p className="text-sm text-muted-foreground">Legacy multiple locations: choose one location to replace this draft’s lines on save. Existing contract stays unchanged until saved.</p>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="D+n · days after delivery / swap">
            <Input type="number" min={1} max={365} value={draft?.swap_interval_days ?? 14} onChange={(e) => set({ swap_interval_days: Number(e.target.value) })} required />
          </Field>
          <Field label="Start date" hint="The first delivery is due on this day.">
            <Input type="date" value={draft?.start_date ?? ''} onChange={(e) => set({ start_date: e.target.value })} disabled={!isDraft} required />
          </Field>
        </div>

        {isDraft && (
          <fieldset className="space-y-2">
            <legend className="mb-2 text-sm font-medium">Mats per zone</legend>
            {!draft?.building_id && <p className="text-sm text-muted-foreground">Choose a location first.</p>}
            {draft?.building_id && currentZones.length === 0 && !zones.loading && <p className="text-sm text-muted-foreground">No active area or zone for this location. Update its zones under Customers.</p>}
            {zones.error && <p className="text-sm text-red-700 dark:text-red-400">{zones.error}</p>}
            {draft?.lines.map((line, i) => (
              <div key={i} className="grid grid-cols-[1fr_1fr_4.5rem_auto] items-center gap-2">
                <Select aria-label="Location area / zone" value={line.zone_id} disabled={!draft.building_id || zones.loading} onChange={(e) => setLine(i, { zone_id: Number(e.target.value) })} wrapperClassName="w-full" required>
                  <option value="" disabled>
                    Zone
                  </option>
                  {currentZones.map((z) => (
                    <option key={z.id} value={z.id}>
                      {z.name}
                    </option>
                  ))}
                </Select>
                <Select aria-label="Mat type" value={line.item_type_id} onChange={(e) => setLine(i, { item_type_id: Number(e.target.value) })} wrapperClassName="w-full" required>
                  <option value="" disabled>
                    Mat type
                  </option>
                  {types.data?.filter((t) => t.is_active && categories.data?.some((c) => c.id === t.category_id && c.is_active)).map((t) => (
                    <option key={t.id} value={t.id}>
                      {categories.data?.find((c) => c.id === t.category_id)?.name} → {t.name}
                    </option>
                  ))}
                </Select>
                <Input aria-label="Quantity" type="number" min={1} max={1000} value={line.quantity} onChange={(e) => setLine(i, { quantity: Number(e.target.value) })} required />
                <IconButton variant="ghost" aria-label="Remove this line" type="button" onClick={() => set({ lines: draft.lines.filter((_, j) => j !== i) })}>
                  <X className="size-4" />
                </IconButton>
              </div>
            ))}
            <Button type="button" variant="ghost" size="sm" disabled={!draft?.building_id} onClick={() => set({ lines: [...draft!.lines, { zone_id: currentZones.length === 1 ? currentZones[0].id : '', item_type_id: '', quantity: 1 }] })}>
              <Plus /> Add a line
            </Button>
          </fieldset>
        )}
      </FormDialog>

      <ConfirmDialog
        open={confirm?.what === 'activate'}
        tone="neutral"
        title="Start this contract?"
        confirmLabel="Start contract"
        busyLabel="Starting"
        cancelLabel="Not yet"
        busy={act.busy}
        error={act.error}
        onConfirm={() => void runConfirm()}
        onCancel={() => {
          setConfirm(null)
          act.clear()
        }}
      >
        Delivery is scheduled for {confirm?.contract.customer_name} · {confirm?.contract.building_name ?? 'legacy contract locations'}, due on {day(confirm?.contract.start_date)}. After this the location, mats per zone and the start date
        can no longer be changed.
      </ConfirmDialog>

      <ConfirmDialog
        open={confirm?.what === 'remove'}
        title="Remove this contract?"
        confirmLabel="Remove"
        busyLabel="Removing"
        cancelLabel="Keep it"
        busy={act.busy}
        error={act.error}
        onConfirm={() => void runConfirm()}
        onCancel={() => {
          setConfirm(null)
          act.clear()
        }}
      >
        A draft can be removed. An active contract has to be ended first. An ended contract stays on record.
      </ConfirmDialog>

      <ConfirmDialog
        open={confirm?.what === 'end'}
        title="End this contract?"
        confirmLabel="End contract"
        busyLabel="Ending"
        cancelLabel="Keep it"
        busy={act.busy}
        error={act.error}
        onConfirm={() => void runConfirm()}
        onCancel={() => {
          setConfirm(null)
          act.clear()
        }}
      >
        The contract with {confirm?.contract.customer_name} ends today and its open swap tasks are cancelled. This cannot be undone. Mats still at the customer stay there until you
        create a collection task.
      </ConfirmDialog>
    </>
  )
}
