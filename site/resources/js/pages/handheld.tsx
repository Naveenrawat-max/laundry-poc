/** The handheld pages: the office's review of every role's handheld side by side, and a scanning role's own handheld. */
import { useState } from 'react'

import { PageHead } from '@/app/ui'
import { Backdrop } from '@/components/backdrop'
import { ThemeSwitcher } from '@/components/theme-switcher'
import { Segmented } from '@/components/ui/segmented'
import { Switch } from '@/components/ui/switch'
import { DeviceFrame, Handheld, type ScannerRole, TokenHandheld } from '@/handheld/device'
import { ROLE } from '@/lib/labels'
import { useSession } from '@/lib/session'
import { cn } from '@/lib/utils'

const MODES = [
  { value: 'compare', label: 'Side by side' },
  { value: 'single', label: 'One at a time' },
] as const

const NOTE: Record<ScannerRole, string> = {
  inventory: 'Checks delivery orders and hands passed mats to the driver',
  driver: 'Loads, delivers, swaps and brings mats to washing',
  washing: 'Receives used mats and sends washed ones back',
  outbound: 'Only scans mats out to the driver',
}

/** A setting of this page that should survive a reload, kept in this browser. */
function useStored<T extends string>(key: string, fallback: T): [T, (value: T) => void] {
  const [value, setValue] = useState<T>(() => (localStorage.getItem(key) as T | null) ?? fallback)
  return [
    value,
    (next) => {
      localStorage.setItem(key, next)
      setValue(next)
    },
  ]
}

/** For the office: three or four handhelds, each with its own login, working at the same time. */
export function HandheldPage() {
  const [mode, setMode] = useStored<'compare' | 'single'>('handheld-mode', 'compare')
  const [outbound, setOutbound] = useStored<'yes' | 'no'>('handheld-outbound', 'no')
  const [shown, setShown] = useStored<ScannerRole>('handheld-shown', 'inventory')

  const roles: ScannerRole[] = ['inventory', 'driver', 'washing', ...(outbound === 'yes' ? (['outbound'] as const) : [])]
  const current = roles.includes(shown) ? shown : 'inventory'

  return (
    <>
      <PageHead title="Handhelds">
        Every role's scanner, <span className="highlight">working at the same time</span>. Hand a pile over on one and watch it arrive on the next.
      </PageHead>

      <div className="mt-8 mb-6 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <p className="max-w-2xl text-sm text-gray-500 dark:text-gray-400">
          Each handheld logs in on its own, like the real ones will. Use the users you made under Setup, Users. A scan here sends exactly what the real handheld will send.
        </p>
        <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
          <label className="flex items-center gap-2 text-sm">
            <Switch checked={outbound === 'yes'} onCheckedChange={(on) => setOutbound(on ? 'yes' : 'no')} />
            Outbound as a fourth
          </label>
          <Segmented label="How to show the handhelds" options={MODES} value={mode} onChange={setMode} />
        </div>
      </div>

      {mode === 'single' && <Segmented label="Which handheld" className="mx-auto mb-6 w-fit" options={roles.map((r) => ({ value: r, label: ROLE[r] }))} value={current} onChange={setShown} />}

      {/* Wide rows scroll inside this box, never the page. Every handheld stays mounted, so switching the view loses nothing. */}
      <div className="-mx-6 overflow-x-auto px-6 pb-4 sm:-mx-12 sm:px-12">
        <div className="mx-auto flex w-max gap-6">
          {roles.map((role) => (
            <figure key={role} className={cn(mode === 'single' && role !== current && 'hidden')}>
              <figcaption className="mb-3 px-2">
                <span className="block font-montserrat text-sm font-semibold">{ROLE[role]}</span>
                <span className="block text-xs text-muted-foreground">{NOTE[role]}</span>
              </figcaption>
              <DeviceFrame>
                <TokenHandheld slot={role} role={role} />
              </DeviceFrame>
            </figure>
          ))}
        </div>
      </div>
    </>
  )
}

/** For a scanning role that logs in on the web: its own handheld, and nothing else. */
export function OwnHandheldPage() {
  const { api, user, logout } = useSession()
  return (
    <div className="flex min-h-dvh flex-col overflow-y-clip">
      <Backdrop />
      <header className="flex items-center justify-between px-6 py-5 sm:px-12 max-sm:hidden">
        <span className="font-onest text-xl font-medium tracking-tight">
          Proti <span className="text-muted-foreground">mats</span>
        </span>
        <ThemeSwitcher />
      </header>
      <main className="flex flex-1 items-start justify-center sm:p-6">
        {/* On a phone the handheld is the whole screen. */}
        <DeviceFrame className="max-sm:h-dvh max-sm:w-full max-sm:rounded-none max-sm:border-0">
          <Handheld api={api} user={user!} onLogout={() => void logout()} />
        </DeviceFrame>
      </main>
    </div>
  )
}
