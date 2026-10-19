/** Category hierarchy and SKU stock used by office registration and orders. */
import { useEffect, useState } from 'react'
import { Link } from 'react-router'

import { Empty, FormDialog, Load, PageHead, Toolbar } from '@/app/ui'
import { Button } from '@/components/ui/button'
import { Chip } from '@/components/ui/chip'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAction, useLoad } from '@/lib/hooks'
import { useSession } from '@/lib/session'
import type { CatalogSku, Category, ItemType } from '@/lib/types'

type Row = { id: number; name: string; is_active?: boolean; category_id?: number | null }

/** One reference list with add/edit dialogs and optional parent-category selection. */
function NamedList({ title, noun, path, canSwitchOff, main, categories, onChanged, nextLabel, launch, onCreated }: { title: string; noun: string; path: string; canSwitchOff: boolean; main?: boolean; categories?: Category[]; onChanged?: () => Promise<void>; nextLabel?: string; launch?: { token: number; preset: Partial<Row> } | null; onCreated?: (row: Row) => void }) {
  const { api } = useSession()
  const state = useLoad(() => api.get<{ rows: Row[] }>(path).then((r) => r.rows), [api, path])
  const [editing, setEditing] = useState<Partial<Row> | null>(null)
  const [removing, setRemoving] = useState<Row | null>(null)
  const save = useAction()
  const remove = useAction()

  useEffect(() => {
    if (!launch) return
    save.clear()
    setEditing({ name: '', is_active: true, ...launch.preset })
    // The token is the trigger. save.clear is stable for this open.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [launch?.token])

  const submit = (proceed: boolean) =>
    save.run(async () => {
      const body = { name: editing!.name, ...(categories ? { category_id: Number(editing!.category_id) } : {}), ...(canSwitchOff && editing!.id ? { is_active: editing!.is_active } : {}) }
      const created = editing!.id ? null : (await api.post<{ row: Row }>(path, body)).row
      if (editing!.id) await api.patch(`${path}/${editing!.id}`, body)
      setEditing(null)
      await state.reload()
      await onChanged?.()
      if (created && proceed) onCreated?.(created)
    })

  return (
    <section>
      <Toolbar info={<span className="text-xl font-medium text-foreground">{title}</span>}>
        <Button variant={main ? 'solid' : 'soft'} iconRight={null} disabled={categories !== undefined && !categories.some((c) => c.is_active)} onClick={() => { save.clear(); setEditing({ name: '', is_active: true }) }}>
          Add {noun}
        </Button>
      </Toolbar>
      <Load state={state}>
        {(rows) => {
          return rows.length === 0 ? (
            <Empty>No {noun} yet.{categories !== undefined && !categories.some((c) => c.is_active) ? ' Create an active category first.' : ''}</Empty>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  {categories && <TableHead>Category</TableHead>}
                  {canSwitchOff && <TableHead>In use</TableHead>}
                  <TableHead className="w-0" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell className="font-medium">{row.name}</TableCell>
                    {categories && <TableCell>{categories.find((c) => c.id === row.category_id)?.name ?? 'Unassigned'}</TableCell>}
                    {canSwitchOff && <TableCell>{row.is_active ? <Chip tone="success">Yes</Chip> : <Chip>Switched off</Chip>}</TableCell>}
                    <TableCell className="space-x-1 text-right">
                      <Button variant="ghost" size="sm" onClick={() => setEditing(row)}>
                        Edit
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => { remove.clear(); setRemoving(row) }}>
                        Remove
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )
        }}
      </Load>
      <p className="mt-2 text-xs text-muted-foreground">
        Showing {state.data?.length ?? 0} {state.data?.length === 1 ? noun : noun.endsWith('y') ? `${noun.slice(0, -1)}ies` : `${noun}s`}
      </p>

      <FormDialog
        open={editing !== null}
        title={editing?.id ? `Edit ${noun}` : `Add ${noun}`}
        busy={save.busy}
        error={save.error}
        nextLabel={editing?.id ? undefined : nextLabel}
        onSubmit={(proceed) => void submit(Boolean(proceed))}
        onClose={() => {
          setEditing(null)
          save.clear()
        }}
      >
        <Field label="Name">
          <Input value={editing?.name ?? ''} onChange={(e) => setEditing({ ...editing, name: e.target.value })} required autoFocus disabled={save.busy} />
        </Field>
        {categories && <Field label="Category"><Select value={editing?.category_id ?? ''} required disabled={save.busy} onChange={(e) => setEditing({ ...editing, category_id: Number(e.target.value) })} wrapperClassName="w-full"><option value="">Choose category</option>{categories.filter((c) => c.is_active || c.id === editing?.category_id).map((c) => <option key={c.id} value={c.id} disabled={!c.is_active}>{c.name}{!c.is_active ? ' (switched off)' : ''}</option>)}</Select></Field>}
        {canSwitchOff && editing?.id && (
          <label className="flex items-center gap-3 text-sm">
            <Switch disabled={save.busy} checked={editing.is_active ?? true} onCheckedChange={(v) => setEditing({ ...editing, is_active: v })} />
            In use. Switch off to hide it from new mats and contracts.
          </label>
        )}
      </FormDialog>
      <ConfirmDialog open={removing !== null} title={`Remove ${removing?.name ?? 'this'}?`} confirmLabel="Remove" busyLabel="Removing" busy={remove.busy} error={remove.error} onCancel={() => { setRemoving(null); remove.clear() }} onConfirm={() => void remove.run(async () => { await api.del(`${path}/${removing!.id}`); setRemoving(null); await state.reload(); await onChanged?.() })}>
        If something still uses it, nothing is removed and the next step is named.
      </ConfirmDialog>
    </section>
  )
}

export function SetupPage() {
  const { api } = useSession()
  const categories = useLoad(() => api.get<{ rows: Category[] }>('/categories').then((r) => r.rows), [api])
  const types = useLoad(() => api.get<{ rows: ItemType[] }>('/item-types').then((r) => r.rows), [api])
  const catalog = useLoad(() => api.get<{ rows: CatalogSku[] }>('/sku-catalog').then((r) => r.rows), [api], 8000)
  const [creating, setCreating] = useState(false)
  const [typeId, setTypeId] = useState('')
  const [width, setWidth] = useState('')
  const [height, setHeight] = useState('')
  const [color, setColor] = useState('')
  const [quantity, setQuantity] = useState('0')
  const [restocking, setRestocking] = useState<CatalogSku | null>(null)
  const [removingSku, setRemovingSku] = useState<CatalogSku | null>(null)
  const [received, setReceived] = useState('')
  const save = useAction()
  const removeSku = useAction()
  const [subLaunch, setSubLaunch] = useState<{ token: number; preset: Partial<Row> } | null>(null)
  const activeTypes = types.data?.filter((t) => t.is_active && categories.data?.some((c) => c.id === t.category_id && c.is_active)) ?? []
  const refresh = async () => { await Promise.all([categories.reload(), types.reload(), catalog.reload()]) }
  const openSku = (nextType = '') => { setCreating(true); setTypeId(nextType); setWidth(''); setHeight(''); setColor(''); setQuantity('0'); save.clear() }

  return (
    <>
      <PageHead title="Categories and SKUs">
        Categories, the subcategories under them, and the SKUs in stock. <Link to="/registration" className="underline">Map individual mats</Link> when a SKU has untagged stock.
      </PageHead>
      <div className="grid gap-x-8 lg:grid-cols-2">
        <NamedList title="Categories" noun="category" path="/categories" canSwitchOff main onChanged={refresh} nextLabel="Save and add subcategory" onCreated={(row) => setSubLaunch({ token: Date.now(), preset: { category_id: Number(row.id) } })} />
        <Load state={categories}>{(rows) => <NamedList title="Subcategories" noun="subcategory" path="/item-types" canSwitchOff categories={rows} onChanged={refresh} nextLabel="Save and add SKU" launch={subLaunch} onCreated={(row) => openSku(String(row.id))} />}</Load>
      </div>
      <Toolbar info={<span className="text-xl font-medium text-foreground">Product SKUs and stock</span>}>
        <Button variant="solid" iconRight={null} disabled={!activeTypes.length || categories.loading || types.loading || Boolean(categories.error || types.error)} onClick={() => openSku()}>Add SKU</Button>
      </Toolbar>
      {types.error && <p className="mb-3 text-sm text-destructive">{types.error}</p>}
      <Load state={catalog}>{(rows) => rows.length ? <>
        <Table><TableHeader><TableRow>{['Product SKU', 'Category / subcategory', 'Size / colour', 'Untagged', 'Ready', 'Reserved', 'On van', 'With customer', 'Washing', 'Repair', 'Scrapped', 'Total owned', ''].map((label) => <TableHead key={label}>{label}</TableHead>)}</TableRow></TableHeader><TableBody>{rows.map((sku) => <TableRow key={sku.id}>
          <TableCell className="font-mono text-xs whitespace-nowrap">{sku.code}</TableCell>
          <TableCell>{sku.category_name} → {sku.type_name}</TableCell>
          <TableCell className="whitespace-nowrap">{sku.width_cm !== null && sku.height_cm !== null ? `${sku.width_cm} × ${sku.height_cm} cm` : 'Size not recorded'} · {sku.color ?? 'Colour not recorded'}</TableCell>
          {[sku.untagged_quantity, sku.ready_quantity, sku.reserved_quantity, sku.on_van_quantity, sku.at_customer_quantity, sku.washing_quantity, sku.repair_quantity, sku.scrapped_quantity, sku.total_quantity].map((n, i) => <TableCell key={i} className="text-right tabular-nums">{n}</TableCell>)}
          <TableCell className="space-x-1 text-right whitespace-nowrap"><Button variant="ghost" size="sm" onClick={() => { setRestocking(sku); setReceived(''); save.clear() }}>Add stock</Button><Button variant="ghost" size="sm" onClick={() => { removeSku.clear(); setRemovingSku(sku) }}>Remove</Button></TableCell>
        </TableRow>)}</TableBody></Table>
        <p className="mt-2 text-xs text-muted-foreground">Ready excludes reservations and pending handovers. Reserved overlaps mat locations; do not add it again. Total owned includes untagged and tagged mats, excluding scrapped mats. Counts refresh automatically.</p>
      </> : <Empty>{activeTypes.length ? 'Create a SKU with size, colour and initial untagged quantity. Then open Registration to map each physical mat.' : 'Create a category (for example Indoor), then a subcategory (for example Rubber mat), then its product SKU.'}</Empty>}</Load>
      <FormDialog open={creating} title="Add product SKU" description="SKU number is generated automatically. Initial quantity counts untagged mats already owned." busy={save.busy} error={save.error} onClose={() => { setCreating(false); save.clear() }} onSubmit={() => void save.run(async () => {
        await api.post('/sku-catalog', { item_type_id: Number(typeId), width_cm: Number(width), height_cm: Number(height), color, quantity: Number(quantity) })
        setCreating(false); await catalog.reload()
      })}>
        <Field label="Category / subcategory"><Select required disabled={save.busy} value={typeId} onChange={(e) => setTypeId(e.target.value)} wrapperClassName="w-full"><option value="">Choose subcategory</option>{activeTypes.map((t) => <option key={t.id} value={t.id}>{categories.data?.find((c) => c.id === t.category_id)?.name} → {t.name}</option>)}</Select></Field>
        <div className="grid grid-cols-2 gap-3"><Field label="Width (cm)"><Input required disabled={save.busy} type="number" min="0.01" max="999999.99" step="0.01" value={width} onChange={(e) => setWidth(e.target.value)} /></Field><Field label="Height (cm)"><Input required disabled={save.busy} type="number" min="0.01" max="999999.99" step="0.01" value={height} onChange={(e) => setHeight(e.target.value)} /></Field></div>
        <Field label="Colour"><Input required disabled={save.busy} value={color} maxLength={80} onChange={(e) => setColor(e.target.value)} /></Field>
        <Field label="Initial untagged quantity"><Input required disabled={save.busy} type="number" min="0" max="4294967295" step="1" value={quantity} onChange={(e) => setQuantity(e.target.value)} /></Field>
      </FormDialog>
      <FormDialog open={restocking !== null} title={`Add stock · ${restocking?.code ?? ''}`} description={restocking ? `${restocking.label}. Enter newly received untagged mats; this adds to existing stock.` : ''} submitLabel="Add stock" busy={save.busy} error={save.error} onClose={() => { setRestocking(null); save.clear() }} onSubmit={() => void save.run(async () => {
        await api.post(`/sku-catalog/${restocking!.id}/stock`, { quantity: Number(received) })
        setRestocking(null); await catalog.reload()
      })}>
        <Field label="Quantity received"><Input required autoFocus disabled={save.busy} type="number" min="1" max="4294967295" step="1" value={received} onChange={(e) => setReceived(e.target.value)} /></Field>
      </FormDialog>
      <ConfirmDialog open={removingSku !== null} title={`Remove ${removingSku?.code ?? 'this SKU'}?`} confirmLabel="Remove" busyLabel="Removing" busy={removeSku.busy} error={removeSku.error} onCancel={() => { setRemovingSku(null); removeSku.clear() }} onConfirm={() => void removeSku.run(async () => { await api.del(`/sku-catalog/${removingSku!.id}`); setRemovingSku(null); await catalog.reload() })}>
        Untagged stock on this SKU is removed with it. A SKU that already has mapped mats stays on record.
      </ConfirmDialog>
    </>
  )
}
