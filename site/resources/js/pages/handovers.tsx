/** Handovers: every pile that changed hands, and the mats only one side scanned. */
import { Check, Minus } from 'lucide-react'
import { useState } from 'react'
import { useSearchParams } from 'react-router'

import { Count, Empty, FormDialog, Load, PageHead, StatusChip, Toolbar } from '@/app/ui'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Chip } from '@/components/ui/chip'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Drawer, DrawerContent } from '@/components/ui/drawer'
import { Field } from '@/components/ui/field'
import { Segmented } from '@/components/ui/segmented'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { useAction, useLoad } from '@/lib/hooks'
import { HANDOVER, when } from '@/lib/labels'
import { useSession } from '@/lib/session'
import type { Handover, HandoverIssue, HandoverRow } from '@/lib/types'

const VIEWS = [
  { value: 'all', label: 'All handovers' },
  { value: 'issues', label: 'Issues' },
] as const

function Tick({ at }: { at: string | null }) {
  return at ? <Check className="size-4 text-green-700 dark:text-green-400" aria-label={`Scanned ${when(at)}`} /> : <Minus className="size-4 text-muted-foreground" aria-label="Not scanned" />
}

export function HandoversPage() {
  const { api } = useSession()
  const [params, setParams] = useSearchParams()
  const view = params.get('view') === 'issues' ? 'issues' : 'all'
  const handovers = useLoad(() => api.get<{ handovers: HandoverRow[] }>('/handovers').then((r) => r.handovers), [api], 15000)
  const issues = useLoad(() => api.get<{ issues: HandoverIssue[] }>('/handovers?issues=1').then((r) => r.issues), [api], 15000)
  const [openId, setOpenId] = useState<number | null>(null)
  const [resolving, setResolving] = useState<HandoverIssue | null>(null)
  const [note, setNote] = useState('')
  const save = useAction()

  return (
    <>
      <PageHead title="Handovers">
        A mat changes hands only when <span className="highlight">both sides scanned it</span>. The ones that did not add up wait here for you.
      </PageHead>

      <Toolbar info={view === 'all' ? <Count n={handovers.data?.length ?? 0} noun="handover" /> : <Count n={issues.data?.length ?? 0} noun="open issue" />}>
        <Segmented label="View" options={VIEWS} value={view} onChange={(v) => setParams(v === 'issues' ? { view: 'issues' } : {}, { replace: true })} />
      </Toolbar>

      {view === 'all' ? (
        <Load state={handovers}>
          {(rows) =>
            rows.length === 0 ? (
              <Empty>No handovers yet. They start on a handheld, when someone hands a pile to someone else.</Empty>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Handover</TableHead>
                    <TableHead>From</TableHead>
                    <TableHead>To</TableHead>
                    <TableHead className="text-right">Given</TableHead>
                    <TableHead className="text-right">Received</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Opened</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((h) => (
                    <TableRow key={h.id} className="cursor-pointer" onClick={() => setOpenId(h.id)}>
                      <TableCell>
                        <button type="button" className="cursor-pointer text-left font-medium outline-none hover:text-brand focus-visible:underline" onClick={() => setOpenId(h.id)}>
                          {HANDOVER[h.type].name}
                        </button>
                        <span className="block text-xs text-muted-foreground">{HANDOVER[h.type].route}</span>
                      </TableCell>
                      <TableCell>{h.giver_name}</TableCell>
                      <TableCell>{h.receiver_name}</TableCell>
                      <TableCell className="text-right tabular-nums">{h.given}</TableCell>
                      <TableCell className="text-right tabular-nums">{h.received}</TableCell>
                      <TableCell>{h.status === 'open' ? <Chip tone="success">Open</Chip> : <Chip>Closed</Chip>}</TableCell>
                      <TableCell className="whitespace-nowrap">{when(h.created_at)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )
          }
        </Load>
      ) : (
        <Load state={issues}>
          {(rows) =>
            rows.length === 0 ? (
              <Empty>No open issues. Every closed handover added up.</Empty>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Mat</TableHead>
                    <TableHead>What happened</TableHead>
                    <TableHead>Handover</TableHead>
                    <TableHead>Mat is now</TableHead>
                    <TableHead className="w-0" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((issue) => (
                    <TableRow key={`${issue.handover_id}-${issue.item_id}`}>
                      <TableCell>
                        <span className="font-mono text-xs font-medium">{issue.sku}</span>
                        <span className="block text-xs text-muted-foreground">{issue.type_name}</span>
                      </TableCell>
                      <TableCell>
                        {issue.received_at === null ? `${issue.giver_name} scanned it out, ${issue.receiver_name} never scanned it in.` : `${issue.receiver_name} scanned it in, ${issue.giver_name} never scanned it out.`}
                      </TableCell>
                      <TableCell>
                        <button type="button" className="cursor-pointer text-left outline-none hover:text-brand focus-visible:underline" onClick={() => setOpenId(issue.handover_id)}>
                          {HANDOVER[issue.type].name}
                        </button>
                        <span className="block text-xs text-muted-foreground">Closed {when(issue.closed_at)}</span>
                      </TableCell>
                      <TableCell>
                        <StatusChip status={issue.status} />
                      </TableCell>
                      <TableCell>
                        <Button
                          size="sm"
                          onClick={() => {
                            setNote('')
                            setResolving(issue)
                          }}
                        >
                          Mark handled
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )
          }
        </Load>
      )}

      <HandoverDrawer
        id={openId}
        onClose={() => setOpenId(null)}
        onChanged={() => {
          void handovers.reload()
          void issues.reload()
        }}
      />

      <FormDialog
        open={resolving !== null}
        title={`Mark ${resolving?.sku ?? ''} as handled`}
        description="This only closes the issue. If the mat is somewhere else than the system thinks, correct its status in the mat list as well."
        submitLabel="Mark handled"
        busy={save.busy}
        error={save.error}
        onSubmit={() =>
          void save.run(async () => {
            await api.post(`/handovers/${resolving!.handover_id}/items/${resolving!.item_id}/resolve`, { note })
            setResolving(null)
            await issues.reload()
          })
        }
        onClose={() => {
          setResolving(null)
          save.clear()
        }}
      >
        <Field label="What did you find out" hint="Required. For example: was left on the shelf.">
          <Textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} required rows={3} autoFocus />
        </Field>
      </FormDialog>
    </>
  )
}

/** One handover with its pile: which mat got which of the two scans. */
function HandoverDrawer({ id, onClose, onChanged }: { id: number | null; onClose: () => void; onChanged: () => void }) {
  const { api } = useSession()
  const state = useLoad(() => (id === null ? Promise.resolve(null) : api.get<{ handover: Handover }>(`/handovers/${id}`).then((r) => r.handover)), [api, id], id === null ? undefined : 5000)
  const [closing, setClosing] = useState(false)
  const act = useAction()
  const h = state.data
  const oneSided = h?.items.filter((i) => !i.given_at || (!i.received_at && h.type !== 'washing_to_inventory')).length ?? 0

  return (
    <>
      <Drawer open={id !== null && !closing} onOpenChange={(open) => !open && onClose()}>
        <DrawerContent title={h ? HANDOVER[h.type].name : 'Handover'} description={h ? `${h.giver.name} to ${h.receiver.name}. Opened ${when(h.created_at)}.` : undefined} className="w-[min(30rem,95vw)]">
          {state.error && <Alert tone="danger">{state.error}</Alert>}
          {h && (
            <>
              <div className="flex items-center justify-between gap-3">
                {h.status === 'open' ? <Chip tone="success">Open</Chip> : <Chip>Closed {when(h.closed_at)}</Chip>}
                {h.status === 'open' && (
                  <Button size="sm" onClick={() => setClosing(true)}>
                    Close handover
                  </Button>
                )}
              </div>
              {h.items.length === 0 ? (
                <p className="text-sm text-muted-foreground">Nobody has scanned a mat into this handover yet.</p>
              ) : (
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="text-[11px] tracking-wide text-muted-foreground uppercase">
                      <th className="py-1.5 font-medium">Mat</th>
                      <th className="py-1.5 font-medium">Given</th>
                      <th className="py-1.5 font-medium">Received</th>
                      <th className="py-1.5 font-medium">Now</th>
                    </tr>
                  </thead>
                  <tbody>
                    {h.items.map((item) => (
                      <tr key={item.id} className="border-t border-border">
                        <td className="py-2 font-mono text-xs">{item.sku}</td>
                        <td className="py-2">
                          <Tick at={item.given_at} />
                        </td>
                        <td className="py-2">
                          <Tick at={item.received_at} />
                        </td>
                        <td className="py-2">
                          <StatusChip status={item.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </>
          )}
        </DrawerContent>
      </Drawer>
      {/* Outside the drawer, and the drawer steps aside while this is open: a drawer keeps the pointer and the keyboard to itself. */}
      <ConfirmDialog
        open={closing && h != null}
        tone="neutral"
        title="Close this handover?"
        confirmLabel="Close handover"
        busyLabel="Closing"
        cancelLabel="Leave it open"
        busy={act.busy}
        error={act.error}
        onConfirm={() =>
          void act.run(async () => {
            await api.post(`/handovers/${h!.id}/close`)
            setClosing(false)
            await state.reload()
            onChanged()
          })
        }
        onCancel={() => {
          setClosing(false)
          act.clear()
        }}
      >
        Normally the receiver closes it on the handheld. After closing, nobody can scan into it any more
        {oneSided > 0 ? `, and the ${oneSided} ${oneSided === 1 ? 'mat' : 'mats'} with only one scan become issues for you to handle.` : '.'}
      </ConfirmDialog>
    </>
  )
}
