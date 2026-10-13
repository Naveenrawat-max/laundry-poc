/** Customers with their buildings and zones. A building is one stop for the driver; a zone is the spot where a mat lies. */
import { useState } from 'react'

import { Empty, Fact, FormDialog, Load, PageHead, Toolbar } from '@/app/ui'
import { Button } from '@/components/ui/button'
import { Chip } from '@/components/ui/chip'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { useAction, useLoad } from '@/lib/hooks'
import { useSession } from '@/lib/session'
import type { Area, Building, Customer, Zone } from '@/lib/types'
import { cn } from '@/lib/utils'

type Dialog =
  | { kind: 'customer'; row: Partial<Customer> }
  | { kind: 'building'; row: Partial<Building> }
  | { kind: 'zone'; row: Partial<Zone>; buildingId: number }

function ActiveSwitch({ checked, onChange, what }: { checked: boolean; onChange: (v: boolean) => void; what: string }) {
  return (
    <label className="flex items-center gap-3 text-sm">
      <Switch checked={checked} onCheckedChange={onChange} />
      In use. Switch off when this {what} is no longer served.
    </label>
  )
}

export function CustomersPage() {
  const { api } = useSession()
  const customers = useLoad(() => api.get<{ rows: Customer[] }>('/customers').then((r) => r.rows), [api])
  const areas = useLoad(() => api.get<{ rows: Area[] }>('/areas').then((r) => r.rows), [api])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const selected = customers.data?.find((c) => c.id === selectedId) ?? customers.data?.[0] ?? null

  // The buildings of the chosen customer, each with its zones.
  const buildings = useLoad(
    async () => {
      if (!selected) return []
      const rows = (await api.get<{ rows: Building[] }>(`/customers/${selected.id}/buildings`)).rows
      return Promise.all(rows.map(async (b) => ({ ...b, zones: (await api.get<{ rows: Zone[] }>(`/buildings/${b.id}/zones`)).rows })))
    },
    [api, selected?.id],
  )

  const [dialog, setDialog] = useState<Dialog | null>(null)
  const save = useAction()
  const patch = (row: object) => setDialog((d) => (d ? ({ ...d, row: { ...d.row, ...row } } as Dialog) : d))

  const submit = () =>
    save.run(async () => {
      const d = dialog!
      if (d.kind === 'customer') {
        const { id, name, contact_name, contact_phone, contact_email, is_active } = d.row
        const body = { name, contact_name: contact_name || null, contact_phone: contact_phone || null, contact_email: contact_email || null, ...(id ? { is_active } : {}) }
        const out = id ? await api.patch<{ row: Customer }>(`/customers/${id}`, body) : await api.post<{ row: Customer }>('/customers', body)
        setSelectedId(out.row.id)
        await customers.reload()
      } else if (d.kind === 'building') {
        const { id, area_id, name, street, postcode, city, is_active } = d.row
        const body = { area_id: Number(area_id), name, street, postcode, city, ...(id ? { is_active } : {}) }
        if (id) await api.patch(`/buildings/${id}`, body)
        else await api.post(`/customers/${selected!.id}/buildings`, body)
        await buildings.reload()
      } else {
        const { id, name, is_active } = d.row
        if (id) await api.patch(`/zones/${id}`, { name, is_active })
        else await api.post(`/buildings/${d.buildingId}/zones`, { name })
        await buildings.reload()
      }
      setDialog(null)
    })

  const areaName = (id: number) => areas.data?.find((a) => a.id === id)?.name ?? ''

  return (
    <>
      <PageHead title="Customers">
        Each customer has buildings, and each building has <span className="highlight">the spots where mats lie</span>.
      </PageHead>

      <Toolbar info={`${customers.data?.length ?? 0} customers`}>
        <Button variant="solid" iconRight={null} onClick={() => setDialog({ kind: 'customer', row: { name: '', is_active: true } })}>
          Add customer
        </Button>
      </Toolbar>

      <Load state={customers}>
        {(rows) =>
          rows.length === 0 ? (
            <Empty>No customers yet. Add the first one to start.</Empty>
          ) : (
            <div className="grid gap-6 lg:grid-cols-[18rem_1fr]">
              <ul className="space-y-2">
                {rows.map((c) => (
                  <li key={c.id}>
                    <button
                      type="button"
                      onClick={() => setSelectedId(c.id)}
                      aria-current={c.id === selected?.id ? 'true' : undefined}
                      className={cn(
                        'card-chai flex w-full cursor-pointer items-center justify-between gap-2 p-3 text-left text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        c.id === selected?.id && 'border-card-edge-hover bg-black/[0.03] dark:bg-white/[0.04]',
                      )}
                    >
                      <span className="min-w-0">
                        <span className="block truncate font-medium">{c.name}</span>
                        <span className="block truncate text-xs text-muted-foreground">{c.contact_name || 'No contact person'}</span>
                      </span>
                      {!c.is_active && <Chip>Off</Chip>}
                    </button>
                  </li>
                ))}
              </ul>

              {selected && (
                <div className="min-w-0">
                  <div className="card-chai p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <h2 className="font-montserrat text-lg font-semibold">{selected.name}</h2>
                      <Button variant="ghost" size="sm" onClick={() => setDialog({ kind: 'customer', row: selected })}>
                        Edit customer
                      </Button>
                    </div>
                    <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                      <Fact label="Contact person">{selected.contact_name}</Fact>
                      <Fact label="Phone">{selected.contact_phone}</Fact>
                      <Fact label="Email">{selected.contact_email}</Fact>
                    </dl>
                  </div>

                  <div className="mt-6 mb-3 flex items-center justify-between gap-3">
                    <h3 className="text-xl font-medium">Buildings</h3>
                    <Button
                      size="sm"
                      disabled={!areas.data?.length}
                      onClick={() => setDialog({ kind: 'building', row: { name: '', street: '', postcode: '', city: '', area_id: areas.data?.[0]?.id, is_active: true } })}
                    >
                      Add building
                    </Button>
                  </div>
                  {areas.data?.length === 0 && <p className="mb-3 text-sm text-muted-foreground">Add a delivery area first, under Mat types and areas.</p>}

                  <Load state={buildings} rows={2}>
                    {(list) =>
                      list.length === 0 ? (
                        <Empty>No buildings yet. A building is one stop for the driver.</Empty>
                      ) : (
                        <ul className="space-y-3">
                          {list.map((b) => (
                            <li key={b.id} className="card-chai p-4">
                              <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                  <p className="flex items-center gap-2 font-montserrat font-semibold">
                                    {b.name}
                                    {!b.is_active && <Chip>Off</Chip>}
                                  </p>
                                  <p className="text-sm text-muted-foreground">
                                    {b.street}, {b.postcode} {b.city}. Area: {areaName(b.area_id)}
                                  </p>
                                </div>
                                <Button variant="ghost" size="sm" onClick={() => setDialog({ kind: 'building', row: b })}>
                                  Edit
                                </Button>
                              </div>
                              <div className="mt-3 flex flex-wrap items-center gap-2">
                                {b.zones.map((z) => (
                                  <button
                                    key={z.id}
                                    type="button"
                                    onClick={() => setDialog({ kind: 'zone', row: z, buildingId: b.id })}
                                    className={cn(
                                      'cursor-pointer rounded border border-gray-200 px-2 py-1 text-xs transition-colors outline-none hover:text-brand focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:border-gray-700',
                                      !z.is_active && 'text-muted-foreground line-through',
                                    )}
                                  >
                                    {z.name}
                                  </button>
                                ))}
                                <Button variant="ghost" size="sm" onClick={() => setDialog({ kind: 'zone', row: { name: '', is_active: true }, buildingId: b.id })}>
                                  Add zone
                                </Button>
                              </div>
                            </li>
                          ))}
                        </ul>
                      )
                    }
                  </Load>
                </div>
              )}
            </div>
          )
        }
      </Load>

      <FormDialog
        open={dialog !== null}
        title={dialog ? `${dialog.row.id ? 'Edit' : 'Add'} ${dialog.kind}` : ''}
        busy={save.busy}
        error={save.error}
        onSubmit={() => void submit()}
        onClose={() => {
          setDialog(null)
          save.clear()
        }}
      >
        {dialog?.kind === 'customer' && (
          <>
            <Field label="Name">
              <Input value={dialog.row.name ?? ''} onChange={(e) => patch({ name: e.target.value })} required autoFocus />
            </Field>
            <Field label="Contact person" aside="Optional">
              <Input value={dialog.row.contact_name ?? ''} onChange={(e) => patch({ contact_name: e.target.value })} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Phone" aside="Optional">
                <Input type="tel" value={dialog.row.contact_phone ?? ''} onChange={(e) => patch({ contact_phone: e.target.value })} />
              </Field>
              <Field label="Email" aside="Optional">
                <Input type="email" value={dialog.row.contact_email ?? ''} onChange={(e) => patch({ contact_email: e.target.value })} />
              </Field>
            </div>
            {dialog.row.id && <ActiveSwitch checked={dialog.row.is_active ?? true} onChange={(v) => patch({ is_active: v })} what="customer" />}
          </>
        )}
        {dialog?.kind === 'building' && (
          <>
            <Field label="Name">
              <Input value={dialog.row.name ?? ''} onChange={(e) => patch({ name: e.target.value })} required autoFocus />
            </Field>
            <Field label="Street and number">
              <Input value={dialog.row.street ?? ''} onChange={(e) => patch({ street: e.target.value })} required />
            </Field>
            <div className="grid gap-4 sm:grid-cols-[8rem_1fr]">
              <Field label="Postcode">
                <Input value={dialog.row.postcode ?? ''} onChange={(e) => patch({ postcode: e.target.value })} required maxLength={12} />
              </Field>
              <Field label="City">
                <Input value={dialog.row.city ?? ''} onChange={(e) => patch({ city: e.target.value })} required />
              </Field>
            </div>
            <Field label="Delivery area">
              <Select value={dialog.row.area_id ?? ''} onChange={(e) => patch({ area_id: Number(e.target.value) })} wrapperClassName="w-full" required>
                {areas.data?.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name}
                  </option>
                ))}
              </Select>
            </Field>
            {dialog.row.id && <ActiveSwitch checked={dialog.row.is_active ?? true} onChange={(v) => patch({ is_active: v })} what="building" />}
          </>
        )}
        {dialog?.kind === 'zone' && (
          <>
            <Field label="Name" hint="For example: front door, staff entrance, second floor.">
              <Input value={dialog.row.name ?? ''} onChange={(e) => patch({ name: e.target.value })} required autoFocus />
            </Field>
            {dialog.row.id && <ActiveSwitch checked={dialog.row.is_active ?? true} onChange={(v) => patch({ is_active: v })} what="zone" />}
          </>
        )}
      </FormDialog>
    </>
  )
}
