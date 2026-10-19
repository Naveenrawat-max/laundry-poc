/** Tasks: one visit to one customer location, with driver assignment, scheduling and proof. */
import { Fragment, useState } from 'react'
import { Link } from 'react-router'

import { OrderProducts, OrderSummary, SwapDue } from '@/app/orders'
import { Count, Empty, Fact, FormDialog, Load, PageHead, Toolbar } from '@/app/ui'
import { Button } from '@/components/ui/button'
import { Chip } from '@/components/ui/chip'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Dialog, DialogActions } from '@/components/ui/dialog'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Segmented } from '@/components/ui/segmented'
import { Select } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/components/ui/toast'
import { type Api, message } from '@/lib/api'
import { useAction, useLoad } from '@/lib/hooks'
import { TASK_TYPE, day, today, when } from '@/lib/labels'
import { useSession } from '@/lib/session'
import type { Contract, Order, Stop, Task, User } from '@/lib/types'

type StatusFilter = 'open' | 'done' | 'cancelled' | 'all'

const STATUS_OPTIONS = [
  { value: 'open', label: 'Open' },
  { value: 'done', label: 'Done' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'all', label: 'All' },
] as const

/** Expanding a task fetches its location requirements and persisted linked order. */
function TaskDetails({ api, task }: { api: Api; task: Task }) {
  const detail = useLoad(() => api.get<{ task: Stop }>(`/tasks/${task.id}`).then((r) => r.task), [api, task.id])
  const order = useLoad(() => task.order_id ? api.get<{ order: Order }>(`/orders/${task.order_id}`).then((r) => r.order) : Promise.resolve(null), [api, task.order_id])
  return <div className="space-y-3 p-2">
    <p className="font-medium">{task.customer_name} · {task.building_name}</p>
    <p className="text-sm text-muted-foreground">{task.street}, {task.postcode} {task.city}{task.contact_name ? ` · ${task.contact_name}` : ''}{task.contact_phone ? ` · ${task.contact_phone}` : ''}</p>
    <p className="text-sm">Task #{task.id} · Contract #{task.contract_id} · {TASK_TYPE[task.type]} due {day(task.due_date)}</p>
    {task.issue_note && <p className="text-sm">Driver note: {task.issue_note}</p>}
    {task.order_id ? <Load state={order}>{(linked) => linked && <><OrderSummary order={linked} /><OrderProducts order={linked} stage={linked.status === 'pending_qc' ? 'qc' : 'scan'} /><Link to={`${linked.type === 'delivery' ? '/ready-to-use' : '/pickup-orders'}?order=${linked.id}`} className="text-sm underline">Open {linked.number}</Link></>}</Load> : <Load state={detail}>{(stop) => <><p className="text-sm text-muted-foreground">No linked order. This task follows the contract requirements below.</p><div className="grid gap-4 sm:grid-cols-2">
      <section><h3 className="mb-2 font-medium">Contract requirements</h3>{stop.expected.length ? <ul className="space-y-1 text-sm">{stop.expected.map((line) => <li key={`${line.zone_id}:${line.item_type_id}`}>{line.quantity} × {line.type_name} · {line.zone_name}</li>)}</ul> : <p className="text-sm text-muted-foreground">No delivery quantities required.</p>}</section>
      <section><h3 className="mb-2 font-medium">Mats currently at this location</h3>{stop.in_building.length ? <ul className="space-y-2 text-sm">{stop.in_building.map((mat) => <li key={mat.id}><span className="flex flex-wrap items-center gap-x-3"><span className="font-mono">{mat.sku}</span><SwapDue date={mat.swap_due_date} /></span><span className="text-xs text-muted-foreground">{mat.type} · {stop.zones.find((zone) => zone.id === mat.zone_id)?.name}</span></li>)}</ul> : <p className="text-sm text-muted-foreground">No mats currently at this location.</p>}</section>
    </div></>}</Load>}
  </div>
}

export function TasksPage() {
  const { api } = useSession()
  const toast = useToast()
  const [status, setStatus] = useState<StatusFilter>('open')
  const [type, setType] = useState('')
  const [expanded, setExpanded] = useState<number | null>(null)
  const query = new URLSearchParams({ ...(status !== 'all' ? { status } : {}), ...(type ? { type } : {}) }).toString()
  const tasks = useLoad(() => api.get<{ tasks: Task[] }>(`/tasks${query ? `?${query}` : ''}`).then((r) => r.tasks), [api, query], 20000)
  const drivers = useLoad(() => api.get<{ rows: User[] }>('/users').then((r) => r.rows.filter((u) => u.role === 'driver' && u.is_active)), [api])
  const started = useLoad(() => api.get<{ rows: Contract[] }>('/contracts').then((r) => r.rows.filter((c) => c.status !== 'draft')), [api])

  const [moving, setMoving] = useState<{ task: Task; due: string } | null>(null)
  const [cancelling, setCancelling] = useState<Task | null>(null)
  const [proof, setProof] = useState<Task | null>(null)
  const [creating, setCreating] = useState<{ type: 'swap' | 'collection'; contract_id: number | ''; building_id: number | ''; due_date: string } | null>(null)
  // A swap needs a running contract. A collection also fits an ended one: that is when the mats come back.
  const contractsFor = (type: 'swap' | 'collection') => (started.data ?? []).filter((c) => type === 'collection' || c.status === 'active')
  const contracts = contractsFor(creating?.type ?? 'swap')
  const act = useAction()
  const now = today()

  // The driver just picked for a task, shown until the refreshed list is on screen. Without it the
  // dropdown would jump back to the old driver for as long as the server takes to answer.
  const [picked, setPicked] = useState<Record<number, string>>({})

  const assign = async (task: Task, driverId: string) => {
    setPicked((p) => ({ ...p, [task.id]: driverId }))
    try {
      await api.patch(`/tasks/${task.id}`, { assigned_to: driverId ? Number(driverId) : null })
      toast(driverId ? 'Driver assigned.' : 'Task taken back from the driver.', { tone: 'success' })
    } catch (e) {
      toast(message(e), { tone: 'danger' })
    }
    await tasks.reload()
    setPicked(({ [task.id]: _shown, ...rest }) => rest)
  }

  const close = () => {
    setMoving(null)
    setCancelling(null)
    setCreating(null)
    act.clear()
  }
  const finish = async () => {
    close()
    await tasks.reload()
  }

  const buildings = (() => {
    const contract = contracts.find((c) => c.id === creating?.contract_id)
    return [...new Map(contract?.lines.filter((l) => contract.building_id === null || l.building_id === contract.building_id).map((l) => [l.building_id, l.building_name])).entries()]
  })()

  return (
    <>
      <PageHead title="Tasks">
        Every task is <span className="highlight">one visit to one location</span>. Give it a driver and it shows up on their handheld.
      </PageHead>

      <Toolbar info={<Count n={tasks.data?.length ?? 0} noun="task" />}>
        <Select aria-label="Type" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All types</option>
          <option value="delivery">Deliveries</option>
          <option value="swap">Swaps</option>
          <option value="collection">Collections</option>
        </Select>
        <Segmented label="Status" options={STATUS_OPTIONS} value={status} onChange={setStatus} />
        <Button variant="solid" iconRight={null} onClick={() => setCreating({ type: 'swap', contract_id: contractsFor('swap')[0]?.id ?? '', building_id: '', due_date: now })}>
          New task
        </Button>
      </Toolbar>

      <Load state={tasks}>
        {(rows) =>
          rows.length === 0 ? (
            <Empty>No tasks here. Starting a contract creates its delivery tasks, and closing a stop plans the next swap.</Empty>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Due</TableHead>
                  <TableHead>Task</TableHead>
                  <TableHead>Stop</TableHead>
                  <TableHead>Driver</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-0" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((t) => (
                  <Fragment key={t.id}><TableRow>
                    <TableCell className="whitespace-nowrap">
                      {day(t.due_date)}
                      {t.status === 'open' && t.due_date < now && (
                        <Chip tone="danger" className="ml-2">
                          Late
                        </Chip>
                      )}
                    </TableCell>
                    <TableCell><button type="button" className="cursor-pointer rounded-sm text-left font-medium underline underline-offset-4 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50" aria-expanded={expanded === t.id} aria-controls={`task-details-${t.id}`} onClick={() => setExpanded((id) => id === t.id ? null : t.id)}>{TASK_TYPE[t.type]} #{t.id} · {expanded === t.id ? 'Hide details' : 'Details'}</button>{t.order_number && <span className="block text-xs text-muted-foreground">{t.order_number}</span>}</TableCell>
                    <TableCell>
                      <span className="block font-medium">{t.building_name}</span>
                      <span className="block text-xs text-muted-foreground">
                        {t.customer_name}, {t.city}{t.area_name ? ` (${t.area_name})` : ''}
                      </span>
                    </TableCell>
                    <TableCell>
                      {t.status === 'open' ? (
                        <Select aria-label={`Driver for ${t.building_name}`} value={picked[t.id] ?? t.assigned_to ?? ''} onChange={(e) => void assign(t, e.target.value)}>
                          <option value="">No driver yet</option>
                          {drivers.data?.map((d) => (
                            <option key={d.id} value={d.id}>
                              {d.name}
                            </option>
                          ))}
                        </Select>
                      ) : (
                        (t.assigned_name ?? <span className="text-muted-foreground">None</span>)
                      )}
                    </TableCell>
                    <TableCell>
                      {t.status === 'open' && <Chip>Open</Chip>}
                      {t.status === 'done' && <Chip tone="success">Done</Chip>}
                      {t.status === 'cancelled' && <Chip>Cancelled</Chip>}
                      {t.issue_note && (
                        <Chip tone="danger" className="ml-1.5">
                          Note
                        </Chip>
                      )}
                    </TableCell>
                    <TableCell className="text-right whitespace-nowrap">
                      {t.status === 'open' && (
                        <>
                          <Button variant="ghost" size="sm" onClick={() => setMoving({ task: t, due: t.due_date })}>
                            Move
                          </Button>
                          <Button variant="ghost" size="sm" onClick={() => setCancelling(t)}>
                            Cancel
                          </Button>
                        </>
                      )}
                      {t.status === 'done' && (
                        <Button variant="ghost" size="sm" onClick={() => setProof(t)}>
                          Proof
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>{expanded === t.id && <TableRow id={`task-details-${t.id}`}><TableCell colSpan={6}><TaskDetails api={api} task={t} /></TableCell></TableRow>}</Fragment>
                ))}
              </TableBody>
            </Table>
          )
        }
      </Load>

      <FormDialog
        open={moving !== null}
        title="Move the due date"
        description={moving ? `${TASK_TYPE[moving.task.type]} at ${moving.task.building_name}.` : undefined}
        busy={act.busy}
        error={act.error}
        onSubmit={() => void act.run(async () => api.patch(`/tasks/${moving!.task.id}`, { due_date: moving!.due }).then(finish))}
        onClose={close}
      >
        <Field label="Due date">
          <Input type="date" value={moving?.due ?? ''} onChange={(e) => setMoving((m) => (m ? { ...m, due: e.target.value } : m))} required />
        </Field>
      </FormDialog>

      <FormDialog
        open={creating !== null}
        title="New task"
        description={
          creating?.type === 'collection'
            ? 'The driver only picks mats up. Nothing is laid and no next swap is planned. Use it when a contract ends.'
            : 'The driver lays clean mats and picks up the used ones. Use it for a location that has no visit planned, for example after its swap was cancelled. Closing it plans the next swap as usual.'
        }
        submitLabel="Create task"
        busy={act.busy}
        error={act.error}
        onSubmit={() =>
          void act.run(async () => {
            await api.post('/tasks', { type: creating!.type, contract_id: Number(creating!.contract_id), building_id: Number(creating!.building_id), due_date: creating!.due_date })
            await finish()
          })
        }
        onClose={close}
      >
        <Field label="Kind of task">
          <Select
            value={creating?.type ?? 'swap'}
            onChange={(e) => {
              const type = e.target.value as 'swap' | 'collection'
              setCreating((c) => (c ? { ...c, type, contract_id: contractsFor(type)[0]?.id ?? '', building_id: '' } : c))
            }}
            wrapperClassName="w-full"
          >
            <option value="swap">Swap</option>
            <option value="collection">Collection</option>
          </Select>
        </Field>
        <Field label="Contract" hint={contracts.length === 0 ? (creating?.type === 'swap' ? 'No active contract.' : 'No started contract yet.') : undefined}>
          <Select value={creating?.contract_id ?? ''} onChange={(e) => setCreating((c) => (c ? { ...c, contract_id: Number(e.target.value), building_id: '' } : c))} wrapperClassName="w-full" required>
            {contracts.map((c) => (
              <option key={c.id} value={c.id}>
                {c.customer_name} · {c.building_name ?? 'Legacy multiple locations'} · Starts {day(c.start_date)} · D+{c.swap_interval_days} ({c.status})
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Location">
          <Select value={creating?.building_id ?? ''} onChange={(e) => setCreating((c) => (c ? { ...c, building_id: Number(e.target.value) } : c))} wrapperClassName="w-full" required>
            <option value="" disabled>
              Choose a location
            </option>
            {buildings.map(([id, name]) => (
              <option key={id} value={id}>
                {name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Due date">
          <Input type="date" value={creating?.due_date ?? ''} onChange={(e) => setCreating((c) => (c ? { ...c, due_date: e.target.value } : c))} required />
        </Field>
      </FormDialog>

      <ConfirmDialog
        open={cancelling !== null}
        title="Cancel this task?"
        confirmLabel="Cancel the task"
        busyLabel="Cancelling"
        cancelLabel="Keep it"
        busy={act.busy}
        error={act.error}
        onConfirm={() => void act.run(async () => api.patch(`/tasks/${cancelling!.id}`, { status: 'cancelled' }).then(finish))}
        onCancel={close}
      >
        {cancelling && `The ${TASK_TYPE[cancelling.type].toLowerCase()} at ${cancelling.building_name} disappears from the driver's list. A cancelled task cannot be opened again, and no next swap is planned from it.${cancelling.type === 'collection' ? '' : ' To visit this location again, make a swap with "New task".'}`}
      </ConfirmDialog>

      <Dialog open={proof !== null} onClose={() => setProof(null)} title="Proof of the stop" description={proof ? `${TASK_TYPE[proof.type]} at ${proof.building_name}, closed ${when(proof.completed_at)}.` : undefined} className="sm:max-w-2xl">
        {proof && (
          <>
            <dl className="mt-4 grid gap-3 sm:grid-cols-3">
              <Fact label="Signed by">{proof.signed_name}</Fact>
              <Fact label="Driver">{proof.assigned_name}</Fact>
              <Fact label="Customer">{proof.customer_name}</Fact>
            </dl>
            {proof.issue_note && <p className="mt-3 rounded-xl border border-red-600/30 px-3 py-2 text-sm text-red-800 dark:border-red-400/30 dark:text-red-300">Driver's note: {proof.issue_note}</p>}
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <figure>
                <img src={api.url(`/tasks/${proof.id}/photo`)} alt="Photo taken at the stop" className="aspect-video w-full rounded-lg border border-card-edge object-cover" />
                <figcaption className="mt-1 text-xs text-muted-foreground">Photo</figcaption>
              </figure>
              <figure>
                <img src={api.url(`/tasks/${proof.id}/signature`)} alt={`Signature of ${proof.signed_name}`} className="aspect-video w-full rounded-lg border border-card-edge bg-white object-contain" />
                <figcaption className="mt-1 text-xs text-muted-foreground">Signature</figcaption>
              </figure>
            </div>
            <DialogActions>
              <Button onClick={() => setProof(null)}>Close</Button>
            </DialogActions>
          </>
        )}
      </Dialog>
    </>
  )
}
