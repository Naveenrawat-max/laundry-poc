/** Office registration maps two simulated tags to one explicitly selected stock mat. */
import { useState } from 'react'
import { Link } from 'react-router'

import { Empty, Load, PageHead } from '@/app/ui'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import { Select } from '@/components/ui/select'
import { useAction, useLoad } from '@/lib/hooks'
import { useSession } from '@/lib/session'
import type { CatalogSku, Category, ItemType, Mat } from '@/lib/types'

const hex = () => Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, '0')).join('').toUpperCase()
const scanTags = () => ({ epc_primary: `4D4F434B${hex()}`, epc_secondary: `4D4F434B${hex()}`, tid_primary: `E2800001${hex()}`, tid_secondary: `E2800002${hex()}` })

export function RegistrationPage() {
  const { api } = useSession()
  const categories = useLoad(() => api.get<{ rows: Category[] }>('/categories').then((r) => r.rows), [api])
  const types = useLoad(() => api.get<{ rows: ItemType[] }>('/item-types').then((r) => r.rows.filter((t) => t.is_active)), [api])
  const [typeId, setTypeId] = useState('')
  const catalog = useLoad(() => typeId ? api.get<{ rows: CatalogSku[] }>(`/sku-catalog?item_type_id=${typeId}`).then((r) => r.rows) : Promise.resolve([]), [api, typeId], 8000)
  const [skuId, setSkuId] = useState('')
  const selectedType = types.data?.find((t) => String(t.id) === typeId && categories.data?.some((c) => c.id === t.category_id && c.is_active))
  const selectedSku = catalog.data?.find((s) => String(s.id) === skuId && String(s.item_type_id) === typeId)
  const [scanned, setScanned] = useState<ReturnType<typeof scanTags> | null>(null)
  const [done, setDone] = useState<Mat | null>(null)
  const save = useAction()
  const canScan = Boolean(selectedType && selectedSku && selectedSku.width_cm && selectedSku.height_cm && selectedSku.color && selectedSku.untagged_quantity > 0 && !catalog.loading && !catalog.error && !save.busy)
  const clearScan = () => { setScanned(null); setDone(null); save.clear() }

  return <>
    <PageHead title="Registration">Choose a product SKU, scan both tags, then map one mat. Each mapped mat is immediately ready to deliver.</PageHead>
    <div className="mx-auto mt-8 max-w-2xl space-y-5">
      <Alert title="RFID demo">Scans and tag mapping are simulated. Physical RFID tags are not rewritten.</Alert>
      {done && <Alert tone="success" title={`${done.sku} is ready to deliver`}>One mat mapped. Keep this SKU selected to register the next mat, or choose another SKU.</Alert>}
      <Load state={categories}>{() => <Load state={types}>{(rows) => rows.some((t) => categories.data?.some((c) => c.id === t.category_id && c.is_active)) ? <Field label="Category / subcategory">
        <Select required value={typeId} disabled={save.busy} onChange={(e) => { setTypeId(e.target.value); setSkuId(''); clearScan() }} wrapperClassName="w-full">
          <option value="">Choose subcategory</option>
          {rows.filter((t) => categories.data?.some((c) => c.id === t.category_id && c.is_active)).map((t) => <option key={t.id} value={t.id}>{categories.data?.find((c) => c.id === t.category_id)?.name} → {t.name}</option>)}
        </Select>
      </Field> : <Empty>Create a category, subcategory and SKU under <Link to="/setup" className="underline">Categories and SKUs</Link> first.</Empty>}</Load>}</Load>
      {typeId && <Load state={catalog}>{(rows) => rows.filter((s) => String(s.item_type_id) === typeId).length ? <Field label="Product SKU" hint={selectedSku ? `${selectedSku.untagged_quantity} untagged · ${selectedSku.ready_quantity} ready to deliver` : 'Choose one SKU before scanning.'}>
        <Select required value={skuId} disabled={save.busy || catalog.loading || Boolean(catalog.error)} onChange={(e) => { setSkuId(e.target.value); clearScan() }} wrapperClassName="w-full">
          <option value="">Choose SKU</option>
          {rows.filter((s) => String(s.item_type_id) === typeId).map((s) => <option key={s.id} value={s.id} disabled={!s.width_cm || !s.height_cm || !s.color}>{s.code} — {s.label} · {s.untagged_quantity} untagged{!s.width_cm || !s.height_cm || !s.color ? ' (incomplete product details)' : ''}</option>)}
        </Select>
      </Field> : <Empty>No SKU in this subcategory. <Link to="/setup" className="underline">Create a SKU with untagged stock</Link> first.</Empty>}</Load>}
      {selectedSku?.untagged_quantity === 0 && <Alert title="No untagged stock">Add stock to this SKU under <Link to="/setup" className="underline">Categories and SKUs</Link> before mapping another mat.</Alert>}
      <div className="flex flex-wrap gap-3">
        <Button variant="soft" iconRight={null} disabled={!canScan} onClick={() => { if (!canScan) return; setScanned(scanTags()); setDone(null); save.clear() }}>Scan both tags</Button>
        <Button variant="solid" iconRight={null} disabled={!canScan || !scanned || done !== null} onClick={() => void save.run(async () => {
          if (!canScan || !scanned || !selectedSku || done !== null) throw new Error('Select an in-stock SKU and scan both tags first.')
          const { item } = await api.post<{ item: Mat }>('/items', { source: 'simulator', catalog_sku_id: selectedSku.id, ...scanned })
          setDone(item); await catalog.reload()
        })}>{save.busy ? 'Mapping' : 'Map'}</Button>
      </div>
      {scanned && <section className="space-y-3" aria-label="Captured tag readings">
        <p role="status" className="text-sm">{done ? 'Mat mapped. These are the captured readings from before mapping.' : `Two tags scanned. Map them to one ${selectedSku?.label} mat.`}</p>
        <div className="grid gap-3 sm:grid-cols-2">{(['primary', 'secondary'] as const).map((side, index) => <dl key={side} className="card-chai space-y-2 p-4">
          <dt className="font-medium">{index === 0 ? 'First tag' : 'Second tag'}</dt>
          <dt className="text-xs text-muted-foreground">Captured EPC (before mapping)</dt><dd className="break-all font-mono text-xs">{scanned[`epc_${side}`]}</dd>
          <dt className="text-xs text-muted-foreground">TID</dt><dd className="break-all font-mono text-xs">{scanned[`tid_${side}`]}</dd>
        </dl>)}</div>
      </section>}
      {save.error && <Alert tone="danger">{save.error}</Alert>}
    </div>
  </>
}
