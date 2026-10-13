/** The driver's screens: his tasks, and closing one stop with the mats he lays, the mats he takes, a photo and a signature. */
import { useEffect, useMemo, useRef, useState } from 'react'

import { Empty } from '@/app/ui'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Chip } from '@/components/ui/chip'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import { usePile } from '@/handheld/pile'
import { type Ctx, Hint } from '@/handheld/screens'
import { useAction, useLoad } from '@/lib/hooks'
import { TASK_TYPE, day, today } from '@/lib/labels'
import type { Mat, Stop } from '@/lib/types'

function toBlob(canvas: HTMLCanvasElement, type: string, quality?: number): Promise<Blob> {
  return new Promise((resolve, reject) => canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('The picture could not be made.'))), type, quality))
}

/** Any picture as a JPEG of at most 1600 pixels wide: what the API takes, and small enough for a phone connection. */
async function photoFromFile(file: Blob): Promise<Blob> {
  const image = await createImageBitmap(file)
  const scale = Math.min(1, 1600 / image.width)
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(image.width * scale)
  canvas.height = Math.round(image.height * scale)
  canvas.getContext('2d')!.drawImage(image, 0, 0, canvas.width, canvas.height)
  return toBlob(canvas, 'image/jpeg', 0.85)
}

/** A stand-in photo for a simulated stop, so a review does not need a camera. */
function samplePhoto(label: string): Promise<Blob> {
  const canvas = document.createElement('canvas')
  canvas.width = 800
  canvas.height = 600
  const ctx = canvas.getContext('2d')!
  ctx.fillStyle = '#d6d3d1'
  ctx.fillRect(0, 0, 800, 600)
  ctx.fillStyle = '#57534e'
  ctx.fillRect(140, 330, 520, 170)
  ctx.fillStyle = '#1c1917'
  ctx.font = '600 30px sans-serif'
  ctx.fillText('Sample proof photo', 40, 70)
  ctx.font = '22px sans-serif'
  ctx.fillText(label, 40, 110)
  return toBlob(canvas, 'image/jpeg', 0.85)
}

/** A box to sign in with a finger or the mouse. Gives a PNG, the kind of file the API takes for a signature. */
function SignaturePad({ onChange }: { onChange: (png: Blob | null) => void }) {
  const ref = useRef<HTMLCanvasElement>(null)
  const drawing = useRef(false)

  const clear = () => {
    const canvas = ref.current!
    const ctx = canvas.getContext('2d')!
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    onChange(null)
  }
  useEffect(clear, []) // eslint-disable-line react-hooks/exhaustive-deps

  const point = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const box = e.currentTarget.getBoundingClientRect()
    return [((e.clientX - box.left) / box.width) * e.currentTarget.width, ((e.clientY - box.top) / box.height) * e.currentTarget.height] as const
  }

  return (
    <div>
      <canvas
        ref={ref}
        width={560}
        height={200}
        aria-label="Sign here"
        className="w-full touch-none rounded-md border border-gray-200 bg-white dark:border-gray-700"
        onPointerDown={(e) => {
          drawing.current = true
          e.currentTarget.setPointerCapture(e.pointerId)
          const ctx = e.currentTarget.getContext('2d')!
          ctx.strokeStyle = '#111'
          ctx.lineWidth = 3
          ctx.lineCap = 'round'
          ctx.beginPath()
          ctx.moveTo(...point(e))
        }}
        onPointerMove={(e) => {
          if (!drawing.current) return
          const ctx = e.currentTarget.getContext('2d')!
          ctx.lineTo(...point(e))
          ctx.stroke()
        }}
        onPointerUp={(e) => {
          drawing.current = false
          void toBlob(e.currentTarget, 'image/png').then(onChange)
        }}
      />
      <button type="button" onClick={clear} className="mt-1 cursor-pointer text-xs text-muted-foreground underline underline-offset-4 outline-none hover:text-brand focus-visible:text-brand">
        Clear the signature
      </button>
    </div>
  )
}

/** The driver's open tasks, soonest first. Opening one starts the stop. */
export function TasksScreen({ api, user }: Ctx) {
  const state = useLoad(() => api.get<{ tasks: Stop[] }>('/tasks').then((r) => r.tasks), [api], 8000)
  // The stop itself and not its id: once it is closed it leaves the list, and the driver must still see that it went well.
  const [open, setOpen] = useState<Stop | null>(null)

  if (open) {
    return (
      <StopScreen
        api={api}
        user={user}
        stop={open}
        onLeave={() => {
          setOpen(null)
          void state.reload()
        }}
      />
    )
  }
  if (!state.data) return state.error ? <Alert tone="danger">{state.error}</Alert> : <Skeleton className="h-24" />
  if (state.data.length === 0) return <Empty>No tasks for you right now. The office assigns them.</Empty>

  const now = today()
  return (
    <ul className="space-y-2">
      {state.data.map((t) => (
        <li key={t.id}>
          <button type="button" onClick={() => setOpen(t)} className="card-chai w-full cursor-pointer p-3 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
            <span className="flex items-center justify-between gap-2">
              <span className="font-montserrat text-sm font-semibold">
                {TASK_TYPE[t.type]}: {t.building_name}
              </span>
              {t.due_date < now ? <Chip tone="danger">Late</Chip> : t.due_date === now ? <Chip tone="success">Today</Chip> : <Chip>{day(t.due_date)}</Chip>}
            </span>
            <span className="mt-0.5 block text-xs text-muted-foreground">
              {t.customer_name}. {t.street}, {t.postcode} {t.city}
            </span>
            <span className="mt-1 block text-xs">{t.expected.map((e) => `${e.quantity} × ${e.type_name} at ${e.zone_name}`).join('. ') || 'Pick up only'}</span>
          </button>
        </li>
      ))}
    </ul>
  )
}

/** One stop. Nothing is saved until the driver closes it; the server then checks every mat and saves all or nothing. */
function StopScreen({ api, user, stop, onLeave }: Ctx & { stop: Stop; onLeave: () => void }) {
  const pile = usePile(api)
  const mine = useLoad(() => api.get<{ items: Mat[] }>('/items').then((r) => r.items), [api])
  // The clean mats on this driver's van: the server knows what he carries, the pile knows their tag ids.
  const van = useMemo(() => {
    const carried = new Set((mine.data ?? []).filter((m) => m.status === 'out_for_delivery').map((m) => m.sku))
    return (pile.data ?? []).filter((m) => carried.has(m.sku))
  }, [mine.data, pile.data])

  const [fate, setFate] = useState<Record<string, string>>({}) // epc -> "zone:<id>" or "refused"
  const [picked, setPicked] = useState<Set<string>>(new Set())
  const [photo, setPhoto] = useState<Blob | null>(null)
  const [photoUrl, setPhotoUrl] = useState<string | null>(null)
  const [signature, setSignature] = useState<Blob | null>(null)
  const [signedName, setSignedName] = useState(stop.contact_name ?? '')
  const [note, setNote] = useState('')
  const [done, setDone] = useState<{ next_task_id: number | null } | null>(null)
  const close = useAction()

  useEffect(() => {
    if (!photo) return setPhotoUrl(null)
    const url = URL.createObjectURL(photo)
    setPhotoUrl(url)
    return () => URL.revokeObjectURL(url)
  }, [photo])

  const lays = stop.type !== 'collection'
  const takes = stop.type !== 'delivery'

  const submit = () =>
    close.run(async () => {
      if (!photo) throw new Error('Take a photo of the mats first.')
      if (!signature) throw new Error('Ask the customer to sign first.')
      const drops = stop.zones.map((z) => ({ zone_id: z.id, epcs: Object.keys(fate).filter((epc) => fate[epc] === `zone:${z.id}`) })).filter((d) => d.epcs.length > 0)
      const payload = { source: 'simulator', drops, pickups: [...picked], refused: Object.keys(fate).filter((epc) => fate[epc] === 'refused'), signed_name: signedName, issue_note: note || null }
      setDone(await api.form<{ next_task_id: number | null }>(`/tasks/${stop.id}/complete`, payload, { photo, signature }))
    })

  if (done) {
    return (
      <>
        <Alert tone="success" title="Stop closed">
          {done.next_task_id ? 'The next swap is planned. The office will assign it.' : 'Nothing further is planned for this building.'}
        </Alert>
        <Button onClick={onLeave}>Back to my tasks</Button>
      </>
    )
  }

  return (
    <>
      <div className="card-chai p-3">
        <p className="font-montserrat text-sm font-semibold">
          {TASK_TYPE[stop.type]}: {stop.building_name}
        </p>
        <p className="text-xs text-muted-foreground">
          {stop.customer_name}. {stop.street}, {stop.postcode} {stop.city}
        </p>
        {(stop.contact_name || stop.contact_phone) && (
          <p className="mt-1 text-xs">
            Contact: {stop.contact_name} {stop.contact_phone}
          </p>
        )}
      </div>

      {lays && (
        <section className="space-y-2">
          <h3 className="font-montserrat text-sm font-semibold">Mats to lay</h3>
          <ul className="text-xs text-muted-foreground">
            {stop.expected.map((e) => (
              <li key={`${e.zone_id}-${e.item_type_id}`}>
                {e.quantity} × {e.type_name} at {e.zone_name}
              </li>
            ))}
          </ul>
          {van.length === 0 ? (
            <Empty className="py-4">No clean mats on your van. Load them first: receive from inventory.</Empty>
          ) : (
            <ul className="space-y-1.5">
              {van.map((m) => (
                <li key={m.epc} className="grid grid-cols-[1fr_9.5rem] items-center gap-2">
                  <span className="min-w-0">
                    <span className="block font-mono text-xs">{m.sku}</span>
                    <span className="block truncate text-[11px] text-muted-foreground">{m.mat?.type}</span>
                  </span>
                  <Select aria-label={`What happens to ${m.sku}`} value={fate[m.epc] ?? ''} onChange={(e) => setFate({ ...fate, [m.epc]: e.target.value })} wrapperClassName="w-full">
                    <option value="">Stays on the van</option>
                    {stop.zones.map((z) => (
                      <option key={z.id} value={`zone:${z.id}`}>
                        Lay at {z.name}
                      </option>
                    ))}
                    <option value="refused">Customer refused it</option>
                  </Select>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      {takes && (
        <section className="space-y-2">
          <div className="flex items-center justify-between gap-2">
            <h3 className="font-montserrat text-sm font-semibold">Mats to pick up</h3>
            {stop.in_building.length > 0 && (
              <button type="button" className="cursor-pointer text-xs text-muted-foreground underline underline-offset-4 outline-none hover:text-brand" onClick={() => setPicked(new Set(stop.in_building.map((m) => m.epc)))}>
                All found
              </button>
            )}
          </div>
          {stop.in_building.length === 0 ? (
            <Empty className="py-4">The system knows of no mat in this building.</Empty>
          ) : (
            <ul className="space-y-0.5">
              {stop.in_building.map((m) => (
                <li key={m.epc}>
                  <label className="flex cursor-pointer items-center gap-2.5 rounded-md px-1.5 py-1.5 text-sm hover:bg-accent/50">
                    <Checkbox
                      checked={picked.has(m.epc)}
                      onCheckedChange={(on) => {
                        const next = new Set(picked)
                        on ? next.add(m.epc) : next.delete(m.epc)
                        setPicked(next)
                      }}
                    />
                    <span className="font-mono text-xs">{m.sku}</span>
                    <span className="ml-auto truncate text-[11px] text-muted-foreground">{stop.zones.find((z) => z.id === m.zone_id)?.name}</span>
                  </label>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      <section className="space-y-3">
        <h3 className="font-montserrat text-sm font-semibold">Proof</h3>
        {photoUrl && <img src={photoUrl} alt="The photo for this stop" className="aspect-video w-full rounded-md border border-card-edge object-cover" />}
        <div className="grid grid-cols-2 gap-2">
          <label className="inline-flex h-9 cursor-pointer items-center justify-center rounded-md bg-primary px-3 text-sm font-semibold text-primary-foreground transition-colors focus-within:ring-[3px] focus-within:ring-ring/50 hover:bg-primary/90">
            Take a photo
            <input
              type="file"
              accept="image/*"
              capture="environment"
              className="sr-only"
              onChange={(e) => {
                const file = e.target.files?.[0]
                if (file) void close.run(async () => setPhoto(await photoFromFile(file)))
              }}
            />
          </label>
          <Button variant="ghost" onClick={() => void samplePhoto(`${stop.building_name}, ${today()}`).then(setPhoto)}>
            Use a sample
          </Button>
        </div>
        <Field label="Name of the person who signs">
          <Input value={signedName} onChange={(e) => setSignedName(e.target.value)} maxLength={120} />
        </Field>
        <SignaturePad onChange={setSignature} />
        <Field label="Note" aside="Needed when something differs" hint="For example a mat that was missing, or fewer mats laid than agreed.">
          <Textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} rows={2} />
        </Field>
      </section>

      {close.error && <Alert tone="danger">{close.error}</Alert>}
      <Button variant="solid" iconRight={null} disabled={close.busy} onClick={() => void submit()}>
        {close.busy ? 'Closing the stop' : 'Close the stop'}
      </Button>
      <Button variant="ghost" onClick={onLeave} disabled={close.busy}>
        Back, nothing is saved
      </Button>
    </>
  )
}
