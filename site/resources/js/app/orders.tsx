/** Shared office and handheld order summaries; scan checks always come from persisted order lines. */
import { Check, Minus } from 'lucide-react'

import { Chip } from '@/components/ui/chip'
import { day, today } from '@/lib/labels'
import type { Order } from '@/lib/types'

export const ORDER_STATUS: Record<Order['status'], string> = { pending_qc: 'Awaiting quality check', ready: 'Ready', in_progress: 'In progress', awaiting_receipt: 'Awaiting receipt', received: 'Received for washing', done: 'Done', cancelled: 'Cancelled' }
export const RETURN_REASON: Record<NonNullable<Order['return_reason']>, string> = { used: 'Dirty', unused_clean: 'Unused and clean', damaged: 'Damaged' }
export const serviceSchedule = (start: string | null | undefined, interval: number | null | undefined) => start && interval ? `From ${day(start)} the mats will be swapped · D+${interval} (every ${interval} days after a delivery or swap)` : 'Service schedule unavailable'
export const activeOrderItems = (order: Order) => order.items.filter((i) => !i.replaced_at)

/** Swap dates belong to delivered mats and cannot be changed in pickup selection. */
export function SwapDue({ date, deliveredOn }: { date: string | null | undefined; deliveredOn?: string | null }) {
  const now = today()
  return <>{deliveredOn !== undefined && <span className="text-xs text-muted-foreground">{deliveredOn ? `Delivered ${day(deliveredOn)}` : 'Delivery date unavailable'}</span>}<span className={`text-xs ${date && date <= now ? 'font-medium text-destructive' : 'text-muted-foreground'}`}>{date ? `Swap due ${day(date)}${date < now ? ' · Overdue' : date === now ? ' · Due today' : ''}` : 'Swap date unavailable'}</span></>
}

export function OrderSummary({ order, washing = false }: { order: Order; washing?: boolean }) {
  return <div className="card-chai space-y-1 p-3">
    <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-montserrat text-sm font-semibold">{order.number}</h3><Chip>{ORDER_STATUS[order.status]}</Chip></div>
    {!washing && <p className="text-sm">{order.customer_name} · {order.building_name}</p>}
    {!washing && order.contract_id && <p className="text-xs text-muted-foreground">{serviceSchedule(order.contract_start_date, order.swap_interval_days)}{order.next_swap_date ? ` · Next swap ${day(order.next_swap_date)}` : ''}{order.task_id ? ` · Task #${order.task_id}` : ''}</p>}
    <p className="text-xs text-muted-foreground">Driver {order.type === 'delivery' ? 'delivery' : 'pickup'} due {day(order.due_date)} · Driver: {order.assigned_name}</p>
    {!washing && order.type === 'pickup' && <p className="text-xs">{order.return_reason ? RETURN_REASON[order.return_reason] : 'Pickup'}{order.reason ? ` · ${order.reason}` : ''} · To {order.destination === 'washing' ? `washing: ${order.washing_name}` : 'inventory'}{order.return_reason === 'damaged' ? ' · Inspection / repair after receipt' : order.return_reason === 'unused_clean' ? ' · Ready after receipt' : ''}</p>}
    {!washing && order.customer_response && <p className="text-xs">{order.customer_response === 'partial' ? 'Partially refused' : `Customer ${order.customer_response}`}{order.signed_name ? ` · ${order.signed_name}` : ''}{order.refused_count ? ` · ${order.refused_count} refused, ${order.return_pending_count} awaiting return` : ''}</p>}
  </div>
}

/** Read-only checklist; selecting a simulated scan never changes this until the API confirms it. */
export function OrderProducts({ order, stage = 'scan' }: { order: Order; stage?: 'scan' | 'qc' | 'receive' }) {
  return <ul className="divide-y divide-border" aria-label="Order products">{activeOrderItems(order).map((line) => {
    const checked = stage === 'qc' ? line.qc_status === 'passed' : stage === 'receive' ? Boolean(line.received_at) : Boolean(line.scanned_at)
    return <li key={line.id} className="flex items-start gap-2 py-2 text-xs">
      {checked ? <Check className="mt-0.5 size-4 shrink-0 text-green-700 dark:text-green-400" aria-label={stage === 'qc' ? 'QC passed' : stage === 'receive' ? 'Received' : 'Scanned'} /> : <Minus className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-label="Pending" />}
      <span className="min-w-0 flex-1"><span className="flex flex-wrap items-center gap-x-3 gap-y-1"><span className="font-mono font-medium">{line.sku}</span>{order.type === 'pickup' && !['done', 'cancelled'].includes(order.status) && !line.received_at && <SwapDue date={line.swap_due_date} deliveredOn={line.delivered_on} />}</span><span className="block">{line.catalog_sku_code || line.type_name} · {line.type_name}</span><span className="block break-all text-[10px] text-muted-foreground">EPC {line.epc}</span></span>
      {stage === 'qc' && <Chip tone={line.qc_status === 'failed' ? 'danger' : checked ? 'success' : 'default'}>{line.qc_status}</Chip>}
      {line.customer_response && <Chip tone={line.customer_response === 'refused' ? 'danger' : 'success'}>{line.customer_response}</Chip>}
    </li>
  })}</ul>
}
