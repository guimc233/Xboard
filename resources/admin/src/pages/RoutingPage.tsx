import { useEffect, useState, type FormEvent } from 'react'
import useSWR from 'swr'
import { Braces, Copy, Database, Plus, Route, ShieldQuestion, Trash2, Waypoints } from 'lucide-react'
import { api } from '../lib/api'
import { copyText } from '../lib/format'
import type { NodeRecord, OutboundTemplate, RouteProfile, RouteTemplate, RoutingData, RuleSetTemplate } from '../types'
import { RouteTemplateModal } from '../components/RouteTemplateModal'
import { RuleSetModal } from '../components/RuleSetModal'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, LoadingTable, Modal, PageHeader, Select, Switch, useToast } from '../components/ui'

function OutboundModal({ outbound, onClose, onSaved }: {
  outbound?: OutboundTemplate
  onClose: () => void
  onSaved: () => void
}) {
  const [name, setName] = useState(outbound?.name || '')
  const [protocol, setProtocol] = useState<'shadowsocks' | 'socks5' | 'vless'>(outbound?.protocol || 'shadowsocks')
  const [server, setServer] = useState(outbound?.settings.server || '')
  const [port, setPort] = useState(String(outbound?.settings.server_port || 1080))
  const [method, setMethod] = useState(outbound?.settings.method || 'aes-256-gcm')
  const [username, setUsername] = useState(outbound?.settings.username || '')
  const [password, setPassword] = useState(outbound?.settings.password || '')
  const [uuid, setUUID] = useState(outbound?.settings.uuid || '')
  const [network, setNetwork] = useState<'tcp' | 'ws' | 'grpc'>(outbound?.settings.network || 'tcp')
  const [flow, setFlow] = useState(outbound?.settings.flow || '')
  const [tls, setTLS] = useState(Boolean(outbound?.settings.tls))
  const [serverName, setServerName] = useState(outbound?.settings.server_name || '')
  const [allowInsecure, setAllowInsecure] = useState(Boolean(outbound?.settings.allow_insecure))
  const [path, setPath] = useState(outbound?.settings.path || '')
  const [serviceName, setServiceName] = useState(outbound?.settings.service_name || '')
  const [enabled, setEnabled] = useState(outbound ? outbound.enabled : true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const { notify } = useToast()

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.adminPost('server/routing-template/outbound/save', {
        id: outbound?.id,
        name,
        protocol,
        enabled,
        settings: {
          server,
          server_port: Number(port),
          ...(protocol === 'shadowsocks'
            ? { method, password }
            : protocol === 'socks5'
              ? { username: username || null, password: password || null }
              : { uuid, network, flow: flow || null, tls, server_name: tls ? serverName || null : null, allow_insecure: tls ? allowInsecure : false, path: network === 'ws' ? path || null : null, service_name: network === 'grpc' ? serviceName || null : null }),
        },
      })
      notify(outbound ? 'Outbound 模板已更新' : 'Outbound 模板已创建')
      onSaved()
      onClose()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存模板失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} width={protocol === 'vless' ? 'large' : 'medium'} title={outbound ? `编辑 ${outbound.name}` : '新建 Outbound 模板'} description="面板会将统一模板编译为 mihomo 所需的 outbound 格式。" footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} onClick={() => document.getElementById('outbound-submit')?.click()}>保存模板</Button></>}>
      <form className="form-section" onSubmit={submit}>
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <div className="form-grid">
          <Field label="模板名称" required><Input value={name} onChange={(event) => setName(event.target.value)} placeholder="例如 HK-SS-Exit" required /></Field>
          <Field label="协议" required><Select value={protocol} onChange={(event) => setProtocol(event.target.value as typeof protocol)}><option value="shadowsocks">Shadowsocks</option><option value="socks5">SOCKS5</option><option value="vless">VLESS</option></Select></Field>
          <Field label="服务器地址" required><Input value={server} onChange={(event) => setServer(event.target.value)} placeholder="exit.example.com" required /></Field>
          <Field label="端口" required><Input type="number" min="1" max="65535" value={port} onChange={(event) => setPort(event.target.value)} required /></Field>
          {protocol === 'shadowsocks' ? <><Field label="加密方法" required><Input value={method} onChange={(event) => setMethod(event.target.value)} placeholder="aes-256-gcm" required /></Field><Field label="密码" required hint={outbound && password === '********' ? '保留星号表示不修改现有密码。' : undefined}><Input type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" required /></Field></> : null}
          {protocol === 'socks5' ? <><Field label="用户名"><Input value={username} onChange={(event) => setUsername(event.target.value)} autoComplete="off" /></Field><Field label="密码" hint={outbound && password === '********' ? '保留星号表示不修改现有密码。' : undefined}><Input type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" /></Field></> : null}
          {protocol === 'vless' ? <>
            <Field className="span-2" label="用户 UUID" required><Input className="mono" value={uuid} onChange={(event) => setUUID(event.target.value)} placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required /></Field>
            <Field label="传输"><Select value={network} onChange={(event) => setNetwork(event.target.value as typeof network)}><option value="tcp">TCP</option><option value="ws">WebSocket</option><option value="grpc">gRPC</option></Select></Field>
            <Field label="Flow"><Select value={flow} onChange={(event) => setFlow(event.target.value)}><option value="">无</option><option value="xtls-rprx-vision">xtls-rprx-vision</option></Select></Field>
            {network === 'ws' ? <Field className="span-2" label="WebSocket Path"><Input value={path} onChange={(event) => setPath(event.target.value)} placeholder="/ws" /></Field> : null}
            {network === 'grpc' ? <Field className="span-2" label="gRPC Service Name"><Input value={serviceName} onChange={(event) => setServiceName(event.target.value)} /></Field> : null}
            <div className="field switch-field"><Switch checked={tls} onChange={setTLS} label="启用 TLS" /></div>
            {tls ? <><Field label="TLS Server Name"><Input value={serverName} onChange={(event) => setServerName(event.target.value)} placeholder="exit.example.com" /></Field><div className="field switch-field"><Switch checked={allowInsecure} onChange={setAllowInsecure} label="允许不安全证书" /></div></> : null}
          </> : null}
        </div>
        <Switch checked={enabled} onChange={setEnabled} label="启用模板" />
        <button id="outbound-submit" type="submit" hidden />
      </form>
    </Modal>
  )
}

interface ServerConfiguration {
  outbounds: Array<{ template_id: number; tag: string; enabled: boolean }>
  routes: Array<{ template_id: number; enabled: boolean }>
  profiles: RouteProfile[]
  config_version: number
}

function ConfigurationModal({ node, routing, onClose }: {
  node: NodeRecord
  routing: RoutingData
  onClose: () => void
}) {
  const request = useSWR(['route-config', node.id], () => api.adminGet<ServerConfiguration>(`server/routing-template/server?server_id=${node.id}`))
  const [outbounds, setOutbounds] = useState<ServerConfiguration['outbounds']>([])
  const [routes, setRoutes] = useState<ServerConfiguration['routes']>([])
  const [profiles, setProfiles] = useState<RouteProfile[]>([])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const { notify } = useToast()

  useEffect(() => {
    if (!request.data) return
    setOutbounds(request.data.outbounds || [])
    setRoutes(request.data.routes || [])
    setProfiles((request.data.profiles || []).map((profile) => ({ ...profile, outbound_template_id: profile.outbound_template_id ? Number(profile.outbound_template_id) : null })))
  }, [request.data])

  const realitySupported = node.type === 'vless' && Number((node.protocol_settings as { tls?: unknown } | undefined)?.tls) === 2
  function outboundTag(template: OutboundTemplate) {
    return `outbound-${template.id}`
  }

  function toggleOutbound(template: OutboundTemplate, selected: boolean) {
    setOutbounds((items) => selected ? [...items, { template_id: template.id, tag: outboundTag(template), enabled: true }] : items.filter((item) => item.template_id !== template.id))
    if (!selected) setProfiles((items) => items.map((profile) => profile.outbound_template_id === template.id ? { ...profile, outbound_template_id: null } : profile))
  }

  function toggleRoute(template: RouteTemplate, selected: boolean) {
    setRoutes((items) => selected ? [...items, { template_id: template.id, enabled: true }] : items.filter((item) => item.template_id !== template.id))
  }

  async function save() {
    setSaving(true)
    setError('')
    try {
      await api.adminPost('server/routing-template/server/configure', { server_id: node.id, outbounds, routes, profiles })
      notify(`节点 ${node.name} 的路由配置已下发`)
      await request.mutate()
      onClose()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存节点路由配置失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} width="large" title={`配置节点路由 · ${node.name}`} description={`节点 #${node.id}，当前配置版本 ${request.data?.config_version ?? '--'}。保存后所有绑定后端都会收到同一版本配置。`} footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} onClick={() => void save()}>保存并下发</Button></>}>
      {request.error ? <ErrorState error={request.error} retry={() => void request.mutate()} /> : !request.data ? <LoadingTable rows={6} columns={2} /> : <div className="form-section">
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <div className="callout callout-info"><Waypoints size={17} /><div><strong>mihomo 统一配置</strong><p>模板在面板中使用规范化结构；xbnode 生成 mihomo 的 listeners 与 rules，不再区分核心。</p></div></div>

        <div className="form-section">
          <h3 className="section-heading">绑定 Outbounds</h3>
          <div className="binding-list">
            {routing.outbounds.map((template) => {
              const item = outbounds.find((outbound) => outbound.template_id === template.id)
              return <div className="binding-row" key={template.id}><label className="binding-check"><input type="checkbox" checked={Boolean(item)} onChange={(event) => toggleOutbound(template, event.target.checked)} /><span><strong>{template.name}</strong><small>{template.protocol} · {template.settings.server}:{template.settings.server_port}</small></span></label><Input disabled={!item} value={item?.tag || outboundTag(template)} onChange={(event) => setOutbounds((items) => items.map((outbound) => outbound.template_id === template.id ? { ...outbound, tag: event.target.value } : outbound))} aria-label={`${template.name} outbound tag`} /></div>
            })}
            {!routing.outbounds.length ? <EmptyState title="没有 Outbound 模板" description="先关闭此窗口并创建 Shadowsocks 或 SOCKS5 模板。" /> : null}
          </div>
        </div>

        <div className="form-section">
          <h3 className="section-heading">绑定 Route 模板</h3>
          <div className="binding-list">{routing.routes.map((template) => {
            const item = routes.find((route) => route.template_id === template.id)
            return <div className="binding-row" key={template.id}><label className="binding-check"><input type="checkbox" checked={Boolean(item)} onChange={(event) => toggleRoute(template, event.target.checked)} /><span><strong>{template.name}</strong><small>{template.rules.length} 条规则</small></span></label><Switch checked={item?.enabled ?? false} disabled={!item} onChange={(enabled) => setRoutes((items) => items.map((route) => route.template_id === template.id ? { ...route, enabled } : route))} label="启用" /></div>
          })}</div>
        </div>

        <div className="form-section">
          <h3 className="section-heading">Reality 路由档案</h3>
          <div className="callout"><ShieldQuestion size={17} /><div><strong>不要把 Short ID 当作用户路由键</strong><p>Short ID 用于 Reality 握手选择，不能稳定表达面板用户身份。这里为每个档案生成独立 UUID，用户以该 UUID 鉴权后，双核心都能映射到指定 outbound。</p></div></div>
          {realitySupported ? <>
            {profiles.map((profile, index) => <div className="profile-row" key={profile.id || index}>
              <Field label="档案名称"><Input value={profile.name} onChange={(event) => setProfiles((items) => items.map((item, itemIndex) => itemIndex === index ? { ...item, name: event.target.value } : item))} /></Field>
              <Field label="指定 Outbound"><Select value={profile.outbound_template_id || ''} onChange={(event) => setProfiles((items) => items.map((item, itemIndex) => itemIndex === index ? { ...item, outbound_template_id: event.target.value ? Number(event.target.value) : null } : item))}><option value="">默认路由</option>{outbounds.map((item) => { const template = routing.outbounds.find((candidate) => candidate.id === item.template_id); return <option key={item.template_id} value={item.template_id}>{template?.name || item.tag}</option> })}</Select></Field>
              <Switch checked={profile.enabled} onChange={(enabled) => setProfiles((items) => items.map((item, itemIndex) => itemIndex === index ? { ...item, enabled } : item))} label="启用" />
              <Button variant="ghost" size="icon" onClick={() => setProfiles((items) => items.filter((_, itemIndex) => itemIndex !== index))} aria-label="删除档案"><Trash2 size={15} /></Button>
              {profile.uuid ? <div className="span-2 mono muted" style={{ gridColumn: '1 / -1', fontSize: 11 }}>UUID: {profile.uuid}</div> : null}
            </div>)}
            <Button variant="secondary" size="small" onClick={() => setProfiles((items) => [...items, { name: `路由档案 ${items.length + 1}`, outbound_template_id: outbounds[0]?.template_id || null, enabled: true }])}><Plus size={13} />添加路由档案</Button>
          </> : <EmptyState title="此节点不是 VLESS + Reality" description="路由档案仅为 VLESS Reality 节点生成额外用户 UUID。普通 route/outbound 模板仍可正常使用。" />}
        </div>
      </div>}
    </Modal>
  )
}

export function RoutingPage() {
  const routingRequest = useSWR('routing-templates', () => api.adminGet<RoutingData>('server/routing-template/fetch'))
  const nodesRequest = useSWR('nodes', () => api.adminGet<NodeRecord[]>('server/manage/getNodes'))
  const [tab, setTab] = useState<'outbounds' | 'rule_sets' | 'routes'>('outbounds')
  const [outboundEditor, setOutboundEditor] = useState<OutboundTemplate | 'new' | null>(null)
  const [ruleSetEditor, setRuleSetEditor] = useState<RuleSetTemplate | 'new' | null>(null)
  const [routeEditor, setRouteEditor] = useState<RouteTemplate | 'new' | null>(null)
  const [configureNode, setConfigureNode] = useState<NodeRecord | null>(null)
  const { notify } = useToast()
  const routing = routingRequest.data || { outbounds: [], rule_sets: [], routes: [], reality: [] }
  const nodes = nodesRequest.data || []

  async function drop(kind: 'outbound' | 'rule_set' | 'route', template: OutboundTemplate | RuleSetTemplate | RouteTemplate) {
    if (!window.confirm(`确认删除模板「${template.name}」？已绑定的节点会失去该模板。`)) return
    try {
      await api.adminPost('server/routing-template/drop', { kind, id: template.id })
      await routingRequest.mutate()
      notify('模板已删除')
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '删除失败', 'danger')
    }
  }

  function createForCurrentTab() {
    if (tab === 'outbounds') setOutboundEditor('new')
    else if (tab === 'rule_sets') setRuleSetEditor('new')
    else setRouteEditor('new')
  }

  return (
    <>
      <PageHeader title="出站与路由" description="先定义出口和双核心规则集，再用可视化 Route 模板组合分流条件。" actions={<><Select value="" onChange={(event) => { const node = nodes.find((item) => item.id === Number(event.target.value)); if (node) setConfigureNode(node) }} style={{ width: 210 }}><option value="" disabled>选择节点进行配置…</option>{nodes.map((node) => <option key={node.id} value={node.id}>#{node.id} {node.name}</option>)}</Select><Button onClick={createForCurrentTab}><Plus size={15} />新建 {tab === 'outbounds' ? 'Outbound' : tab === 'rule_sets' ? '规则集' : 'Route'}</Button></>} />

      <div className="callout callout-info" style={{ marginBottom: 16 }}><Braces size={18} /><div><strong>规则集即 mihomo rule-provider</strong><p>每个规则集对应一个 mihomo `rule-providers` 条目，需选择 behavior（domain / ipcidr / classical）与 format（yaml / text / mrs）。路由规则用 RULE-SET 引用其标签。</p></div></div>

      <div className="tabs" role="tablist"><button type="button" role="tab" aria-selected={tab === 'outbounds'} className={`tab ${tab === 'outbounds' ? 'is-active' : ''}`} onClick={() => setTab('outbounds')}>Outbound <Badge>{routing.outbounds.length}</Badge></button><button type="button" role="tab" aria-selected={tab === 'rule_sets'} className={`tab ${tab === 'rule_sets' ? 'is-active' : ''}`} onClick={() => setTab('rule_sets')}>规则集 <Badge>{routing.rule_sets.length}</Badge></button><button type="button" role="tab" aria-selected={tab === 'routes'} className={`tab ${tab === 'routes' ? 'is-active' : ''}`} onClick={() => setTab('routes')}>Route <Badge>{routing.routes.length}</Badge></button></div>

      {routingRequest.error ? <ErrorState error={routingRequest.error} retry={() => void routingRequest.mutate()} /> : !routingRequest.data ? <LoadingTable rows={6} columns={3} /> : tab === 'outbounds' ? (
        routing.outbounds.length ? <div className="template-grid">{routing.outbounds.map((outbound) => <Card className="template-card" key={outbound.id}>
          <div className="template-card-head"><div><h3>{outbound.name}</h3><p className="mono">{outbound.uuid}</p></div><Badge tone={outbound.enabled ? 'success' : 'neutral'}>{outbound.enabled ? '启用' : '停用'}</Badge></div>
          <div className="template-meta"><div className="meta-box"><span>协议</span><strong>{outbound.protocol.toUpperCase()}</strong></div><div className="meta-box"><span>目标</span><strong className="truncate">{outbound.settings.server}:{outbound.settings.server_port}</strong></div></div>
          <div className="template-actions"><Button variant="secondary" size="small" onClick={() => setOutboundEditor(outbound)}>编辑</Button><Button variant="ghost" size="icon" onClick={() => void copyText(outbound.uuid).then(() => notify('UUID 已复制'))} aria-label="复制 UUID"><Copy size={14} /></Button><Button variant="ghost" size="icon" onClick={() => void drop('outbound', outbound)} aria-label={`删除 ${outbound.name}`}><Trash2 size={14} /></Button></div>
        </Card>)}</div> : <Card><EmptyState icon={<Waypoints size={20} />} title="没有 Outbound 模板" description="创建 Shadowsocks 或 SOCKS5 出站，再把它绑定到一个或多个节点。" action={<Button onClick={() => setOutboundEditor('new')}><Plus size={15} />新建 Outbound</Button>} /></Card>
      ) : tab === 'rule_sets' ? (routing.rule_sets.length ? <div className="template-grid">{routing.rule_sets.map((ruleSet) => <Card className="template-card" key={ruleSet.id}>
        <div className="template-card-head"><div><h3>{ruleSet.name}</h3><p className="mono">{ruleSet.tag}</p></div><Badge tone={ruleSet.enabled ? 'success' : 'neutral'}>{ruleSet.enabled ? '启用' : '停用'}</Badge></div>
        <div className="template-meta"><div className="meta-box"><span>来源</span><strong>{ruleSet.settings.mihomo.type}</strong></div><div className="meta-box"><span>行为 / 格式</span><strong>{ruleSet.settings.mihomo.behavior} · {(ruleSet.settings.mihomo.format || 'yaml')}</strong></div></div>
        <div className="template-actions"><Button variant="secondary" size="small" onClick={() => setRuleSetEditor(ruleSet)}>编辑</Button><Button variant="ghost" size="icon" onClick={() => void copyText(ruleSet.uuid).then(() => notify('规则集 UUID 已复制'))} aria-label="复制规则集 UUID"><Copy size={14} /></Button><Button variant="ghost" size="icon" onClick={() => void drop('rule_set', ruleSet)} aria-label={`删除 ${ruleSet.name}`}><Trash2 size={14} /></Button></div>
      </Card>)}</div> : <Card><EmptyState icon={<Database size={20} />} title="没有规则集" description="创建一个 mihomo rule-provider，选择 behavior 与 format。" action={<Button onClick={() => setRuleSetEditor('new')}><Plus size={15} />新建规则集</Button>} /></Card>
      ) : routing.routes.length ? <div className="template-grid">{routing.routes.map((route) => <Card className="template-card" key={route.id}>
        <div className="template-card-head"><div><h3>{route.name}</h3><p className="mono">{route.uuid}</p></div><Badge tone={route.enabled ? 'success' : 'neutral'}>{route.enabled ? '启用' : '停用'}</Badge></div>
        <div className="template-meta"><div className="meta-box"><span>规则数量</span><strong>{route.rules.length}</strong></div><div className="meta-box"><span>动作</span><strong>{Array.from(new Set(route.rules.map((rule) => rule.action.type))).join(' / ') || '--'}</strong></div></div>
        <div className="template-actions"><Button variant="secondary" size="small" onClick={() => setRouteEditor(route)}>编辑</Button><Button variant="ghost" size="icon" onClick={() => void drop('route', route)} aria-label={`删除 ${route.name}`}><Trash2 size={14} /></Button></div>
      </Card>)}</div> : <Card><EmptyState icon={<Route size={20} />} title="没有 Route 模板" description="从域名、CIDR、端口或网络类型开始定义第一组路由规则。" action={<Button onClick={() => setRouteEditor('new')}><Plus size={15} />新建 Route</Button>} /></Card>}

      {outboundEditor ? <OutboundModal key={outboundEditor === 'new' ? 'new' : outboundEditor.id} outbound={outboundEditor === 'new' ? undefined : outboundEditor} onClose={() => setOutboundEditor(null)} onSaved={() => void routingRequest.mutate()} /> : null}
      {ruleSetEditor ? <RuleSetModal key={ruleSetEditor === 'new' ? 'new' : ruleSetEditor.id} ruleSet={ruleSetEditor === 'new' ? undefined : ruleSetEditor} onClose={() => setRuleSetEditor(null)} onSaved={() => void routingRequest.mutate()} /> : null}
      {routeEditor ? <RouteTemplateModal key={routeEditor === 'new' ? 'new' : routeEditor.id} route={routeEditor === 'new' ? undefined : routeEditor} ruleSets={routing.rule_sets} onClose={() => setRouteEditor(null)} onSaved={() => void routingRequest.mutate()} /> : null}
      {configureNode ? <ConfigurationModal node={configureNode} routing={routing} onClose={() => setConfigureNode(null)} /> : null}
    </>
  )
}
