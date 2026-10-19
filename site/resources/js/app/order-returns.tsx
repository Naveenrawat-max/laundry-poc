/** Office notification of refused order mats awaiting two-sided return; history stays in the order. */
import { Link } from 'react-router'

import { activeOrderItems } from '@/app/orders'
import { Alert } from '@/components/ui/alert'
import { useLoad } from '@/lib/hooks'
import { useSession } from '@/lib/session'
import type { Order } from '@/lib/types'

export function OrderReturnsReminder() {
  const { api } = useSession()
  const state = useLoad(() => api.get<{ orders: Order[] }>('/orders').then((r) => r.orders.filter((o) => (o.return_pending_count ?? 0) > 0)), [api], 8000)
  if (!state.data?.length) return null
  return <Alert tone="warning" title="Refused mats awaiting return" className="mb-6">
    <ul className="space-y-2">{state.data.map((o) => <li key={o.id}>
      <Link to={`/ready-to-use?order=${o.id}#order-${o.id}`} className="font-medium underline underline-offset-4">{o.number}</Link> · Task #{o.task_id} · {o.customer_name} · {o.return_pending_count} awaiting inventory receipt
      <span className="block text-xs">{activeOrderItems(o).filter((m) => m.customer_response === 'refused' && m.status === 'refused').map((m) => m.sku).join(', ')}</span>
    </li>)}</ul>
    <p className="mt-2 text-xs">Driver and inventory confirm the return by scanning the same refused mats. This notice clears when receipt is complete; order history remains.</p>
  </Alert>
}
