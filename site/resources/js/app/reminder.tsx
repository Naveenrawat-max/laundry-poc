/** The reminder on top of every office page: tasks that are late, due today, or due tomorrow and still without a driver. */
import { useEffect } from 'react'
import { Link } from 'react-router'

import { Alert } from '@/components/ui/alert'
import { useLoad } from '@/lib/hooks'
import { today } from '@/lib/labels'
import { useSession } from '@/lib/session'
import type { Task } from '@/lib/types'

const TITLE = document.title

function dayAfter(date: string): string {
  const [y, m, d] = date.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d + 1)).toISOString().slice(0, 10)
}

const tasks = (n: number, one: string, many: string) => `${n} ${n === 1 ? `task ${one}` : `tasks ${many}`}`

export function TaskReminder() {
  const { api } = useSession()
  // ponytail: reads the open task list the Tasks page reads, once a minute, so a change shows after a minute at most.
  // Give it its own count endpoint when the list outgrows its 200 rows.
  const open = useLoad(() => api.get<{ tasks: Task[] }>('/tasks?status=open').then((r) => r.tasks), [api], 60000)
  const now = today()
  const tomorrow = dayAfter(now)
  const list = open.data ?? []
  const late = list.filter((t) => t.due_date < now).length
  const dueToday = list.filter((t) => t.due_date === now)
  const todayNoDriver = dueToday.filter((t) => t.assigned_to === null).length
  const tomorrowNoDriver = list.filter((t) => t.due_date === tomorrow && t.assigned_to === null).length
  const urgent = late + dueToday.length

  // The number also stands in the tab's title, so it is seen while the office works in another tab.
  useEffect(() => {
    document.title = urgent ? `(${urgent}) ${TITLE}` : TITLE
    return () => {
      document.title = TITLE
    }
  }, [urgent])

  if (urgent + tomorrowNoDriver === 0) return null
  return (
    <Alert tone={late ? 'danger' : 'warning'} className="mb-6">
      {late > 0 && `${tasks(late, 'is', 'are')} late. `}
      {dueToday.length > 0 && `${tasks(dueToday.length, 'is', 'are')} due today${todayNoDriver ? `, ${todayNoDriver} without a driver` : ''}. `}
      {tomorrowNoDriver > 0 && `${tasks(tomorrowNoDriver, 'is', 'are')} due tomorrow and ${tomorrowNoDriver === 1 ? 'has' : 'have'} no driver yet. `}
      <Link to="/tasks" className="underline underline-offset-4 outline-none hover:text-brand focus-visible:text-brand">
        Open the tasks
      </Link>
    </Alert>
  )
}
