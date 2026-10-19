/** The shapes the API answers with, as built in site/app/Http/Controllers. */

export type Role = 'office' | 'inventory' | 'driver' | 'washing' | 'outbound'

export type User = { id: number; name: string; username: string; role: Role; is_active: boolean }

export type SimulationClock = { enabled: boolean; today: string; simulated: boolean; next_due_date: string | null }

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
  catalog_sku_id: number | null
  catalog_sku_code: string | null
  catalog_sku_label: string | null
  width_cm: number | null
  height_cm: number | null
  color: string | null
  delivered_on: string | null
  swap_due_date: string | null
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
  order_id: number | null
  order_number: string | null
  type: HandoverType
  status: 'open' | 'closed'
  pending_receipt: boolean
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
  order_id: number | null
  order_number: string | null
  type: HandoverType
  status: 'open' | 'closed'
  pending_receipt: boolean
  created_at: string
  closed_at: string | null
  giver: { id: number; name: string }
  receiver: { id: number; name: string }
  items: (Mat & { given_at: string | null; received_at: string | null; resolved_at: string | null; receivable: boolean })[]
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
  order_id: number | null
  order_number: string | null
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
  area_name: string | null
  customer_id: number
  customer_name: string
  contact_name: string | null
  contact_phone: string | null
}

/** A driver's own open task, with what he needs at the stop. */
export type Stop = Task & {
  zones: { id: number; name: string }[]
  expected: { zone_id: number; zone_name: string; item_type_id: number; type_name: string; quantity: number }[]
  in_building: { id: number; sku: string; epc: string; zone_id: number; type: string; delivered_on?: string | null; swap_due_date?: string | null }[]
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
  building_id: number | null
  building_name: string | null
  street: string | null
  postcode: string | null
  city: string | null
  next_visit_date: string | null
  next_swap_date: string | null
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
  area_id: number | null
  name: string
  street: string
  postcode: string
  city: string
  is_active: boolean
}
export type Zone = { id: number; building_id: number; name: string; is_active: boolean }
export type Category = { id: number; name: string; is_active: boolean }
export type ItemType = { id: number; name: string; is_active: boolean; category_id: number | null }

/** Fixed product choices; the physical mat still has its own unique sku and EPC. */
export type CatalogSku = {
  id: number
  item_type_id: number
  code: string
  label: string
  category_id: number | null
  category_name: string | null
  type_name: string
  width_cm: number | null
  height_cm: number | null
  color: string | null
  untagged_quantity: number
  ready_quantity: number
  reserved_quantity: number
  on_van_quantity: number
  at_customer_quantity: number
  washing_quantity: number
  repair_quantity: number
  scrapped_quantity: number
  tagged_quantity: number
  total_quantity: number
}
export type OrderItem = {
  id: number
  item_id: number
  sku: string
  epc: string
  item_type_id: number
  type_name: string
  catalog_sku_id: number | null
  catalog_sku_code: string | null
  zone_id?: number
  status: Status
  qc_status: 'pending' | 'passed' | 'failed'
  qc_at: string | null
  qc_note?: string | null
  scanned_at: string | null
  received_at: string | null
  replaced_at: string | null
  customer_response?: 'accepted' | 'refused' | null
  refusal_scanned_at?: string | null
  delivered_on: string | null
  swap_due_date: string | null
}
export type AvailableOrderItem = Pick<OrderItem, 'sku' | 'epc' | 'item_type_id' | 'type_name' | 'catalog_sku_id' | 'catalog_sku_code' | 'status' | 'delivered_on' | 'swap_due_date'> & { id: number; zone_id: number | null }
export type Order = {
  id: number
  contract_id?: number | null
  contract_start_date?: string | null
  swap_interval_days?: number | null
  next_swap_date?: string | null
  task_id?: number | null
  dispatch_handover_id: number | null
  return_handover_id: number | null
  refused_count?: number
  return_pending_count?: number
  number: string
  type: 'delivery' | 'pickup'
  status: 'pending_qc' | 'ready' | 'in_progress' | 'awaiting_receipt' | 'received' | 'done' | 'cancelled'
  customer_id?: number
  customer_name?: string
  building_id?: number
  building_name?: string
  assigned_to: number
  assigned_name: string
  washing_user_id: number | null
  washing_name: string | null
  due_date: string
  destination: 'customer' | 'washing' | 'inventory'
  reason?: string | null
  return_reason?: 'used' | 'unused_clean' | 'damaged' | null
  dispatched_at: string | null
  received_at: string | null
  completed_at: string | null
  signed_name?: string | null
  customer_response?: 'accepted' | 'refused' | 'partial' | null
  items: OrderItem[]
}
