/** Mat types and delivery areas: two short lists the rest of the set-up picks from. */
import { useState } from 'react'

import { Count, Empty, FormDialog, Load, PageHead, Toolbar } from '@/app/ui'
import { Button } from '@/components/ui/button'
import { Chip } from '@/components/ui/chip'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAction, useLoad } from '@/lib/hooks'
import { useSession } from '@/lib/session'

type Row = { id: number; name: string; is_active?: boolean }

/** One list of named things with an add and an edit dialog. `canSwitchOff` is false for areas: the API has no switch for them. */
function NamedList({ title, noun, path, canSwitchOff, main }: { title: string; noun: string; path: string; canSwitchOff: boolean; main?: boolean }) {
  const { api } = useSession()
  const state = useLoad(() => api.get<{ rows: Row[] }>(path).then((r) => r.rows), [api, path])
  const [editing, setEditing] = useState<Partial<Row> | null>(null)
  const save = useAction()

  const submit = () =>
    save.run(async () => {
      const body = { name: editing!.name, ...(canSwitchOff && editing!.id ? { is_active: editing!.is_active } : {}) }
      if (editing!.id) await api.patch(`${path}/${editing!.id}`, body)
      else await api.post(path, body)
      setEditing(null)
      await state.reload()
    })

  return (
    <section>
      <Toolbar info={<span className="text-xl font-medium text-foreground">{title}</span>}>
        <Button variant={main ? 'solid' : 'soft'} iconRight={null} onClick={() => setEditing({ name: '', is_active: true })}>
          Add {noun}
        </Button>
      </Toolbar>
      <Load state={state}>
        {(rows) =>
          rows.length === 0 ? (
            <Empty>No {noun} yet.</Empty>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  {canSwitchOff && <TableHead>In use</TableHead>}
                  <TableHead className="w-0" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell className="font-medium">{row.name}</TableCell>
                    {canSwitchOff && <TableCell>{row.is_active ? <Chip tone="success">Yes</Chip> : <Chip>Switched off</Chip>}</TableCell>}
                    <TableCell>
                      <Button variant="ghost" size="sm" onClick={() => setEditing(row)}>
                        Edit
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )
        }
      </Load>
      <p className="mt-2 text-xs text-muted-foreground">
        <Count n={state.data?.length ?? 0} noun={noun} />
      </p>

      <FormDialog
        open={editing !== null}
        title={editing?.id ? `Edit ${noun}` : `Add ${noun}`}
        busy={save.busy}
        error={save.error}
        onSubmit={() => void submit()}
        onClose={() => {
          setEditing(null)
          save.clear()
        }}
      >
        <Field label="Name">
          <Input value={editing?.name ?? ''} onChange={(e) => setEditing({ ...editing, name: e.target.value })} required autoFocus />
        </Field>
        {canSwitchOff && editing?.id && (
          <label className="flex items-center gap-3 text-sm">
            <Switch checked={editing.is_active ?? true} onCheckedChange={(v) => setEditing({ ...editing, is_active: v })} />
            In use. Switch off to hide it from new mats and contracts.
          </label>
        )}
      </FormDialog>
    </section>
  )
}

export function SetupPage() {
  return (
    <>
      <PageHead title="Mat types and areas">
        The short lists <span className="highlight">everything else picks from</span>. Nothing here is ever deleted, only switched off.
      </PageHead>
      <div className="grid gap-x-8 lg:grid-cols-2">
        <NamedList title="Mat types" noun="mat type" path="/item-types" canSwitchOff main />
        <NamedList title="Delivery areas" noun="area" path="/areas" canSwitchOff={false} />
      </div>
    </>
  )
}
