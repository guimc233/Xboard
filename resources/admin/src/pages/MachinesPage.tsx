import { useEffect, useMemo, useState, type FormEvent } from 'react'
import useSWR from 'swr'
import { Copy, Cpu, Download, KeyRound, Plus, RefreshCw, Search, ServerCog, Terminal, Trash2 } from 'lucide-react'
import { api } from '../lib/api'
import { copyText, relativeTime } from '../lib/format'
import type { Machine, NodeRecord } from '../types'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, LoadingTable, Modal, PageHeader, Switch, Textarea, useToast } from '../components/ui'

function online(machine: Machine) {
  if (!machine.is_active || !machine.last_seen_at) return false
  const time = typeof machine.last_seen_at === 'number' && machine.last_seen_at < 10_000_000_000
    ? machine.last_seen_at * 1000
    : new Date(machine.last_seen_at).getTime()
  return Date.now() - time <= 5 * 60 * 1000
}

function percentage(load: Record<string, unknown> | null | undefined, names: string[]) {
  for (const name of names) {
    const value = load?.[name]
    if (typeof value === 'number') return value <= 1 ? Math.round(value * 100) : Math.round(value)
  }
  return null
}

function MachineEditor({ machine, open, onClose, onSaved }: {
  machine?: Machine
  open: boolean
  onClose: () => void
  onSaved: (credential?: { id: number; token: string; install_command?: string }) => void
}) {
  const [name, setName] = useState(machine?.name || '')
  const [notes, setNotes] = useState(machine?.notes || '')
  const [active, setActive] = useState(machine ? machine.is_active : true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      const result = await api.adminPost<{ id: number; token: string; install_command?: string } | true>('server/machine/save', { id: machine?.id, name, notes: notes || null, is_active: active })
      onSaved(result === true ? undefined : result)
      onClose()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存服务器失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} title={machine ? `编辑 ${machine.name}` : '新建服务器'} description="一台服务器可以承载多个节点；一个节点也可以反向绑定多台服务器。" footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} onClick={() => document.getElementById('machine-submit')?.click()}>保存</Button></>}>
      <form onSubmit={submit} className="form-section">
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <Field label="服务器名称" required><Input value={name} onChange={(event) => setName(event.target.value)} placeholder="例如 DE-Hetzner-01" required autoFocus /></Field>
        <Field label="备注"><Textarea value={notes || ''} onChange={(event) => setNotes(event.target.value)} placeholder="运营商、机房、线路或用途" /></Field>
        <Switch checked={active} onChange={setActive} label="启用服务器" />
        <button id="machine-submit" type="submit" hidden />
      </form>
    </Modal>
  )
}

function CredentialModal({ machine, initial, onClose }: {
  machine: Machine
  initial?: { token?: string; install_command?: string }
  onClose: () => void
}) {
  const [token, setToken] = useState(initial?.token || '')
  const [command, setCommand] = useState(initial?.install_command || '')
  const [loading, setLoading] = useState(!initial?.token || !initial?.install_command)
  const { notify } = useToast()

  async function load() {
    setLoading(true)
    try {
      const [tokenResult, commandResult] = await Promise.all([
        api.adminGet<{ token: string }>(`server/machine/getToken?id=${machine.id}`),
        api.adminGet<{ command: string }>(`server/machine/installCommand?id=${machine.id}`),
      ])
      setToken(tokenResult.token)
      setCommand(commandResult.command)
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '读取接入信息失败', 'danger')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { if (loading) void load() }, [])

  async function reset() {
    if (!window.confirm('确认重置 Token？旧 Token 会立即失效。')) return
    try {
      const result = await api.adminPost<{ token: string }>('server/machine/resetToken', { id: machine.id })
      setToken(result.token)
      notify('Token 已重置')
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '重置失败', 'danger')
    }
  }

  return (
    <Modal open onClose={onClose} title={`${machine.name} 接入信息`} description={`Machine ID: ${machine.id}。以下凭据可让 xboard-node 以 machine mode 接入。`} footer={<Button variant="secondary" onClick={onClose}>关闭</Button>}>
      {loading ? <LoadingTable rows={3} columns={1} /> : <div className="form-section">
        <Field label="服务器 Token" hint="Token 属于敏感信息，请勿发到公开日志。">
          <div className="inline-fields"><Input className="mono" type="password" value={token} readOnly /><Button variant="secondary" onClick={() => void copyText(token).then(() => notify('Token 已复制'))}><Copy size={14} />复制</Button></div>
        </Field>
        <Field label="一键安装命令" hint="在目标 Linux 服务器以 root 或 sudo 权限执行。">
          <Textarea className="mono" value={command} readOnly />
        </Field>
        <div style={{ display: 'flex', gap: 8 }}><Button variant="secondary" onClick={() => void copyText(command).then(() => notify('安装命令已复制'))}><Terminal size={14} />复制命令</Button><Button variant="danger" onClick={() => void reset()}>重置 Token</Button></div>
      </div>}
    </Modal>
  )
}

export function MachinesPage() {
  const machinesRequest = useSWR('machines', () => api.adminGet<Machine[]>('server/machine/fetch'))
  const nodesRequest = useSWR('nodes', () => api.adminGet<NodeRecord[]>('server/manage/getNodes'))
  const [search, setSearch] = useState('')
  const [editor, setEditor] = useState<Machine | 'new' | null>(null)
  const [credential, setCredential] = useState<{ machine: Machine; initial?: { token?: string; install_command?: string } } | null>(null)
  const { notify } = useToast()
  const machines = machinesRequest.data || []
  const nodes = nodesRequest.data || []
  const filtered = useMemo(() => machines.filter((machine) => {
    const term = search.trim().toLowerCase()
    return !term || [machine.id, machine.name, machine.notes, machine.agent_instance_id].some((value) => String(value || '').toLowerCase().includes(term))
  }), [machines, search])

  async function remove(machine: Machine) {
    if (!window.confirm(`确认删除服务器「${machine.name}」？节点只会解绑，不会删除。`)) return
    try {
      await api.adminPost('server/machine/drop', { id: machine.id })
      await Promise.all([machinesRequest.mutate(), nodesRequest.mutate()])
      notify('服务器已删除')
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '删除失败', 'danger')
    }
  }

  async function pushUpdate(machine: Machine) {
    if (!window.confirm(`向「${machine.name}」下发 xbnode 更新？Agent 会在拉取新版本后自动重启。`)) return
    try {
      await api.adminPost('server/machine/update', { id: machine.id, version: 'dev' })
      await machinesRequest.mutate()
      notify('更新已下发，等待 Agent 拉取')
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '下发更新失败', 'danger')
    }
  }

  return (
    <>
      <PageHeader title="服务器管理" description="管理 xbnode 机器身份、心跳和承载关系。Machine ID/Token 接入与节点原 node_id 接入可以同时存在。" actions={<><Button variant="secondary" onClick={() => void machinesRequest.mutate()}><RefreshCw size={15} />刷新</Button><Button onClick={() => setEditor('new')}><Plus size={15} />新建服务器</Button></>} />

      <Card style={{ marginBottom: 16 }}>
        <div className="card-toolbar">
          <div className="search-field" style={{ position: 'relative' }}><Search size={15} style={{ position: 'absolute', left: 11, top: 11, color: 'var(--muted-foreground)' }} /><Input style={{ paddingLeft: 34 }} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="搜索服务器名称、备注或 SID…" /></div>
          <Badge tone="success">在线 {machines.filter(online).length}</Badge><Badge>总计 {machines.length}</Badge>
        </div>
      </Card>

      {machinesRequest.error ? <ErrorState error={machinesRequest.error} retry={() => void machinesRequest.mutate()} /> : !machinesRequest.data ? <LoadingTable rows={6} columns={4} /> : filtered.length ? (
        <div className="machine-grid">
          {filtered.map((machine) => {
            const machineNodes = nodes.filter((node) => node.machine_ids?.includes(machine.id))
            const cpu = percentage(machine.load_status, ['cpu', 'cpu_usage', 'cpu_percent'])
            const memory = percentage(machine.load_status, ['memory', 'mem_usage', 'mem_percent'])
			const canPushUpdate = Array.isArray(machine.capabilities) && machine.capabilities.includes('remote-self-update-v1')
            return (
              <Card className="machine-card" key={machine.id}>
                <div className="machine-card-head">
                  <div><h3>{machine.name}</h3><p>SID {machine.id} · {machine.notes || '无备注'}</p></div>
                  <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                    <Badge tone="info">{machine.kernel_type || 'mihomo'}</Badge>
                    <Badge tone={!machine.is_active ? 'neutral' : online(machine) ? 'success' : 'danger'}>{!machine.is_active ? '已停用' : online(machine) ? '在线' : '离线'}</Badge>
                  </div>
                </div>
                <div className="machine-meta">
                  <div className="meta-box"><span>承载节点</span><strong>{machineNodes.length}</strong></div>
                  <div className="meta-box"><span>最后心跳</span><strong>{relativeTime(machine.last_seen_at)}</strong></div>
                  <div className="meta-box"><span>CPU</span><strong>{cpu === null ? '--' : `${cpu}%`}</strong></div>
                  <div className="meta-box"><span>内存</span><strong>{memory === null ? '--' : `${memory}%`}</strong></div>
                </div>
                {machine.agent_version ? <p><Cpu size={12} style={{ verticalAlign: -2, marginRight: 5 }} />Agent {machine.agent_version}{machine.agent_instance_id ? ` · ${machine.agent_instance_id.slice(0, 8)}` : ''}</p> : null}
				{machine.update_status ? <p>更新 {machine.update_version || 'dev'} · <Badge tone={machine.update_status === 'succeeded' ? 'success' : machine.update_status === 'failed' ? 'danger' : 'warning'}>{machine.update_status}</Badge>{machine.update_message ? ` · ${machine.update_message}` : ''}</p> : null}
                <div className="machine-actions">
                  <Button variant="secondary" size="small" onClick={() => setEditor(machine)}>编辑</Button>
                  <Button variant="secondary" size="small" onClick={() => setCredential({ machine })}><KeyRound size={13} />接入</Button>
				  <Button variant="secondary" size="small" title={canPushUpdate ? '下发固定的 xbctl upgrade 指令' : '这台服务器需先手动升级一次 xbnode'} disabled={!machine.is_active || !canPushUpdate || machine.update_status === 'pending' || machine.update_status === 'running'} onClick={() => void pushUpdate(machine)}><Download size={13} />{canPushUpdate ? '下发更新' : '需先升级'}</Button>
                  <Button variant="ghost" size="icon" onClick={() => void remove(machine)} aria-label={`删除 ${machine.name}`}><Trash2 size={14} /></Button>
                </div>
              </Card>
            )
          })}
        </div>
      ) : <Card><EmptyState icon={<ServerCog size={19} />} title="尚无服务器" description="创建服务器记录后，会生成 machine mode 所需的 Token 与安装命令。" action={<Button onClick={() => setEditor('new')}><Plus size={15} />新建服务器</Button>} /></Card>}

      {editor ? <MachineEditor key={editor === 'new' ? 'new' : editor.id} machine={editor === 'new' ? undefined : editor} open onClose={() => setEditor(null)} onSaved={(result) => {
        void machinesRequest.mutate().then((list) => {
          if (result && list) {
            const machine = list.find((item) => item.id === result.id)
            if (machine) setCredential({ machine, initial: { token: result.token, install_command: result.install_command } })
          }
        })
        notify(editor === 'new' ? '服务器已创建' : '服务器已更新')
      }} /> : null}
      {credential ? <CredentialModal machine={credential.machine} initial={credential.initial} onClose={() => setCredential(null)} /> : null}
    </>
  )
}
