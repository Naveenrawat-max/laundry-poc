/** The dashboard: where the mats are right now, and what the office has to act on today. */
import { Link } from 'react-router'

import { Card, CardBody, CardTitle } from '@/components/ui/card'
import { Chip } from '@/components/ui/chip'
import { Load, PageHead } from '@/app/ui'
import { useLoad } from '@/lib/hooks'
import { STATUS, STATUSES, TASK_TYPE, day, today } from '@/lib/labels'
import { useSession } from '@/lib/session'
import type { Contract, HandoverIssue, HandoverRow, Status, Task } from '@/lib/types'

type Numbers = {
  byStatus: Record<Status, number>
  tasks: Task[]
  issues: HandoverIssue[]
  openHandovers: number
  activeContracts: number
}

const PLACES: { name: string; statuses: Status[] }[] = [
  { name: 'In inventory', statuses: ['registered', 'ready', 'qc_passed', 'rewash', 'repair'] },
  { name: 'On a van', statuses: ['out_for_delivery', 'refused', 'collected'] },
  { name: 'At customers', statuses: ['at_customer'] },
  { name: 'In washing', statuses: ['in_washing'] },
]

function Stat({ label, value, note, to }: { label: string; value: number; note?: string; to: string }) {
  return (
    <Link to={to} className="outline-none">
      <Card className="h-full">
        <CardBody className="gap-1">
          <p className="text-[11px] tracking-wide text-muted-foreground uppercase">{label}</p>
          <p className="font-montserrat text-3xl font-semibold tabular-nums">{value}</p>
          {note && <p className="text-xs text-muted-foreground">{note}</p>}
        </CardBody>
      </Card>
    </Link>
  )
}

export function DashboardPage() {
  const { api } = useSession()
  const state = useLoad<Numbers>(
    async () => {
      const [counts, tasks, issues, handovers, contracts] = await Promise.all([
        api.get<{ counts: Partial<Record<Status, number>> }>('/items/counts'),
        api.get<{ tasks: Task[] }>('/tasks?status=open'),
        api.get<{ issues: HandoverIssue[] }>('/handovers?issues=1'),
        api.get<{ handovers: HandoverRow[] }>('/handovers'),
        api.get<{ rows: Contract[] }>('/contracts'),
      ])
      return {
        // A status no mat has is left out of the answer, so every status starts at zero.
        byStatus: Object.fromEntries(STATUSES.map((s) => [s, counts.counts[s] ?? 0])) as Record<Status, number>,
        tasks: tasks.tasks,
        issues: issues.issues,
        openHandovers: handovers.handovers.filter((h) => h.status === 'open').length,
        activeContracts: contracts.rows.filter((c) => c.status === 'active').length,
      }
    },
    [api],
    30000,
  )

  return (
    <>
      <PageHead title="Dashboard">
        Where every mat is right now, and <span className="highlight">what needs you today</span>.
      </PageHead>

      <div className="mt-8">
        <Load state={state} rows={6}>
          {(n) => {
            const total = STATUSES.reduce((sum, s) => sum + n.byStatus[s], 0)
            const inUse = total - n.byStatus.scrapped
            const now = today()
            const overdue = n.tasks.filter((t) => t.due_date < now)
            const unassigned = n.tasks.filter((t) => t.assigned_to === null)
            const dueToday = n.tasks.filter((t) => t.due_date === now)
            const attention = [...overdue, ...unassigned.filter((t) => !overdue.includes(t))].slice(0, 6)

            return (
              <>
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                  {PLACES.map((place) => (
                    <Stat
                      key={place.name}
                      label={place.name}
                      value={place.statuses.reduce((sum, s) => sum + n.byStatus[s], 0)}
                      note={`of ${inUse} mats in use`}
                      to={`/mats?status=${place.statuses[0]}`}
                    />
                  ))}
                </div>

                <div className="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                  <Stat label="Open tasks" value={n.tasks.length} note={`${dueToday.length} due today`} to="/tasks" />
                  <Stat label="Not assigned yet" value={unassigned.length} note="Waiting for a driver" to="/tasks" />
                  <Stat label="Handover issues" value={n.issues.length} note="Scanned by one side only" to="/handovers?view=issues" />
                  <Stat label="Active contracts" value={n.activeContracts} note={`${n.openHandovers} handovers open now`} to="/contracts" />
                </div>

                <div className="mt-10 grid gap-6 lg:grid-cols-2">
                  <section>
                    <h2 className="text-xl font-medium">Mats by status</h2>
                    <p className="mb-4 text-sm text-muted-foreground">{total} mats registered in total.</p>
                    <ul className="card-chai space-y-2.5 p-4">
                      {STATUSES.map((s) => (
                        <li key={s}>
                          <Link to={`/mats?status=${s}`} className="group/row grid grid-cols-[10.5rem_1fr_2.5rem] items-center gap-3 text-sm outline-none">
                            <span className="truncate text-muted-foreground transition-colors group-hover/row:text-brand">{STATUS[s]}</span>
                            <span className="h-1.5 overflow-hidden rounded-full bg-black/[0.06] dark:bg-white/[0.06]">
                              <span className="block h-full rounded-full bg-primary" style={{ width: total ? `${(n.byStatus[s] / total) * 100}%` : 0 }} />
                            </span>
                            <span className="text-right tabular-nums">{n.byStatus[s]}</span>
                          </Link>
                        </li>
                      ))}
                    </ul>
                  </section>

                  <section>
                    <h2 className="text-xl font-medium">Needs attention</h2>
                    <p className="mb-4 text-sm text-muted-foreground">Late or unassigned tasks, and mats a handover lost track of.</p>
                    <ul className="space-y-2">
                      {attention.map((t) => (
                        <li key={t.id}>
                          <Link to="/tasks" className="card-chai flex items-center justify-between gap-3 p-3 text-sm outline-none">
                            <span className="min-w-0">
                              <span className="block truncate font-medium">
                                {TASK_TYPE[t.type]} at {t.building_name}
                              </span>
                              <span className="block truncate text-xs text-muted-foreground">
                                {t.customer_name}, due {day(t.due_date)}
                              </span>
                            </span>
                            <span className="flex shrink-0 gap-1.5">
                              {t.due_date < now && <Chip tone="danger">Late</Chip>}
                              {t.assigned_to === null && <Chip>No driver</Chip>}
                            </span>
                          </Link>
                        </li>
                      ))}
                      {n.issues.slice(0, 4).map((issue) => (
                        <li key={`${issue.handover_id}-${issue.item_id}`}>
                          <Link to="/handovers?view=issues" className="card-chai flex items-center justify-between gap-3 p-3 text-sm outline-none">
                            <span className="min-w-0">
                              <span className="block truncate font-medium">{issue.sku} was scanned by one side only</span>
                              <span className="block truncate text-xs text-muted-foreground">
                                {issue.giver_name} to {issue.receiver_name}
                              </span>
                            </span>
                            <Chip tone="danger">Issue</Chip>
                          </Link>
                        </li>
                      ))}
                      {attention.length === 0 && n.issues.length === 0 && (
                        <li className="card-chai p-4 text-sm text-muted-foreground">
                          <CardTitle className="mb-1">All clear</CardTitle>
                          Nothing is late, every task has a driver, and every handover added up.
                        </li>
                      )}
                    </ul>
                  </section>
                </div>
              </>
            )
          }}
        </Load>
      </div>
    </>
  )
}
