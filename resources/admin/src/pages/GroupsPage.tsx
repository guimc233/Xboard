import { useMemo, useState, type FormEvent } from 'react'
import useSWR from 'swr'
import { Layers, Pencil, Plus, RefreshCw, Search, ShieldCheck, Trash2, UserRoundCheck } from 'lucide-react'
import { api } from '../lib/api'
import { formatDate } from '../lib/format'
import type { Group } from '../types'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, LoadingTable, Modal, PageHeader, useToast } from '../components/ui'

function GroupEditor({ group, onClose, onSaved }: {
  group?: Group
  onClose: () => void
  onSaved: () => void
}) {
  const [name, setName] = useState(group?.name || '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.adminPost('server/group/save', { id: group?.id, name })
      onSaved()
      onClose()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存权限组失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={group ? `重命名「${group.name}」` : '新建权限组'}
      description="权限组决定用户能收到哪些节点：用户属于某个组，节点勾选该组后才会出现在该组用户的订阅里。"
      footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} onClick={() => document.getElementById('group-submit')?.click()}>保存</Button></>}
    >
      <form onSubmit={submit} className="form-section">
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <Field label="权限组名称" required><Input value={name} onChange={(event) => setName(event.target.value)} placeholder="例如：默认授权组" required autoFocus /></Field>
        <button id="group-submit" type="submit" hidden />
      </form>
    </Modal>
  )
}

export function GroupsPage() {
  const [search, setSearch] = useState('')
  const [editor, setEditor] = useState<Group | 'new' | null>(null)
  const groupsRequest = useSWR('groups', () => api.adminGet<Group[]>('server/group/fetch'))
  const { notify } = useToast()
  const groups = groupsRequest.data || []

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase()
    if (!keyword) return groups
    return groups.filter((group) => group.name.toLowerCase().includes(keyword) || String(group.id) === keyword)
  }, [groups, search])

  const summary = useMemo(() => ({
    users: groups.reduce((sum, group) => sum + Number(group.users_count || 0), 0),
    servers: groups.reduce((sum, group) => sum + Number(group.server_count || 0), 0),
  }), [groups])

  async function remove(group: Group) {
    if (!window.confirm(`确认删除权限组「${group.name}」？被用户或节点占用的组无法删除，需先解绑。`)) return
    try {
      await api.adminPost('server/group/drop', { id: group.id })
      await groupsRequest.mutate()
      notify('权限组已删除')
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '删除失败', 'danger')
    }
  }

  return (
    <>
      <PageHeader
        title="权限组"
        description="用户访问的最小单位：用户—权限组—节点。节点不勾选任何权限组时不会出现在任何人的订阅中。"
        actions={<><Button variant="secondary" onClick={() => void groupsRequest.mutate()}><RefreshCw size={15} />刷新</Button><Button onClick={() => setEditor('new')}><Plus size={15} />新建权限组</Button></>}
      />

      <div className="kpi-grid">
        <Card className="kpi-card"><div className="kpi-top"><span>权限组</span><span className="kpi-icon"><Layers size={16} /></span></div><div className="kpi-value">{groups.length}</div><div className="kpi-hint">全部权限组</div></Card>
        <Card className="kpi-card"><div className="kpi-top"><span>已授权用户</span><span className="kpi-icon"><UserRoundCheck size={16} /></span></div><div className="kpi-value">{summary.users}</div><div className="kpi-hint">跨组累加</div></Card>
        <Card className="kpi-card"><div className="kpi-top"><span>已绑定节点</span><span className="kpi-icon"><ShieldCheck size={16} /></span></div><div className="kpi-value">{summary.servers}</div><div className="kpi-hint">一个节点可属于多个组</div></Card>
      </div>

      <Card>
        <form className="card-toolbar" onSubmit={(event) => event.preventDefault()}>
          <div className="search-field" style={{ position: 'relative' }}><Search size={15} style={{ position: 'absolute', left: 11, top: 11, color: 'var(--muted-foreground)' }} /><Input style={{ paddingLeft: 34 }} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="搜索权限组名称或 ID…" /></div>
          {search ? <Button variant="ghost" onClick={() => setSearch('')}>清除</Button> : null}
        </form>

        {groupsRequest.error ? <ErrorState error={groupsRequest.error} retry={() => void groupsRequest.mutate()} /> : !groupsRequest.data ? <LoadingTable rows={4} columns={5} /> : filtered.length ? (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>ID</th><th>名称</th><th>用户</th><th>节点</th><th>创建时间</th><th style={{ textAlign: 'right' }}>操作</th></tr></thead>
              <tbody>{filtered.map((group) => (
                <tr key={group.id}>
                  <td className="mono">#{group.id}</td>
                  <td><div className="table-primary"><strong>{group.name}</strong><span>用户—权限组—节点 授权链</span></div></td>
                  <td><Badge tone={Number(group.users_count || 0) ? 'info' : 'neutral'}>{Number(group.users_count || 0)} 人</Badge></td>
                  <td><Badge tone={Number(group.server_count || 0) ? 'success' : 'neutral'}>{Number(group.server_count || 0)} 个</Badge></td>
                  <td>{group.created_at ? formatDate(group.created_at) : '--'}</td>
                  <td><div className="table-actions"><Button variant="secondary" size="small" onClick={() => setEditor(group)}><Pencil size={13} />重命名</Button><Button variant="ghost" size="icon" onClick={() => void remove(group)} aria-label={`删除 ${group.name}`} title="删除权限组"><Trash2 className="text-danger" size={15} /></Button></div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : (
          <EmptyState
            icon={<Layers size={19} />}
            title={groups.length ? '没有匹配的权限组' : '尚无权限组'}
            description={groups.length ? '换个关键词再试。' : '先建一个权限组，然后在「节点管理」里勾选它，「用户授权」里把用户分到该组。'}
            action={!groups.length ? <Button onClick={() => setEditor('new')}><Plus size={15} />新建权限组</Button> : undefined}
          />
        )}
      </Card>

      {editor ? <GroupEditor key={editor === 'new' ? 'new' : editor.id} group={editor === 'new' ? undefined : editor} onClose={() => setEditor(null)} onSaved={() => { void groupsRequest.mutate(); notify(editor === 'new' ? '权限组已创建' : '权限组已更新') }} /> : null}
    </>
  )
}
