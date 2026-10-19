/** Customers own service locations; every new location starts with a usable Main area. */
import { useState } from 'react'

import { Empty, Fact, FormDialog, Load, PageHead, Toolbar } from '@/app/ui'
import { Button } from '@/components/ui/button'
import { Chip } from '@/components/ui/chip'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { useAction, useLoad } from '@/lib/hooks'
import { useSession } from '@/lib/session'
import type { Building, Customer, Zone } from '@/lib/types'
import { cn } from '@/lib/utils'

type Dialog =
  | { kind: 'customer'; row: Partial<Customer> }
  | { kind: 'building'; row: Partial<Building> }
  | { kind: 'zone'; row: Partial<Zone>; buildingId: number }

type LocationDraft = Pick<Building, 'name' | 'street' | 'postcode' | 'city'>
const emptyLocation = (): LocationDraft => ({ name: '', street: '', postcode: '', city: '' })

function LocationFields({ value, onChange, disabled }: { value: Partial<LocationDraft>; onChange: (patch: Partial<LocationDraft>) => void; disabled: boolean }) {
  return <>
    <Field label="Location name"><Input required disabled={disabled} maxLength={160} value={value.name ?? ''} onChange={(e) => onChange({ name: e.target.value })} /></Field>
    <Field label="Street and number"><Input required disabled={disabled} maxLength={160} value={value.street ?? ''} onChange={(e) => onChange({ street: e.target.value })} /></Field>
    <div className="grid gap-4 sm:grid-cols-[8rem_1fr]"><Field label="Postcode"><Input required disabled={disabled} maxLength={12} value={value.postcode ?? ''} onChange={(e) => onChange({ postcode: e.target.value })} /></Field><Field label="City"><Input required disabled={disabled} maxLength={80} value={value.city ?? ''} onChange={(e) => onChange({ city: e.target.value })} /></Field></div>
  </>
}

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
  const [initialLocations, setInitialLocations] = useState<LocationDraft[]>([emptyLocation()])
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
  const [removing, setRemoving] = useState<{ path: string; title: string; detail: string } | null>(null)
  const save = useAction()
  const remove = useAction()
  const patch = (row: object) => setDialog((d) => (d ? ({ ...d, row: { ...d.row, ...row } } as Dialog) : d))

  const submit = () =>
    save.run(async () => {
      const d = dialog!
      if (d.kind === 'customer') {
        const { id, name, contact_name, contact_phone, contact_email, is_active } = d.row
        const body = { name, contact_name: contact_name || null, contact_phone: contact_phone || null, contact_email: contact_email || null, ...(id ? { is_active } : {}) }
        const out = id ? await api.patch<{ row: Customer }>(`/customers/${id}`, body) : await api.post<{ row: Customer }>('/customers', { ...body, locations: initialLocations })
        setSelectedId(out.row.id)
        await customers.reload()
      } else if (d.kind === 'building') {
        const { id, name, street, postcode, city, is_active } = d.row
        const body = { name, street, postcode, city, ...(id ? { is_active } : {}) }
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

  return (
    <>
      <PageHead title="Customers">
        Each customer owns one or more <span className="highlight">service locations</span>. Each location has its own contract and swap schedule.
      </PageHead>

      <Toolbar info={`${customers.data?.length ?? 0} customers`}>
        <Button variant="solid" iconRight={null} onClick={() => { save.clear(); setInitialLocations([emptyLocation()]); setDialog({ kind: 'customer', row: { name: '', is_active: true } }) }}>
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
                      <div className="flex gap-1">
                        <Button variant="ghost" size="sm" onClick={() => setDialog({ kind: 'customer', row: selected })}>
                          Edit customer
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => { remove.clear(); setRemoving({ path: `/customers/${selected.id}`, title: `Remove ${selected.name}?`, detail: 'End an active contract first. Remove a draft contract first. A customer with deliveries on record stays in the list.' }) }}>
                          Remove
                        </Button>
                      </div>
                    </div>
                    <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                      <Fact label="Contact person">{selected.contact_name}</Fact>
                      <Fact label="Phone">{selected.contact_phone}</Fact>
                      <Fact label="Email">{selected.contact_email}</Fact>
                    </dl>
                  </div>

                  <div className="mt-6 mb-3 flex items-center justify-between gap-3">
                    <h3 className="text-xl font-medium">Locations</h3>
                    <Button
                      size="sm"
                      disabled={!selected.is_active}
                      onClick={() => setDialog({ kind: 'building', row: { ...emptyLocation(), is_active: true } })}
                    >
                      Add location
                    </Button>
                  </div>

                  <Load state={buildings} rows={2}>
                    {(list) =>
                      list.length === 0 ? (
                        <Empty>No locations yet. Add a location before creating its contract.</Empty>
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
                                    {b.street}, {b.postcode} {b.city}
                                  </p>
                                </div>
                                <div className="flex gap-1">
                                  <Button variant="ghost" size="sm" onClick={() => setDialog({ kind: 'building', row: b })}>
                                    Edit
                                  </Button>
                                  <Button variant="ghost" size="sm" onClick={() => { remove.clear(); setRemoving({ path: `/buildings/${b.id}`, title: `Remove ${b.name}?`, detail: 'End this location’s contract first, or remove its draft. A location with deliveries on record stays.' }) }}>
                                    Remove
                                  </Button>
                                </div>
                              </div>
                              <div className="mt-3 flex flex-wrap items-center gap-2">
                                {b.zones.map((z) => (
                                  <span key={z.id} className="inline-flex items-center gap-1">
                                    <button
                                      type="button"
                                      onClick={() => setDialog({ kind: 'zone', row: z, buildingId: b.id })}
                                      className={cn(
                                        'cursor-pointer rounded border border-gray-200 px-2 py-1 text-xs transition-colors outline-none hover:text-brand focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:border-gray-700',
                                        !z.is_active && 'text-muted-foreground line-through',
                                      )}
                                    >
                                      {z.name}
                                    </button>
                                    <Button variant="ghost" size="sm" onClick={() => { remove.clear(); setRemoving({ path: `/zones/${z.id}`, title: `Remove ${z.name}?`, detail: 'Take it off the contract first, or end that contract. A zone that already has mats or orders stays.' }) }}>
                                      Remove
                                    </Button>
                                  </span>
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
        title={dialog ? `${dialog.row.id ? 'Edit' : 'Add'} ${dialog.kind === 'building' ? 'location' : dialog.kind}` : ''}
        className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl"
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
            {!dialog.row.id && <fieldset className="space-y-4"><legend className="mb-3 font-medium">Customer locations</legend><p className="text-sm text-muted-foreground">Add at least one location. Each starts with a Main area; extra zones can be added later.</p>{initialLocations.map((location, index) => <div key={index} className="space-y-3 rounded-xl border border-border p-4"><div className="flex items-center justify-between gap-2"><h3 className="text-sm font-medium">Location {index + 1}</h3>{initialLocations.length > 1 && <Button type="button" variant="ghost" size="sm" disabled={save.busy} onClick={() => setInitialLocations((rows) => rows.filter((_, i) => i !== index))}>Remove location {index + 1}</Button>}</div><LocationFields value={location} disabled={save.busy} onChange={(change) => setInitialLocations((rows) => rows.map((row, i) => i === index ? { ...row, ...change } : row))} /></div>)}<Button type="button" variant="soft" disabled={save.busy || initialLocations.length >= 100} onClick={() => setInitialLocations((rows) => [...rows, emptyLocation()])}>Add another location</Button></fieldset>}
            {dialog.row.id && <ActiveSwitch checked={dialog.row.is_active ?? true} onChange={(v) => patch({ is_active: v })} what="customer" />}
          </>
        )}
        {dialog?.kind === 'building' && (
          <>
            <LocationFields value={dialog.row} disabled={save.busy} onChange={patch} />
            {!dialog.row.id && <p className="text-sm text-muted-foreground">A Main area is created automatically for this location.</p>}
            {dialog.row.id && <ActiveSwitch checked={dialog.row.is_active ?? true} onChange={(v) => patch({ is_active: v })} what="location" />}
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
      <ConfirmDialog open={removing !== null} title={removing?.title ?? 'Remove?'} confirmLabel="Remove" busyLabel="Removing" busy={remove.busy} error={remove.error} onCancel={() => { setRemoving(null); remove.clear() }} onConfirm={() => void remove.run(async () => { await api.del(removing!.path); const path = removing!.path; setRemoving(null); if (path.startsWith('/customers/')) setSelectedId(null); await customers.reload(); await buildings.reload() })}>
        {removing?.detail}
      </ConfirmDialog>
    </>
  )
}
