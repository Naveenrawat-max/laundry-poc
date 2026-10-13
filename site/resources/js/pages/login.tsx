/** The login page. One form for every role: after login the role decides what opens. */
import { useState } from 'react'

import { Backdrop } from '@/components/backdrop'
import { ThemeSwitcher } from '@/components/theme-switcher'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { useAction } from '@/lib/hooks'
import { useSession } from '@/lib/session'

export function LoginPage() {
  const { login } = useSession()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const { busy, error, run } = useAction()

  return (
    <div className="flex min-h-dvh flex-col overflow-y-clip">
      <Backdrop />
      <header className="flex items-center justify-between px-6 py-5 sm:px-12">
        <span className="font-onest text-xl font-medium tracking-tight">
          Proti <span className="text-muted-foreground">mats</span>
        </span>
        <ThemeSwitcher />
      </header>

      <main className="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center p-6 pb-24">
        <h1 className="text-2xl font-medium sm:text-3xl">Sign in</h1>
        <p className="mt-2 text-base text-neutral-700 dark:text-neutral-400">
          Follow every mat from the shelf to the customer and <span className="highlight">back through the wash</span>.
        </p>

        <form
          className="card-chai mt-8 space-y-4 p-5"
          onSubmit={(e) => {
            e.preventDefault()
            void run(() => login(username, password))
          }}
        >
          <Field label="Username">
            <Input value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="username" autoCapitalize="none" autoFocus required />
          </Field>
          <Field label="Password">
            <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required />
          </Field>
          {error && <Alert tone="danger">{error}</Alert>}
          <Button type="submit" variant="solid" className="w-full" disabled={busy}>
            {busy ? 'Signing in' : 'Sign in'}
          </Button>
        </form>
        <p className="mt-4 text-xs text-muted-foreground">Office staff get the office console. Everyone else gets their handheld screens.</p>
      </main>
    </div>
  )
}
