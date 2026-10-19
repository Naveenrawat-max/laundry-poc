/** The pile panel: it stands in for the handheld's trigger. Tick the mats that are "in the pile", then scan. */
import { CircleAlert, CircleCheck, CircleHelp, CircleX } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'

import { StatusChip } from '@/app/ui'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Segmented } from '@/components/ui/segmented'
import { Skeleton } from '@/components/ui/skeleton'
import type { Api } from '@/lib/api'
import { useAction, useLoad } from '@/lib/hooks'
import type { Mat, ScanAnswer, ScanResult, Status } from '@/lib/types'
import { cn } from '@/lib/utils'

/** A tag id no mat has, to see what the handheld says about a stranger's tag. */
const UNKNOWN_TAG = 'AAAA00000000000000000000'

export type PileMat = { sku: string; epc: string; mat: Mat | null }

/** Every mat the simulator can "hear", with what a lookup says about it. A real scanner hears any mat near it. */
export function usePile(api: Api) {
  return useLoad<PileMat[]>(async () => {
    const { items } = await api.get<{ items: { sku: string; epc: string }[] }>('/sim/items')
    if (items.length === 0) return []
    const answer = await api.post<ScanAnswer>('/scan/lookup', { source: 'simulator', reads: items.map((i) => ({ epc: i.epc })) })
    const byEpc = new Map(answer.results.map((r) => [r.epc, r.item]))
    return items.map((i) => ({ ...i, mat: byEpc.get(i.epc.toUpperCase()) ?? null }))
  }, [api])
}

const FILTERS = [
  { value: 'fits', label: 'Fits this step' },
  { value: 'all', label: 'All mats' },
] as const

export function PilePanel({
  api,
  fits,
  expected,
  single = false,
  scanLabel = 'Scan',
  onScan,
  refreshToken = 0,
}: {
  api: Api
  /** The statuses this step is meant for. Other mats can still be scanned, to see them refused. */
  fits?: Status[]
  /** SKUs the other side already scanned: what the receiver is expected to find. */
  expected?: string[]
  /** One mat at a time, as the quality check needs. */
  single?: boolean
  scanLabel?: string
  onScan: (epcs: string[]) => Promise<void>
  /** Bump this after a quality result so each mat's state refreshes immediately. */
  refreshToken?: number
}) {
  const pile = usePile(api)
  useEffect(() => {
    if (refreshToken > 0) void pile.reload()
    // reload identity is stable; the token is the trigger.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [refreshToken])
  const [picked, setPicked] = useState<Set<string>>(new Set())
  const [filter, setFilter] = useState<'fits' | 'all'>('fits')
  const scan = useAction()

  const isFit = (m: PileMat) => (expected ? expected.includes(m.sku) : fits ? m.mat !== null && fits.includes(m.mat.status) : true)
  const narrowable = Boolean(fits || expected)
  const shown = useMemo(() => (pile.data ?? []).filter((m) => !narrowable || filter === 'all' || isFit(m) || picked.has(m.epc)), [pile.data, filter, picked, narrowable, fits, expected]) // eslint-disable-line react-hooks/exhaustive-deps

  const toggle = (epc: string) =>
    setPicked((old) => {
      if (single) return new Set(old.has(epc) ? [] : [epc])
      const next = new Set(old)
      next.has(epc) ? next.delete(epc) : next.add(epc)
      return next
    })

  const fitting = (pile.data ?? []).filter(isFit)
  const shortcuts: { label: string; run: () => void }[] = single
    ? []
    : [
        { label: expected ? 'All expected' : 'All that fit', run: () => setPicked(new Set(fitting.map((m) => m.epc))) },
        { label: 'Leave one out', run: () => setPicked(new Set(fitting.slice(0, -1).map((m) => m.epc))) },
        {
          label: 'Add a stranger',
          run: () => {
            const stranger = (pile.data ?? []).find((m) => !isFit(m) && !picked.has(m.epc))
            if (stranger) setPicked(new Set([...picked, stranger.epc]))
          },
        },
        { label: 'Unknown tag', run: () => toggle(UNKNOWN_TAG) },
        { label: 'Clear', run: () => setPicked(new Set()) },
      ]

  const run = () =>
    scan.run(async () => {
      await onScan([...picked])
      setPicked(new Set())
      await pile.reload()
    })

  if (pile.error && !pile.data) return <Alert tone="warning">The pile could not be loaded: {pile.error}</Alert>

  return (
    <section className="rounded-xl border border-dashed border-card-edge-hover p-3">
      <div className="mb-2 flex items-center justify-between gap-2">
        <h3 className="font-montserrat text-sm font-semibold">Pile in front of the scanner</h3>
        <span className="text-[11px] text-muted-foreground">Simulated</span>
      </div>
      {narrowable && <Segmented label="Which mats to list" options={FILTERS} value={filter} onChange={setFilter} className="mb-2" />}

      {!pile.data ? (
        <div className="space-y-1.5" aria-busy="true">
          <Skeleton className="h-8" />
          <Skeleton className="h-8" />
          <Skeleton className="h-8" />
        </div>
      ) : (
        <ul className="max-h-52 space-y-0.5 overflow-y-auto pr-1">
          {shown.map((m) => (
            <li key={m.epc}>
              <label className="flex cursor-pointer items-center gap-2.5 rounded-md px-1.5 py-1.5 text-sm hover:bg-accent/50">
                <Checkbox checked={picked.has(m.epc)} onCheckedChange={() => toggle(m.epc)} />
                <span className="font-mono text-xs">{m.sku}</span>
                {m.mat && <StatusChip status={m.mat.status} className="ml-auto" />}
              </label>
            </li>
          ))}
          {picked.has(UNKNOWN_TAG) && (
            <li className="flex items-center gap-2.5 px-1.5 py-1.5 text-sm">
              <Checkbox checked onCheckedChange={() => toggle(UNKNOWN_TAG)} />
              <span className="font-mono text-xs">A tag no mat has</span>
            </li>
          )}
          {shown.length === 0 && <li className="px-1.5 py-3 text-sm text-muted-foreground">No mat fits this step right now. Switch to all mats to scan one anyway.</li>}
        </ul>
      )}

      {shortcuts.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {shortcuts.map((s) => (
            <button
              key={s.label}
              type="button"
              onClick={s.run}
              className="cursor-pointer rounded border border-gray-200 px-2 py-0.5 text-[11px] text-muted-foreground transition-colors outline-none hover:text-brand focus-visible:ring-2 focus-visible:ring-ring/50 dark:border-gray-700"
            >
              {s.label}
            </button>
          ))}
        </div>
      )}

      {scan.error && (
        <Alert tone="danger" className="mt-2">
          {scan.error}
        </Alert>
      )}
      <Button type="button" variant="solid" iconRight={null} className="mt-3 w-full" disabled={picked.size === 0 || scan.busy} onClick={() => void run()}>
        {scan.busy ? 'Scanning' : `${scanLabel}${single ? '' : ` ${picked.size} ${picked.size === 1 ? 'mat' : 'mats'}`}`}
      </Button>
    </section>
  )
}

const RESULT = {
  found: { icon: CircleCheck, word: 'Found', className: 'text-green-700 dark:text-green-400' },
  not_on_list: { icon: CircleAlert, word: 'Not on the list', className: 'text-yellow-700 dark:text-yellow-300' },
  unknown: { icon: CircleHelp, word: 'Unknown tag', className: 'text-muted-foreground' },
  rejected: { icon: CircleX, word: 'Refused', className: 'text-red-700 dark:text-red-400' },
} as const

/** What the server said about the last scan: one line per mat, then the mats that were expected and not heard. */
export function ScanResults({ answer, className }: { answer: ScanAnswer | null; className?: string }) {
  if (!answer) return null
  return (
    <section className={cn('space-y-2', className)} aria-live="polite">
      <h3 className="font-montserrat text-sm font-semibold">Last scan</h3>
      <ul className="space-y-1.5">
        {answer.results.map((r: ScanResult) => {
          const look = RESULT[r.result]
          return (
            <li key={r.epc} className="flex items-start gap-2 text-sm">
              <look.icon className={cn('mt-0.5 size-4 shrink-0', look.className)} aria-hidden="true" />
              <span className="min-w-0 flex-1">
                <span className="font-mono text-xs">{r.item?.sku ?? r.epc}</span> <span className={look.className}>{look.word}</span>
                {r.reason && <span className="block text-xs text-muted-foreground">{r.reason}</span>}
              </span>
              {r.item && <StatusChip status={r.item.status} />}
            </li>
          )
        })}
        {answer.missing.map((m) => (
          <li key={m.id} className="flex items-start gap-2 text-sm">
            <CircleAlert className="mt-0.5 size-4 shrink-0 text-yellow-700 dark:text-yellow-300" aria-hidden="true" />
            <span className="min-w-0 flex-1">
              <span className="font-mono text-xs">{m.sku}</span> <span className="text-yellow-700 dark:text-yellow-300">Missing</span>
              <span className="block text-xs text-muted-foreground">The giver scanned it, you did not.</span>
            </span>
          </li>
        ))}
      </ul>
    </section>
  )
}
