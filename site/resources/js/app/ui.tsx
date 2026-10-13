/** Small pieces every office page shares: the page head, a status chip, the loading and empty states, and a form in a dialog. */
import type { ReactNode } from 'react'

import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Chip } from '@/components/ui/chip'
import { Dialog, DialogActions } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import type { Loaded } from '@/lib/hooks'
import { STATUS, statusTone } from '@/lib/labels'
import type { Status } from '@/lib/types'
import { cn } from '@/lib/utils'

/** The centred page title with its one-line lead. Put one highlight phrase in the lead. */
export function PageHead({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="space-y-3">
      <h1 className="text-2xl font-medium sm:text-center sm:text-3xl">{title}</h1>
      {children && <p className="mx-auto max-w-3xl text-base text-neutral-700 sm:text-center sm:text-lg dark:text-neutral-400">{children}</p>}
    </div>
  )
}

/** The row above a list: what is shown on the left, the controls on the right. */
export function Toolbar({ info, children }: { info?: ReactNode; children?: ReactNode }) {
  return (
    <div className="mt-8 mb-3 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <p className="text-sm text-gray-500 dark:text-gray-400">{info}</p>
      <div className="flex flex-wrap items-center gap-2">{children}</div>
    </div>
  )
}

export function Count({ n, noun }: { n: number; noun: string }) {
  return (
    <>
      Showing <span className="font-semibold text-gray-900 dark:text-gray-100">{n}</span> {n === 1 ? noun : `${noun}s`}
    </>
  )
}

export function StatusChip({ status, className }: { status: Status; className?: string }) {
  return (
    <Chip tone={statusTone(status)} className={className}>
      {STATUS[status]}
    </Chip>
  )
}

/** Shows what was loaded, or a quiet placeholder while it loads, or the reason it failed. */
export function Load<T>({ state, children, rows = 4 }: { state: Loaded<T>; children: (data: T) => ReactNode; rows?: number }) {
  if (state.data !== null) {
    return (
      <>
        {state.error && (
          <Alert tone="warning" className="mb-3">
            Could not refresh: {state.error}
          </Alert>
        )}
        {children(state.data)}
      </>
    )
  }
  if (state.error) return <Alert tone="danger">{state.error}</Alert>
  return (
    <div className="space-y-2" aria-busy="true">
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} className="h-10 w-full" />
      ))}
    </div>
  )
}

export function Empty({ children, className }: { children: ReactNode; className?: string }) {
  return <p className={cn('rounded-xl border border-dashed border-card-edge px-4 py-8 text-center text-sm text-muted-foreground', className)}>{children}</p>
}

/** A form in a dialog: the fields as children, the server's refusal above the buttons. */
export function FormDialog({
  open,
  title,
  description,
  submitLabel = 'Save',
  busy,
  error,
  onSubmit,
  onClose,
  children,
  className,
}: {
  open: boolean
  title: string
  description?: ReactNode
  submitLabel?: string
  busy: boolean
  error: string | null
  onSubmit: () => void
  onClose: () => void
  children: ReactNode
  className?: string
}) {
  return (
    <Dialog open={open} onClose={onClose} title={title} description={description} busy={busy} className={className}>
      <form
        className="mt-5 space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          onSubmit()
        }}
      >
        {children}
        {error && <Alert tone="danger">{error}</Alert>}
        <DialogActions>
          <Button type="button" variant="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? 'Saving' : submitLabel}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  )
}

/** A label with its value, for detail panels. */
export function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-[11px] tracking-wide text-muted-foreground uppercase">{label}</dt>
      <dd className="truncate text-sm">{children || <span className="text-muted-foreground">None</span>}</dd>
    </div>
  )
}
