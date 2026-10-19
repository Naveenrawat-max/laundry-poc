/** Loading data from the API: once, again on demand, and again every few seconds where a screen must stay fresh. */
import { useCallback, useEffect, useRef, useState } from 'react'

import { message } from '@/lib/api'

export type Loaded<T> = { data: T | null; error: string | null; loading: boolean; reload: () => Promise<void>; adopt: (next: T) => void }

/**
 * Runs `load` when `deps` change, and every `everyMs` when given. The hosting has no push,
 * so a screen that shows someone else's work (a handover, the dashboard) asks again on a timer.
 */
export function useLoad<T>(load: () => Promise<T>, deps: unknown[], everyMs?: number): Loaded<T> {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const latest = useRef(load)
  latest.current = load
  const run = useRef(0)

  const reload = useCallback(async () => {
    const mine = ++run.current
    try {
      const next = await latest.current()
      if (mine !== run.current) return // a newer request already answered
      setData(next)
      setError(null)
    } catch (e) {
      if (mine === run.current) setError(message(e))
    } finally {
      if (mine === run.current) setLoading(false)
    }
  }, [])

  /** Show this answer now and ignore any refresh that started before it. */
  const adopt = useCallback((next: T) => {
    run.current += 1
    setData(next)
    setError(null)
    setLoading(false)
  }, [])

  useEffect(() => {
    const refresh = () => { void reload() }
    window.addEventListener('proti:business-date', refresh)
    return () => window.removeEventListener('proti:business-date', refresh)
  }, [reload])

  useEffect(() => {
    setLoading(true)
    setData(null) // what is on screen answered another question (another customer, another filter)
    let timer = 0
    let stopped = false
    // The next refresh is counted from the answer of the last one, never from a fixed clock:
    // on a slow line a fixed clock would pile requests up.
    const tick = async () => {
      if (!document.hidden) await reload()
      if (!stopped && everyMs) timer = window.setTimeout(tick, everyMs)
    }
    if (everyMs) void tick()
    else void reload()
    return () => {
      stopped = true
      window.clearTimeout(timer)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, everyMs, reload])

  return { data, error, loading, reload, adopt }
}

/** Runs one action (a save, a scan) and keeps its busy flag and its error. */
export function useAction() {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const run = useCallback(async <T,>(action: () => Promise<T>): Promise<T | undefined> => {
    setBusy(true)
    setError(null)
    try {
      return await action()
    } catch (e) {
      setError(message(e))
      return undefined
    } finally {
      setBusy(false)
    }
  }, [])
  return { busy, error, run, clear: () => setError(null) }
}
