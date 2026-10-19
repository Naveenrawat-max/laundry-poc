/** The frame around every office page: the background, the menu and the page column. */
import { Link, Outlet, useLocation } from 'react-router'

import { TaskReminder } from '@/app/reminder'
import { OrderReturnsReminder } from '@/app/order-returns'
import { Backdrop } from '@/components/backdrop'
import { type NavGroup, Navbar } from '@/components/navbar'
import { Button } from '@/components/ui/button'
import { Alert } from '@/components/ui/alert'
import { useAction, useLoad } from '@/lib/hooks'
import { day } from '@/lib/labels'
import { useSession } from '@/lib/session'
import type { SimulationClock } from '@/lib/types'
import { cn } from '@/lib/utils'

/** The product's own wordmark. Text only: this is Proti's system, so it carries no other brand. */
export function Wordmark({ to = '/' }: { to?: string }) {
  return (
    <Link to={to} aria-label="Home" className="font-onest text-xl font-medium tracking-tight whitespace-nowrap">
      Proti <span className="text-muted-foreground">mats</span>
    </Link>
  )
}

const OPERATIONS = [
  { label: 'Registration', description: 'Choose a stocked SKU and map two demo tags to one mat', href: '/registration' },
  { label: 'Ready to deliver', description: 'Create delivery orders and release repaired mats', href: '/ready-to-use' },
  { label: 'Pickup orders', description: 'Collect delivered customer mats for washing or inventory', href: '/pickup-orders' },
  { label: 'Tasks', description: 'Deliveries, swaps and collections, and who drives them', href: '/tasks' },
  { label: 'Mats', description: 'Every mat, where it is now and everything that happened to it', href: '/mats' },
  { label: 'Handovers', description: 'Piles passed between people, and the ones that did not add up', href: '/handovers' },
]

const SETUP = [
  { label: 'Customers', description: 'Customers and their service locations', href: '/customers' },
  { label: 'Contracts', description: 'Which mats lie where, and how often they are swapped', href: '/contracts' },
  { label: 'Categories and SKUs', description: 'Product categories, sizes, colours and stock', href: '/setup' },
  { label: 'Users', description: 'Who can log in, and in which role', href: '/users' },
]

export function Shell() {
  const { user, api, logout } = useSession()
  const clock = useLoad(() => api.get<{ clock: SimulationClock }>('/simulation-clock').then((r) => r.clock), [api], 30000)
  const clockAction = useAction()
  const moveClock = (reset = false) => void clockAction.run(async () => {
    const result = reset ? await api.del<{ clock: SimulationClock }>('/simulation-clock') : await api.post<{ clock: SimulationClock }>('/simulation-clock/skip')
    clock.adopt(result.clock)
  })
  const { pathname } = useLocation()
  const at = (href: string) => pathname === href || pathname.startsWith(href + '/')
  const mark = <T extends { href: string }>(items: T[]) => items.map((item) => ({ ...item, active: at(item.href) }))

  const groups: NavGroup[] = [
    { label: 'Workflow', href: '/workflow', active: at('/workflow') },
    { label: 'Dashboard', href: '/dashboard', active: at('/dashboard') },
    { label: 'Operations', items: mark(OPERATIONS), active: OPERATIONS.some((i) => at(i.href)) },
    { label: 'Setup', items: mark(SETUP), active: SETUP.some((i) => at(i.href)) },
    { label: 'Handheld', href: '/handheld', active: at('/handheld') },
  ]

  // The handheld page shows up to four devices side by side and needs more than the usual column.
  const wide = at('/handheld')

  return (
    <div className="min-h-dvh overflow-y-clip">
      <Backdrop />
      <Navbar
        layout="inline"
        logo={<Wordmark to="/workflow" />}
        groups={groups}
        actions={
          <div className="flex flex-wrap items-center justify-end gap-2">
            <span className="hidden text-sm text-muted-foreground sm:inline">{user?.name}</span>
            {user?.role === 'office' && clock.data?.enabled && <>
              <span className="text-xs text-muted-foreground" role="status">{clock.data.simulated ? 'Simulation date' : 'Business date'}: {day(clock.data.today)}</span>
              <Button variant="outline" size="sm" disabled={clockAction.busy || !clock.data.next_due_date} title={clock.data.next_due_date ? `Next due ${day(clock.data.next_due_date)}` : 'No future due dates'} onClick={() => moveClock()}>{clockAction.busy ? 'Updating date' : 'Skip to next due date'}</Button>
              {clock.data.simulated && <Button variant="ghost" size="sm" disabled={clockAction.busy} onClick={() => moveClock(true)}>Reset date</Button>}
            </>}
            <Button variant="ghost" size="sm" onClick={() => void logout()}>
              Sign out
            </Button>
          </div>
        }
      />
      <main className={cn('mx-auto p-6 sm:p-12 sm:pt-6', wide ? 'max-w-[1500px]' : 'max-w-6xl')}>
        {(clockAction.error || clock.error) && <Alert tone="danger" className="mb-4">{clockAction.error || `Business date unavailable: ${clock.error}`}</Alert>}
        <TaskReminder />
        <OrderReturnsReminder />
        <Outlet />
      </main>
    </div>
  )
}
