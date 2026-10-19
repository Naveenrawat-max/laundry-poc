/** The workflow page: how Proti's mats travel, in plain words, before anyone looks at numbers. */
import { ArrowRight, Building2, ChevronsDown, Flag, Repeat, ScanLine, Truck, Warehouse, WashingMachine } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'

import { Button } from '@/components/ui/button'
import { Card, CardBody, CardTitle } from '@/components/ui/card'
import { Chip } from '@/components/ui/chip'

const STOPS = [
  { icon: Warehouse, name: 'Inventory', text: 'The office registers each mat and creates its delivery order. Inventory checks the ordered mats before dispatch.', next: 'Dispatch to driver' },
  { icon: Building2, name: 'At the customer', text: 'The driver lays the mats in the right spot and proves it with a photo and a signature.', next: 'Swap day' },
  { icon: Truck, name: 'On the van', text: 'The office selects delivered customer mats for pickup. Their return reason determines washing, clean inventory or inspection.', next: 'Recipient confirms receipt' },
  { icon: WashingMachine, name: 'Washing house', text: 'Incoming orders identify the driver and exact mats. Receipt is confirmed before washing and return to inventory.', next: 'Back to inventory' },
]

const STEPS: { who: string; title: string; text: string }[] = [
  { who: 'Office', title: 'Set up the customer', text: 'Add customer details and one or more service locations. Each location starts with a Main area. Create and start its own contract with a service start date, mat quantities and D+n days between actual deliveries or swaps.' },
  { who: 'Office', title: 'Register and create a delivery order', text: 'Create a category and subcategory, then a SKU with size, colour and untagged quantity. Select that SKU before scanning both demo tags. Inspect the captured EPCs and TIDs, then press Map. One mat becomes ready immediately; its SKU stays selected for the next mat. Choose customer and location to load its active service schedule, then select exact mats and a driver.' },
  { who: 'Inventory', title: 'Quality check and dispatch', text: 'Scan each mat in the order and record its quality. Replace failed mats and check their replacements. Scan the complete passed order to dispatch it to the assigned driver.' },
  { who: 'Driver', title: 'Scan, prove and deliver', text: 'My tasks shows the order number and exact products. One batch scan checks every matching mat in the pile and reports unrelated tags. Photo, signature and customer acceptance or refusal appear after all ordered mats are scanned.' },
  { who: 'Office', title: 'Create a pickup order', text: 'Choose the customer and location to see mats already delivered there. Select exact mats, driver and return reason. Used mats go to washing; unused clean mats become ready when inventory receives them; damaged mats require inspection and repair.' },
  { who: 'Driver and recipient', title: 'Pick up and confirm receipt', text: 'The driver scans every ordered customer mat and records proof. Washing receives an incoming notification by driver; washing or inventory scans and confirms the mats it receives.' },
  { who: 'Washing', title: 'Wash and send back', text: 'After confirming receipt and washing the mats, scan the clean order to confirm the wash and return it to inventory. Each mat gets exactly one wash added.' },
]

const RULES = [
  { icon: ScanLine, title: 'Exact mats, exact scans', text: 'An order reserves individual mats. A different mat cannot complete an ordered product’s checklist.' },
  { icon: Flag, title: 'Quality before dispatch', text: 'Every ordered delivery mat must pass QC. Failed mats are replaced and checked before the driver can deliver.' },
  { icon: Repeat, title: 'Scan twice, count once', text: 'Scanning the same mat again changes nothing. A wash can never be counted twice.' },
]

const PEOPLE = [
  { name: 'Office', tool: 'Web console', text: 'Categories, product SKUs and stock, individual mat registration, delivery and pickup orders, customers and contracts.' },
  { name: 'Inventory manager', tool: 'Handheld', text: 'Checks order quality, replaces failed mats, dispatches passed orders and receives returns.' },
  { name: 'Delivery driver', tool: 'Handheld', text: 'Loads, delivers, swaps and collects, and brings used mats to washing.' },
  { name: 'Washing house manager', tool: 'Handheld', text: 'Receives used mats and sends washed ones back.' },
  { name: 'Outbound', tool: 'Handheld, optional', text: 'Only scans mats out to the driver, when that is a separate person.' },
]

function Section({ title, children, lead }: { title: string; lead: ReactNode; children: ReactNode }) {
  return (
    <section>
      <h2 className="mt-16 text-2xl font-medium sm:mt-20 sm:text-[30px]">{title}</h2>
      <p className="mb-6 text-gray-600 sm:text-lg dark:text-gray-300">{lead}</p>
      {children}
    </section>
  )
}

export function WorkflowPage() {
  return (
    <>
      <div className="flex flex-col gap-y-2.5 pt-4 sm:gap-y-4 sm:pt-10">
        <h1 className="max-w-4xl text-[42px] font-semibold tracking-tight max-md:leading-11 md:text-6xl">How a mat travels</h1>
        <p className="mt-2 max-w-3xl text-base text-neutral-700 md:text-xl dark:text-neutral-400">
          Proti rents door mats, delivers them, collects them and washes them. Every mat goes round <span className="highlight">the same loop</span>, and
          a scan marks each step.
        </p>
        <div className="mt-6 flex flex-wrap items-center gap-4">
          <Button asChild variant="solid">
            <Link to="/dashboard">Open the dashboard</Link>
          </Button>
          <Button asChild variant="outline" iconRight={<ChevronsDown />}>
            <a href="#steps">Read the steps</a>
          </Button>
        </div>
      </div>

      <Section title="The loop in four stops" lead={<>A mat is always at one of <span className="highlight">four places</span>. A handover moves it to the next.</>}>
        <ol className="grid gap-4 md:grid-cols-4">
          {STOPS.map((stop, i) => (
            <li key={stop.name} className="relative">
              <Card className="h-full">
                <CardBody className="gap-2">
                  <div className="flex items-center justify-between">
                    <stop.icon className="size-5 text-muted-foreground" aria-hidden="true" />
                    <span className="font-mono text-xs text-muted-foreground">{i + 1} of 4</span>
                  </div>
                  <CardTitle>{stop.name}</CardTitle>
                  <p className="text-sm leading-relaxed text-gray-600 dark:text-gray-400">{stop.text}</p>
                  <p className="mt-auto flex items-center gap-1.5 pt-3 text-xs text-muted-foreground">
                    <ArrowRight className="size-3.5" aria-hidden="true" />
                    {stop.next}
                  </p>
                </CardBody>
              </Card>
            </li>
          ))}
        </ol>
      </Section>

      <div id="steps" className="scroll-mt-24">
        <Section title="Step by step" lead={<>Seven steps, each with <span className="highlight">one clear owner</span>.</>}>
          <ol className="grid gap-3">
            {STEPS.map((step, i) => (
              <li key={step.title} className="card-chai flex gap-4 p-4 sm:items-start">
                <span className="grid size-8 shrink-0 place-items-center rounded-full border border-card-edge-hover font-montserrat text-sm font-semibold">{i + 1}</span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <h3 className="font-montserrat text-base font-semibold">{step.title}</h3>
                    <Chip>{step.who}</Chip>
                  </div>
                  <p className="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{step.text}</p>
                </div>
              </li>
            ))}
          </ol>
        </Section>
      </div>

      <Section title="Three rules" lead={<>They are what makes the numbers <span className="highlight">worth trusting</span>.</>}>
        <div className="grid gap-4 md:grid-cols-3">
          {RULES.map((rule) => (
            <Card key={rule.title}>
              <CardBody className="gap-2">
                <rule.icon className="size-5 text-muted-foreground" aria-hidden="true" />
                <CardTitle>{rule.title}</CardTitle>
                <p className="text-sm leading-relaxed text-gray-600 dark:text-gray-400">{rule.text}</p>
              </CardBody>
            </Card>
          ))}
        </div>
      </Section>

      <Section title="Who uses what" lead={<>The office works in this console. Everyone else works on <span className="highlight">a handheld scanner</span>.</>}>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          {PEOPLE.map((person) => (
            <Card key={person.name}>
              <CardBody className="gap-2">
                <CardTitle>{person.name}</CardTitle>
                <Chip className="w-fit">{person.tool}</Chip>
                <p className="text-sm leading-relaxed text-gray-600 dark:text-gray-400">{person.text}</p>
              </CardBody>
            </Card>
          ))}
        </div>
        <p className="mt-6 text-sm text-muted-foreground">
          The real handheld is not here yet, so it is simulated.{' '}
          <Link to="/handheld" className="underline underline-offset-4 transition-colors hover:text-brand">
            Open the handhelds
          </Link>{' '}
          to see every role work side by side.
        </p>
      </Section>
    </>
  )
}
