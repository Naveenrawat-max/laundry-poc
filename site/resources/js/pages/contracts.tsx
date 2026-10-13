/** Contracts: how many mats of which type lie in which zone, and after how many days they are swapped. */
import { Plus, X } from 'lucide-react'
import { useState } from 'react'

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
import type { Building, Contract, Customer, ItemType, Zone } from '@/lib/types'

type Line = { zone_id: number | ''; item_type_id: number | ''; quantity: number }
type Draft = { id?: number; status: Contract['status']; customer_id: number | ''; swap_interval_days: number; start_date: string; lines: Line[] }
type ZoneOption = { id: number; label: string }

const TONE = { draft: 'default', active: 'success', ended: 'default' } as const
const WORD = { draft: 'Draft', active: 'Active', ended: 'Ended' } as const

export function ContractsPage() {
  const { api } = useSession()
  const toast = useToast()
  const contracts = useLoad(() => api.get<{ rows: Contract[] }>('/contracts').then((r) => r.rows), [api])
  const customers = useLoad(() => api.get<{ rows: Customer[] }>('/customers').then((r) => r.rows), [api])
  const types = useLoad(() => api.get<{ rows: ItemType[] }>('/item-types').then((r) => r.rows), [api])

  const [draft, setDraft] = useState<Draft | null>(null)
  const [confirm, setConfirm] = useState<{ what: 'activate' | 'end'; contract: Contract } | null>(null)
  const save = useAction()
  const act = useAction()
  const set = (patch: Partial<Draft>) => setDraft((d) => (d ? { ...d, ...patch } : d))
  const setLine = (i: number, patch: Partial<Line>) => set({ lines: draft!.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)) })

  // The zones a line may point at: every zone of every building of the chosen customer.
  const zones = useLoad<ZoneOption[]>(
    async () => {
      if (!draft?.customer_id) return []
      const buildings = (await api.get<{ rows: Building[] }>(`/customers/${draft.customer_id}/buildings`)).rows
      const perBuilding = await Promise.all(
        buildings.map(async (b) => (await api.get<{ rows: Zone[] }>(`/buildings/${b.id}/zones`)).rows.map((z) => ({ id: z.id, label: `${b.name}, ${z.name}` }))),
      )
      return perBuilding.flat()
    },
    [api, draft?.customer_id],
  )

  const isDraft = !draft?.id || draft.status === 'draft'

  const submit = () =>
    save.run(async () => {
      const d = draft!
      const lines = d.lines.map((l) => ({ zone_id: Number(l.zone_id), item_type_id: Number(l.item_type_id), quantity: Number(l.quantity) }))
      if (!d.id) {
        await api.post('/contracts', { customer_id: Number(d.customer_id), swap_interval_days: Number(d.swap_interval_days), start_date: d.start_date, lines })
      } else if (d.status === 'draft') {
        await api.patch(`/contracts/${d.id}`, { swap_interval_days: Number(d.swap_interval_days), start_date: d.start_date, lines })
      } else {
        await api.patch(`/contracts/${d.id}`, { swap_interval_days: Number(d.swap_interval_days) })
      }
      setDraft(null)
      await contracts.reload()
    })

  const runConfirm = () =>
    act.run(async () => {
      const { what, contract } = confirm!
      const out = await api.post<{ task_ids?: number[] }>(`/contracts/${contract.id}/${what}`)
      toast(what === 'activate' ? `Contract started. ${out.task_ids?.length ?? 0} delivery tasks are waiting for a driver.` : 'Contract ended.', { tone: 'success' })
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
          onClick={() => setDraft({ status: 'draft', customer_id: customers.data?.[0]?.id ?? '', swap_interval_days: 14, start_date: today(), lines: [{ zone_id: '', item_type_id: '', quantity: 1 }] })}
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
                      <h2 className="truncate font-montserrat text-base font-semibold">{c.customer_name}</h2>
                      <p className="text-sm text-muted-foreground">
                        Swap every {c.swap_interval_days} days. Starts {day(c.start_date)}
                        {c.end_date ? `, ended ${day(c.end_date)}` : ''}.
                      </p>
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
                          setDraft({ id: c.id, status: c.status, customer_id: c.customer_id, swap_interval_days: c.swap_interval_days, start_date: c.start_date, lines: c.lines.map((l) => ({ zone_id: l.zone_id, item_type_id: l.item_type_id, quantity: l.quantity })) })
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
        className="sm:max-w-2xl"
      >
        {!draft?.id && (
          <Field label="Customer">
            <Select value={draft?.customer_id ?? ''} onChange={(e) => set({ customer_id: Number(e.target.value), lines: [{ zone_id: '', item_type_id: '', quantity: 1 }] })} wrapperClassName="w-full" required>
              {customers.data?.filter((c) => c.is_active).map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          </Field>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Days between swaps">
            <Input type="number" min={1} max={365} value={draft?.swap_interval_days ?? 14} onChange={(e) => set({ swap_interval_days: Number(e.target.value) })} required />
          </Field>
          <Field label="Start date" hint="The first delivery is due on this day.">
            <Input type="date" value={draft?.start_date ?? ''} onChange={(e) => set({ start_date: e.target.value })} disabled={!isDraft} required />
          </Field>
        </div>

        {isDraft && (
          <fieldset className="space-y-2">
            <legend className="mb-2 text-sm font-medium">Mats per zone</legend>
            {zones.data?.length === 0 && !zones.loading && <p className="text-sm text-muted-foreground">This customer has no zones yet. Add a building and its zones under Customers first.</p>}
            {draft?.lines.map((line, i) => (
              <div key={i} className="grid grid-cols-[1fr_1fr_4.5rem_auto] items-center gap-2">
                <Select aria-label="Zone" value={line.zone_id} onChange={(e) => setLine(i, { zone_id: Number(e.target.value) })} wrapperClassName="w-full" required>
                  <option value="" disabled>
                    Zone
                  </option>
                  {zones.data?.map((z) => (
                    <option key={z.id} value={z.id}>
                      {z.label}
                    </option>
                  ))}
                </Select>
                <Select aria-label="Mat type" value={line.item_type_id} onChange={(e) => setLine(i, { item_type_id: Number(e.target.value) })} wrapperClassName="w-full" required>
                  <option value="" disabled>
                    Mat type
                  </option>
                  {types.data?.filter((t) => t.is_active).map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.name}
                    </option>
                  ))}
                </Select>
                <Input aria-label="Quantity" type="number" min={1} max={1000} value={line.quantity} onChange={(e) => setLine(i, { quantity: Number(e.target.value) })} required />
                <IconButton variant="ghost" aria-label="Remove this line" type="button" onClick={() => set({ lines: draft.lines.filter((_, j) => j !== i) })}>
                  <X className="size-4" />
                </IconButton>
              </div>
            ))}
            <Button type="button" variant="ghost" size="sm" onClick={() => set({ lines: [...draft!.lines, { zone_id: '', item_type_id: '', quantity: 1 }] })}>
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
        One delivery task is created for each building of {confirm?.contract.customer_name}, due on {day(confirm?.contract.start_date)}. After this the mats per zone and the start date
        can no longer be changed.
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
