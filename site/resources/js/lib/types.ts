/** The shapes the API answers with, as built in site/app/Http/Controllers. */

export type Role = 'office' | 'inventory' | 'driver' | 'washing' | 'outbound'

export type User = { id: number; name: string; username: string; role: Role; is_active: boolean }

export type Status =
  | 'registered'
  | 'ready'
  | 'qc_passed'
  | 'rewash'
  | 'repair'
  | 'scrapped'
  | 'out_for_delivery'
  | 'refused'
  | 'at_customer'
  | 'collected'
  | 'in_washing'

/** A mat as every scanning role sees it. */
export type Mat = {
  id: number
  sku: string
  type: string
  status: Status
  wash_count: number
  current_wash_date: string | null
  previous_wash_date: string | null
}

/** A mat as the office sees it. */
export type OfficeMat = Mat & {
  epc: string
  tid_primary: string
  tid_secondary: string
  item_type_id: number
  holder: { id: number; name: string } | null
  location: { zone_id: number; zone: string; building: string; customer: string } | null
}

export type MatEvent = {
  id: number
  created_at: string
  event_type: string
  from_status: Status | null
  to_status: Status
  source: string
  note?: string | null
  handover_id: number | null
  task_id?: number | null
  user_name: string
  zone_name?: string | null
}

export type ScanResult = {
  epc: string
  result: 'found' | 'not_on_list' | 'unknown' | 'rejected'
  item: Mat | null
  reason?: string
}
export type ScanAnswer = { results: ScanResult[]; missing: Mat[] }

export type HandoverType =
  | 'inventory_to_driver'
  | 'driver_to_washing'
  | 'washing_to_inventory'
  | 'driver_to_inventory'
  | 'inventory_to_washing'

export type HandoverRow = {
  id: number
  type: HandoverType
  status: 'open' | 'closed'
  created_at: string
  closed_at: string | null
  giver_id: number
  giver_name: string
  receiver_id: number
  receiver_name: string
  given: number
  received: number
}

export type Handover = {
  id: number
  type: HandoverType
  status: 'open' | 'closed'
  created_at: string
  closed_at: string | null
  giver: { id: number; name: string }
  receiver: { id: number; name: string }
  items: (Mat & { given_at: string | null; received_at: string | null; resolved_at: string | null })[]
}

export type HandoverIssue = {
  handover_id: number
  type: HandoverType
  closed_at: string
  giver_name: string
  receiver_name: string
  given_at: string | null
  received_at: string | null
  item_id: number
  sku: string
  type_name: string
  status: Status
}

export type Task = {
  id: number
  type: 'delivery' | 'swap' | 'collection'
  status: 'open' | 'done' | 'cancelled'
  due_date: string
  completed_at: string | null
  contract_id: number
  building_id: number
  previous_task_id: number | null
  assigned_to: number | null
  assigned_name: string | null
  signed_name: string | null
  issue_note: string | null
  has_proof: number
  building_name: string
  street: string
  postcode: string
  city: string
  area_name: string
  customer_id: number
  customer_name: string
  contact_name: string | null
  contact_phone: string | null
}

/** A driver's own open task, with what he needs at the stop. */
export type Stop = Task & {
  zones: { id: number; name: string }[]
  expected: { zone_id: number; zone_name: string; item_type_id: number; type_name: string; quantity: number }[]
  in_building: { id: number; sku: string; epc: string; zone_id: number; type: string }[]
}

export type DriverNeeds = {
  driver: { id: number; name: string }
  needs: { item_type_id: number; type: string; needed: number; carried: number; remaining: number }[]
}

export type ContractLine = {
  id: number
  zone_id: number
  zone_name: string
  building_id: number
  building_name: string
  item_type_id: number
  type_name: string
  quantity: number
}
export type Contract = {
  id: number
  customer_id: number
  customer_name: string
  swap_interval_days: number
  status: 'draft' | 'active' | 'ended'
  start_date: string
  end_date: string | null
  lines: ContractLine[]
}

export type Area = { id: number; name: string }
export type Customer = {
  id: number
  name: string
  contact_name: string | null
  contact_phone: string | null
  contact_email: string | null
  is_active: boolean
}
export type Building = {
  id: number
  customer_id: number
  area_id: number
  name: string
  street: string
  postcode: string
  city: string
  is_active: boolean
}
export type Zone = { id: number; building_id: number; name: string; is_active: boolean }
export type ItemType = { id: number; name: string; is_active: boolean }
