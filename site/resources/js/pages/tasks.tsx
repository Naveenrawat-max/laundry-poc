/** Tasks: one visit to one building. The office assigns a driver, moves a date, cancels, and checks the proof of a closed stop. */
import { useState } from 'react'

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
import { message } from '@/lib/api'
import { useAction, useLoad } from '@/lib/hooks'
import { TASK_TYPE, day, today, when } from '@/lib/labels'
import { useSession } from '@/lib/session'
import type { Contract, Task, User } from '@/lib/types'

type StatusFilter = 'open' | 'done' | 'cancelled' | 'all'

const STATUS_OPTIONS = [
  { value: 'open', label: 'Open' },
  { value: 'done', label: 'Done' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'all', label: 'All' },
] as const

export function TasksPage() {
  const { api } = useSession()
  const toast = useToast()
  const [status, setStatus] = useState<StatusFilter>('open')
  const [type, setType] = useState('')
  const query = new URLSearchParams({ ...(status !== 'all' ? { status } : {}), ...(type ? { type } : {}) }).toString()
  const tasks = useLoad(() => api.get<{ tasks: Task[] }>(`/tasks${query ? `?${query}` : ''}`).then((r) => r.tasks), [api, query], 20000)
  const drivers = useLoad(() => api.get<{ rows: User[] }>('/users').then((r) => r.rows.filter((u) => u.role === 'driver' && u.is_active)), [api])
  const contracts = useLoad(() => api.get<{ rows: Contract[] }>('/contracts').then((r) => r.rows.filter((c) => c.status !== 'draft')), [api])

  const [moving, setMoving] = useState<{ task: Task; due: string } | null>(null)
  const [cancelling, setCancelling] = useState<Task | null>(null)
  const [proof, setProof] = useState<Task | null>(null)
  const [collection, setCollection] = useState<{ contract_id: number | ''; building_id: number | ''; due_date: string } | null>(null)
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
    setCollection(null)
    act.clear()
  }
  const finish = async () => {
    close()
    await tasks.reload()
  }

  const collectionBuildings = (() => {
    const contract = contracts.data?.find((c) => c.id === collection?.contract_id)
    return [...new Map(contract?.lines.map((l) => [l.building_id, l.building_name])).entries()]
  })()

  return (
    <>
      <PageHead title="Tasks">
        Every task is <span className="highlight">one visit to one building</span>. Give it a driver and it shows up on his handheld.
      </PageHead>

      <Toolbar info={<Count n={tasks.data?.length ?? 0} noun="task" />}>
        <Select aria-label="Type" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All types</option>
          <option value="delivery">Deliveries</option>
          <option value="swap">Swaps</option>
          <option value="collection">Collections</option>
        </Select>
        <Segmented label="Status" options={STATUS_OPTIONS} value={status} onChange={setStatus} />
        <Button variant="solid" iconRight={null} onClick={() => setCollection({ contract_id: contracts.data?.[0]?.id ?? '', building_id: '', due_date: now })}>
          New collection
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
                  <TableRow key={t.id}>
                    <TableCell className="whitespace-nowrap">
                      {day(t.due_date)}
                      {t.status === 'open' && t.due_date < now && (
                        <Chip tone="danger" className="ml-2">
                          Late
                        </Chip>
                      )}
                    </TableCell>
                    <TableCell>{TASK_TYPE[t.type]}</TableCell>
                    <TableCell>
                      <span className="block font-medium">{t.building_name}</span>
                      <span className="block text-xs text-muted-foreground">
                        {t.customer_name}, {t.city} ({t.area_name})
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
                  </TableRow>
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
        open={collection !== null}
        title="New collection task"
        description="The driver only picks mats up. Nothing is laid and no next swap is planned. Use it when a contract ends."
        submitLabel="Create task"
        busy={act.busy}
        error={act.error}
        onSubmit={() =>
          void act.run(async () => {
            await api.post('/tasks', { contract_id: Number(collection!.contract_id), building_id: Number(collection!.building_id), due_date: collection!.due_date })
            await finish()
          })
        }
        onClose={close}
      >
        <Field label="Contract" hint={contracts.data?.length === 0 ? 'No started contract yet.' : undefined}>
          <Select value={collection?.contract_id ?? ''} onChange={(e) => setCollection((c) => (c ? { ...c, contract_id: Number(e.target.value), building_id: '' } : c))} wrapperClassName="w-full" required>
            {contracts.data?.map((c) => (
              <option key={c.id} value={c.id}>
                {c.customer_name} ({c.status})
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Building">
          <Select value={collection?.building_id ?? ''} onChange={(e) => setCollection((c) => (c ? { ...c, building_id: Number(e.target.value) } : c))} wrapperClassName="w-full" required>
            <option value="" disabled>
              Choose a building
            </option>
            {collectionBuildings.map(([id, name]) => (
              <option key={id} value={id}>
                {name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Due date">
          <Input type="date" value={collection?.due_date ?? ''} onChange={(e) => setCollection((c) => (c ? { ...c, due_date: e.target.value } : c))} required />
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
        {cancelling && `The ${TASK_TYPE[cancelling.type].toLowerCase()} at ${cancelling.building_name} disappears from the driver's list. A cancelled task cannot be opened again, and no next swap is planned from it.`}
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
