/** One handheld: the frame that looks like the device, its login, and the screens the logged-in role gets. */
import { ArrowLeft, ArrowRightLeft, Boxes, ChevronRight, ClipboardCheck, Droplets, Inbox, ListChecks, LogOut, type LucideIcon, Search, Truck, Undo2 } from 'lucide-react'
import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react'

import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { OrderNotice, OrderReturnsScreen, OrderTasksScreen } from '@/handheld/orders'
import { type Ctx, GiveScreen, MatListScreen, NeedsScreen, ReceiveScreen, ScanScreen } from '@/handheld/screens'
import { TasksScreen } from '@/handheld/stop'
import { type Api, createApi } from '@/lib/api'
import { useAction } from '@/lib/hooks'
import { ROLE } from '@/lib/labels'
import type { Role, User } from '@/lib/types'
import { cn } from '@/lib/utils'

export type ScannerRole = Exclude<Role, 'office'>

type Screen = { id: string; title: string; hint: string; icon: LucideIcon; render: (ctx: Ctx) => ReactNode }

const lookup: Screen = {
  id: 'lookup',
  title: 'Look up',
  hint: 'What is this mat?',
  icon: Search,
  render: ({ api }) => <ScanScreen api={api} path="/scan/lookup" intro="Scan any mat to see what it is. Nothing changes." scanLabel="Look up" />,
}

/** The screens per role, in the order of the working day. Hiding a screen is not the protection: the server checks every call. */
const SCREENS: Record<ScannerRole, Screen[]> = {
  inventory: [
    { id: 'qc', title: 'Quality check', hint: 'Check delivery orders and mats received back from drivers', icon: ClipboardCheck, render: (c) => <OrderTasksScreen {...c} mode="qc" /> },
    { id: 'receive-pickups', title: 'Receive pickups', hint: 'Customer pickups sent directly to inventory', icon: Inbox, render: (c) => <OrderTasksScreen {...c} mode="inventory-receive" /> },
    { id: 'needs', title: 'What drivers need', hint: 'Mats still to hand out', icon: ListChecks, render: (c) => <NeedsScreen {...c} /> },
    { id: 'give-driver', title: 'Hand over to driver', hint: 'Handover A, scan out', icon: Truck, render: (c) => <GiveScreen {...c} type="inventory_to_driver" /> },
    { id: 'receive-washing', title: 'Receive from washing', hint: 'Acknowledge clean mats handed back by washing', icon: Inbox, render: (c) => <ReceiveScreen {...c} types={['washing_to_inventory']} /> },
    { id: 'receive-returns', title: 'Receive returns', hint: 'Scan refused order mats in from a driver', icon: Undo2, render: (c) => <ReceiveScreen {...c} types={['driver_to_inventory']} /> },
    { id: 'give-washing', title: 'Send to rewash', hint: 'Mats that failed the check', icon: Droplets, render: (c) => <GiveScreen {...c} type="inventory_to_washing" /> },
    { id: 'mats', title: 'Mats in inventory', hint: 'Everything on the shelf', icon: Boxes, render: (c) => <MatListScreen {...c} empty="No mats in inventory." /> },
    lookup,
  ],
  driver: [
    { id: 'tasks', title: 'My tasks', hint: 'Delivery and pickup order checklists', icon: ListChecks, render: (c) => <OrderTasksScreen {...c} mode="driver" /> },
    { id: 'contract-tasks', title: 'Contract tasks', hint: 'Existing scheduled swaps and collections', icon: ListChecks, render: (c) => <TasksScreen {...c} /> },
    { id: 'load', title: 'Load the van', hint: 'Handover A, scan in', icon: Inbox, render: (c) => <ReceiveScreen {...c} types={['inventory_to_driver']} /> },
    { id: 'give-washing', title: 'Hand over to washing', hint: 'Handover B, scan out', icon: Droplets, render: (c) => <GiveScreen {...c} type="driver_to_washing" /> },
    { id: 'give-returns', title: 'Return refused mats', hint: 'Order return: scan out to inventory', icon: Undo2, render: (c) => <OrderReturnsScreen {...c} /> },
    { id: 'mats', title: 'On my van', hint: 'The mats I carry', icon: Boxes, render: (c) => <MatListScreen {...c} empty="Your van is empty." /> },
    lookup,
  ],
  washing: [
    { id: 'incoming-orders', title: 'Incoming deliveries', hint: 'Receive by driver, wash and return to inventory', icon: Truck, render: (c) => <OrderTasksScreen {...c} mode="washing" /> },
    { id: 'receive', title: 'Receive', hint: 'Handover B and rewash, scan in', icon: Inbox, render: (c) => <ReceiveScreen {...c} types={['driver_to_washing', 'inventory_to_washing']} /> },
    { id: 'mats', title: 'In washing', hint: 'Mats in the washing house', icon: Boxes, render: (c) => <MatListScreen {...c} empty="Nothing is in washing." /> },
    { id: 'give-inventory', title: 'Hand over to inventory', hint: 'Handover C, counts the wash', icon: ArrowRightLeft, render: (c) => <GiveScreen {...c} type="washing_to_inventory" /> },
    lookup,
  ],
  outbound: [
    { id: 'needs', title: 'What drivers need', hint: 'Mats still to hand out', icon: ListChecks, render: (c) => <NeedsScreen {...c} /> },
    { id: 'give-driver', title: 'Hand over to driver', hint: 'Handover A, scan out', icon: Truck, render: (c) => <GiveScreen {...c} type="inventory_to_driver" /> },
    lookup,
  ],
}

/** The body of the device: a status strip on top, then whatever the handheld shows. */
export function DeviceFrame({ children, className }: { children: ReactNode; className?: string }) {
  const [clock, setClock] = useState(() => new Date())
  useEffect(() => {
    const timer = window.setInterval(() => setClock(new Date()), 30000)
    return () => window.clearInterval(timer)
  }, [])
  return (
    <div className={cn('flex h-[560px] w-[320px] shrink-0 flex-col overflow-hidden rounded-[2rem] border border-card-edge-hover bg-background', className)}>
      <div className="flex shrink-0 items-center justify-between px-5 pt-2.5 pb-1 font-mono text-[10px] text-muted-foreground">
        <span>{clock.toLocaleTimeString('en-GB', { timeZone: 'Europe/Amsterdam', hour: '2-digit', minute: '2-digit' })}</span>
      </div>
      {children}
    </div>
  )
}

/** A logged-in handheld: the list of screens of this role, and one screen at a time. */
export function Handheld({ api, user, onLogout }: Ctx & { onLogout: () => void }) {
  const screens = SCREENS[user.role as ScannerRole] ?? []
  const [screenId, setScreenId] = useState<string | null>(null)
  const screen = screens.find((s) => s.id === screenId)

  return (
    <>
      <header className="flex shrink-0 items-center gap-2 border-b border-border px-3 py-2">
        {screen ? (
          <button type="button" aria-label="Back to the menu" onClick={() => setScreenId(null)} className="grid size-8 cursor-pointer place-items-center rounded-md outline-none hover:bg-accent/60 focus-visible:ring-[3px] focus-visible:ring-ring/50">
            <ArrowLeft className="size-4" aria-hidden="true" />
          </button>
        ) : null}
        <div className="min-w-0 flex-1 px-1">
          <p className="truncate font-montserrat text-sm font-semibold">{screen ? screen.title : user.name}</p>
          <p className="truncate text-[11px] text-muted-foreground">{screen ? user.name : ROLE[user.role]}</p>
        </div>
        <button type="button" aria-label="Sign out of this handheld" onClick={onLogout} className="grid size-8 cursor-pointer place-items-center rounded-md text-muted-foreground outline-none hover:bg-accent/60 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50">
          <LogOut className="size-4" aria-hidden="true" />
        </button>
      </header>

      <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-4">
        {!screen && user.role !== 'outbound' && <OrderNotice api={api} user={user} />}
        {screen ? (
          // The key gives every screen a fresh start when the driver comes back to it.
          <div key={screen.id} className="flex flex-col gap-3">
            {screen.render({ api, user })}
          </div>
        ) : (
          <ul className="space-y-1.5">
            {screens.map((s) => (
              <li key={s.id}>
                <button type="button" onClick={() => setScreenId(s.id)} className="card-chai flex w-full cursor-pointer items-center gap-3 p-3 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                  <s.icon className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-medium">{s.title}</span>
                    <span className="block truncate text-xs text-muted-foreground">{s.hint}</span>
                  </span>
                  <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </>
  )
}

type Saved = { token: string; user: User }

/**
 * A handheld with its own login, as the real device will have: a username and a password give a token,
 * and every call carries that token. So several handhelds can be logged in as different people in one browser.
 */
export function TokenHandheld({ slot, role }: { slot: string; role: ScannerRole }) {
  const key = `handheld:${slot}`
  // The token lives in this tab only. Closing the tab logs the handheld out.
  const [saved, setSaved] = useState<Saved | null>(() => {
    try {
      return JSON.parse(sessionStorage.getItem(key) ?? 'null') as Saved | null
    } catch {
      return null
    }
  })
  const token = useRef<string | null>(saved?.token ?? null)
  token.current = saved?.token ?? null

  const forget = () => {
    sessionStorage.removeItem(key)
    setSaved(null)
  }
  const api: Api = useMemo(() => createApi({ token: () => token.current, cookies: false, onLoggedOut: forget }), []) // eslint-disable-line react-hooks/exhaustive-deps

  // A token from an earlier visit may have been withdrawn since. Ask once who it belongs to.
  const [checked, setChecked] = useState(saved === null)
  useEffect(() => {
    if (checked) return
    api
      .get<{ user: User }>('/auth/me')
      .then((me) => setSaved((s) => (s ? { ...s, user: me.user } : s)))
      .catch(() => undefined) // a withdrawn token answers 401, which already forgets it; a lost connection keeps it
      .finally(() => setChecked(true))
  }, [api, checked]) // eslint-disable-line react-hooks/exhaustive-deps

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const login = useAction()

  if (!checked) {
    return (
      <div className="grid flex-1 place-items-center">
        <Spinner />
      </div>
    )
  }

  if (saved) {
    return (
      <Handheld
        api={api}
        user={saved.user}
        onLogout={() => {
          void api.post('/auth/logout').catch(() => undefined)
          forget()
        }}
      />
    )
  }

  return (
    <form
      className="flex flex-1 flex-col justify-center gap-3 p-5"
      onSubmit={(e) => {
        e.preventDefault()
        void login.run(async () => {
          const out = await api.post<Saved>('/auth/token', { username, password })
          if (out.user.role !== role) {
            // The side by side view only makes sense when every handheld shows its own role. Hand the token back.
            void createApi({ token: () => out.token, cookies: false }).post('/auth/logout').catch(() => undefined)
            throw new Error(`${out.user.name} has the role ${ROLE[out.user.role].toLowerCase()}. This handheld is for the role ${ROLE[role].toLowerCase()}.`)
          }
          sessionStorage.setItem(key, JSON.stringify(out))
          setPassword('')
          setSaved(out)
        })
      }}
    >
      <div className="mb-2">
        <p className="font-montserrat text-lg font-semibold">Sign in</p>
        <p className="text-sm text-muted-foreground">This handheld is for the {ROLE[role].toLowerCase()}.</p>
      </div>
      <Field label="Username">
        <Input value={username} onChange={(e) => setUsername(e.target.value)} autoCapitalize="none" autoComplete="off" required />
      </Field>
      <Field label="Password">
        <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="off" required />
      </Field>
      {login.error && <Alert tone="danger">{login.error}</Alert>}
      <Button type="submit" variant="solid" iconRight={null} disabled={login.busy}>
        {login.busy ? 'Signing in' : 'Sign in'}
      </Button>
    </form>
  )
}
