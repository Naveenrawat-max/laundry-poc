/** The login of the person at this browser: the session cookie, the user it belongs to, and the API client that uses it. */
import { createContext, use, useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { type Api, ApiError, createApi } from '@/lib/api'
import type { User } from '@/lib/types'

type Session = {
  /** null while nobody is logged in. */
  user: User | null
  /** false until the first "who am I" answer is in. */
  ready: boolean
  api: Api
  login: (username: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

const SessionContext = createContext<Session | null>(null)

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [ready, setReady] = useState(false)
  const csrf = useRef<string | null>(null)

  const api = useMemo(() => createApi({ csrf: () => csrf.current, onLoggedOut: () => setUser(null) }), [])

  useEffect(() => {
    api
      .get<{ user: User; csrf_token: string }>('/auth/me')
      .then((me) => {
        csrf.current = me.csrf_token
        setUser(me.user)
      })
      .catch((e) => {
        if (!(e instanceof ApiError) || e.status !== 401) console.error(e)
      })
      .finally(() => setReady(true))
  }, [api])

  const login = useCallback(
    async (username: string, password: string) => {
      const out = await api.post<{ user: User; csrf_token: string }>('/auth/login', { username, password })
      csrf.current = out.csrf_token
      setUser(out.user)
    },
    [api],
  )

  const logout = useCallback(async () => {
    await api.post('/auth/logout').catch(() => undefined)
    csrf.current = null
    setUser(null)
  }, [api])

  const value = useMemo(() => ({ user, ready, api, login, logout }), [user, ready, api, login, logout])
  return <SessionContext value={value}>{children}</SessionContext>
}

export function useSession(): Session {
  const session = use(SessionContext)
  if (!session) throw new Error('useSession must be used inside <SessionProvider>')
  return session
}
