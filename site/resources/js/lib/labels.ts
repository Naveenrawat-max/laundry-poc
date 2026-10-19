/** The words the screens use for the API's codes, and how dates are shown. */
import type { HandoverType, Role, Status } from '@/lib/types'

export const ROLE: Record<Role, string> = {
  office: 'Office',
  inventory: 'Inventory manager',
  driver: 'Delivery driver',
  washing: 'Washing house manager',
  outbound: 'Outbound',
}

export const STATUSES: Status[] = [
  'registered',
  'ready',
  'qc_passed',
  'rewash',
  'repair',
  'scrapped',
  'out_for_delivery',
  'refused',
  'at_customer',
  'collected',
  'in_washing',
]

export const STATUS: Record<Status, string> = {
  registered: 'Registered',
  ready: 'Ready to use',
  qc_passed: 'Checked, may go out',
  rewash: 'Needs rewash',
  repair: 'In repair',
  scrapped: 'Scrapped',
  out_for_delivery: 'On the van, clean',
  refused: 'Refused, on the van',
  at_customer: 'At the customer',
  collected: 'On the van, used',
  in_washing: 'In washing',
}

/** Where a mat is, as the dashboard and the mat list group the statuses. */
export const PLACES: { name: string; statuses: Status[] }[] = [
  { name: 'In inventory', statuses: ['registered', 'ready', 'qc_passed', 'rewash', 'repair'] },
  { name: 'On a van', statuses: ['out_for_delivery', 'refused', 'collected'] },
  { name: 'At customers', statuses: ['at_customer'] },
  { name: 'In washing', statuses: ['in_washing'] },
]

/** The statuses in which a driver carries the mat. */
export const HELD: Status[] = ['out_for_delivery', 'refused', 'collected']

/** Colour is only ever text and a thin outline: green for good, red for a problem, grey for the rest. */
export function statusTone(status: Status): 'default' | 'success' | 'danger' | 'special' {
  if (status === 'qc_passed' || status === 'at_customer') return 'success'
  if (status === 'refused' || status === 'scrapped' || status === 'rewash' || status === 'repair') return 'danger'
  if (status === 'in_washing') return 'special'
  return 'default'
}

export const HANDOVER: Record<HandoverType, { name: string; route: string }> = {
  inventory_to_driver: { name: 'Handover A', route: 'Inventory to driver' },
  driver_to_washing: { name: 'Handover B', route: 'Driver to washing' },
  washing_to_inventory: { name: 'Handover C', route: 'Washing to inventory' },
  driver_to_inventory: { name: 'Return', route: 'Driver to inventory, refused mats' },
  inventory_to_washing: { name: 'Rewash', route: 'Inventory to washing' },
}

export const EVENT: Record<string, string> = {
  register: 'Registered',
  ready: 'Marked ready',
  qc: 'Quality check',
  handover_give: 'Scanned out',
  handover_receive: 'Scanned in',
  deliver: 'Laid at the customer',
  refuse: 'Refused at the stop',
  collect: 'Picked up',
  correction: 'Corrected by the office',
}

export const TASK_TYPE = { delivery: 'Delivery', swap: 'Swap', collection: 'Collection' } as const

/** The server stores times in UTC. Every screen shows Dutch time. */
export function when(utc: string | null | undefined): string {
  if (!utc) return ''
  const date = new Date(utc.replace(' ', 'T') + 'Z')
  return date.toLocaleString('en-GB', { timeZone: 'Europe/Amsterdam', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

/** A calendar day (due date, wash date) is a Dutch day already and is shown as it is. */
export function day(date: string | null | undefined): string {
  if (!date) return ''
  const [y, m, d] = date.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-GB', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' })
}

let businessDate: string | null = null

/** Keep every office and handheld screen on the server's shared business date. */
export function setBusinessDate(date: string): void {
  if (businessDate === date) return
  businessDate = date
  window.dispatchEvent(new Event('proti:business-date'))
}

/** Business today as YYYY-MM-DD; the server supplies simulator dates. */
export function today(): string {
  return businessDate ?? new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Amsterdam' })
}
