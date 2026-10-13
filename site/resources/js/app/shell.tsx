/** The frame around every office page: the background, the menu and the page column. */
import { Link, Outlet, useLocation } from 'react-router'

import { TaskReminder } from '@/app/reminder'
import { Backdrop } from '@/components/backdrop'
import { type NavGroup, Navbar } from '@/components/navbar'
import { Button } from '@/components/ui/button'
import { useSession } from '@/lib/session'
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
  { label: 'Tasks', description: 'Deliveries, swaps and collections, and who drives them', href: '/tasks' },
  { label: 'Mats', description: 'Every mat, where it is now and everything that happened to it', href: '/mats' },
  { label: 'Handovers', description: 'Piles passed between people, and the ones that did not add up', href: '/handovers' },
]

const SETUP = [
  { label: 'Customers', description: 'Customers, their buildings and the zones inside', href: '/customers' },
  { label: 'Contracts', description: 'Which mats lie where, and how often they are swapped', href: '/contracts' },
  { label: 'Mat types and areas', description: 'The kinds of mat and the delivery areas', href: '/setup' },
  { label: 'Users', description: 'Who can log in, and in which role', href: '/users' },
]

export function Shell() {
  const { user, logout } = useSession()
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
          <div className="flex items-center gap-2">
            <span className="hidden text-sm text-muted-foreground sm:inline">{user?.name}</span>
            <Button variant="ghost" size="sm" onClick={() => void logout()}>
              Sign out
            </Button>
          </div>
        }
      />
      <main className={cn('mx-auto p-6 sm:p-12 sm:pt-6', wide ? 'max-w-[1500px]' : 'max-w-6xl')}>
        <TaskReminder />
        <Outlet />
      </main>
    </div>
  )
}
