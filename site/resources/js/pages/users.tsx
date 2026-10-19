/** Users: who can log in, and in which role. A person with no work yet can be removed. Anyone already on a scan is switched off instead. */
import { useState } from 'react'

import { Count, Empty, FormDialog, Load, PageHead, Toolbar } from '@/app/ui'
import { Button } from '@/components/ui/button'
import { Chip } from '@/components/ui/chip'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAction, useLoad } from '@/lib/hooks'
import { ROLE } from '@/lib/labels'
import { useSession } from '@/lib/session'
import type { Role, User } from '@/lib/types'

type Draft = { id?: number; name: string; username: string; password: string; role: Role; is_active: boolean }

const EMPTY: Draft = { name: '', username: '', password: '', role: 'driver', is_active: true }

export function UsersPage() {
  const { api, user: me } = useSession()
  const state = useLoad(() => api.get<{ rows: User[] }>('/users').then((r) => r.rows), [api])
  const [draft, setDraft] = useState<Draft | null>(null)
  const [removing, setRemoving] = useState<User | null>(null)
  const save = useAction()
  const remove = useAction()
  const set = (patch: Partial<Draft>) => setDraft((d) => (d ? { ...d, ...patch } : d))
  const isMe = draft?.id === me?.id

  const submit = () =>
    save.run(async () => {
      const d = draft!
      if (d.id) {
        await api.patch(`/users/${d.id}`, { name: d.name, role: d.role, is_active: d.is_active, ...(d.password ? { password: d.password } : {}) })
      } else {
        await api.post('/users', { name: d.name, username: d.username, password: d.password, role: d.role })
      }
      setDraft(null)
      await state.reload()
    })

  return (
    <>
      <PageHead title="Users">
        One login per person, <span className="highlight">one role per login</span>. The role decides which screens open.
      </PageHead>

      <Toolbar info={<Count n={state.data?.length ?? 0} noun="user" />}>
        <Button variant="solid" iconRight={null} onClick={() => setDraft(EMPTY)}>
          Add user
        </Button>
      </Toolbar>

      <Load state={state}>
        {(rows) =>
          rows.length === 0 ? (
            <Empty>No users yet.</Empty>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Username</TableHead>
                  <TableHead>Role</TableHead>
                  <TableHead>Can log in</TableHead>
                  <TableHead className="w-0" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((u) => (
                  <TableRow key={u.id}>
                    <TableCell className="font-medium">{u.name}</TableCell>
                    <TableCell className="font-mono text-xs">{u.username}</TableCell>
                    <TableCell>{ROLE[u.role]}</TableCell>
                    <TableCell>{u.is_active ? <Chip tone="success">Yes</Chip> : <Chip>Switched off</Chip>}</TableCell>
                    <TableCell className="space-x-1 text-right">
                      <Button variant="ghost" size="sm" onClick={() => setDraft({ ...u, password: '' })}>
                        Edit
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => { remove.clear(); setRemoving(u) }}>
                        Remove
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )
        }
      </Load>

      <FormDialog
        open={draft !== null}
        title={draft?.id ? 'Edit user' : 'Add user'}
        description={draft?.id ? undefined : 'The username cannot be changed later.'}
        busy={save.busy}
        error={save.error}
        onSubmit={() => void submit()}
        onClose={() => {
          setDraft(null)
          save.clear()
        }}
      >
        <Field label="Name">
          <Input value={draft?.name ?? ''} onChange={(e) => set({ name: e.target.value })} required autoFocus />
        </Field>
        {!draft?.id && (
          <Field label="Username" hint="3 to 60 letters, digits, dots, dashes or underscores.">
            <Input value={draft?.username ?? ''} onChange={(e) => set({ username: e.target.value })} autoCapitalize="none" autoComplete="off" required />
          </Field>
        )}
        <Field label={draft?.id ? 'New password' : 'Password'} hint="10 to 72 characters." aside={draft?.id ? 'Leave empty to keep it' : undefined}>
          <Input type="password" value={draft?.password ?? ''} onChange={(e) => set({ password: e.target.value })} autoComplete="new-password" required={!draft?.id} minLength={10} />
        </Field>
        <Field label="Role" hint={isMe ? 'You cannot change your own role.' : undefined}>
          <Select value={draft?.role ?? 'driver'} onChange={(e) => set({ role: e.target.value as Role })} disabled={isMe} wrapperClassName="w-full">
            {(Object.keys(ROLE) as Role[]).map((r) => (
              <option key={r} value={r}>
                {ROLE[r]}
              </option>
            ))}
          </Select>
        </Field>
        {draft?.id && !isMe && (
          <label className="flex items-center gap-3 text-sm">
            <Switch checked={draft.is_active} onCheckedChange={(v) => set({ is_active: v })} />
            Can log in. Switching off logs this person out at their next step.
          </label>
        )}
      </FormDialog>
      <ConfirmDialog open={removing !== null} title={`Remove ${removing?.name ?? 'this person'}?`} confirmLabel="Remove" busyLabel="Removing" busy={remove.busy} error={remove.error} onCancel={() => { setRemoving(null); remove.clear() }} onConfirm={() => void remove.run(async () => { await api.del(`/users/${removing!.id}`); setRemoving(null); await state.reload() })}>
        Someone who has already scanned, carried or been assigned work stays. Switch them off instead.
      </ConfirmDialog>
    </>
  )
}
