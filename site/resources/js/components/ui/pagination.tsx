import { ChevronLeft, ChevronRight } from 'lucide-react'
import * as React from 'react'

import { cn } from '@/lib/utils'

/**
 * Page numbers for long lists. Pass `href` to make each page a link (good
 * for search engines and the back button), or `onChange` for client-side
 * paging. The current page is white on dark and marked aria-current; gaps
 * collapse to an ellipsis so the row never gets long.
 */
function pages(current: number, total: number): (number | '…')[] {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const set = new Set([1, total, current - 1, current, current + 1].filter((p) => p >= 1 && p <= total))
  const sorted = [...set].sort((a, b) => a - b)
  const out: (number | '…')[] = []
  sorted.forEach((p, i) => {
    if (i > 0 && p - sorted[i - 1] > 1) out.push('…')
    out.push(p)
  })
  return out
}

const item =
  'inline-flex h-9 min-w-9 cursor-pointer items-center justify-center gap-1 rounded-md px-2.5 text-sm tabular-nums transition-colors duration-150 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50'

function Pagination({
  page,
  total,
  href,
  onChange,
  className,
}: {
  page: number
  total: number
  href?: (page: number) => string
  onChange?: (page: number) => void
  className?: string
}) {
  if (total <= 1) return null
  const go = (p: number, label: React.ReactNode, extra: { 'aria-label'?: string; disabled?: boolean; current?: boolean } = {}) => {
    const cls = cn(
      item,
      extra.current ? 'bg-primary font-semibold text-primary-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
      extra.disabled && 'pointer-events-none opacity-40',
    )
    const aria = { 'aria-label': extra['aria-label'] ?? `Page ${p}`, 'aria-current': extra.current ? ('page' as const) : undefined }
    return href && !extra.disabled ? (
      <a href={href(p)} className={cls} {...aria}>
        {label}
      </a>
    ) : (
      <button type="button" className={cls} disabled={extra.disabled} onClick={() => onChange?.(p)} {...aria}>
        {label}
      </button>
    )
  }
  return (
    <nav aria-label="Pagination" className={cn('flex items-center justify-center gap-1', className)}>
      {go(page - 1, <ChevronLeft className="size-4" aria-hidden="true" />, { 'aria-label': 'Previous page', disabled: page <= 1 })}
      {pages(page, total).map((p, i) =>
        p === '…' ? (
          <span key={`gap-${i}`} className="px-1 text-sm text-muted-foreground" aria-hidden="true">
            …
          </span>
        ) : (
          <React.Fragment key={p}>{go(p, p, { current: p === page })}</React.Fragment>
        ),
      )}
      {go(page + 1, <ChevronRight className="size-4" aria-hidden="true" />, { 'aria-label': 'Next page', disabled: page >= total })}
    </nav>
  )
}

export { Pagination }
