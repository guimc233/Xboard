import { useMemo, useState, type DragEvent, type FormEvent } from 'react'
import useSWR from 'swr'
import { Braces, Copy, GripVertical, KeyRound, ListOrdered, Network, Pencil, Plus, RefreshCw, Route as RouteIcon, Save, Search, Sparkles, Trash2, Waypoints, X } from 'lucide-react'
import { api } from '../lib/api'
import { formatBytes } from '../lib/format'
import type { Group, Machine, MachineBinding, MachineBindingState, NodeRecord, RoutingData } from '../types'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, LoadingTable, Modal, PageHeader, Select, Switch, Textarea, useToast } from '../components/ui'
import { NetworkSettingsEditor } from '../components/NetworkSettingsEditor'
import { NodeRoutingConfigurationModal } from '../components/NodeRoutingConfigurationModal'
import { MihomoInboundEditor } from '../components/MihomoInboundEditor'
import { MIHOMO_INBOUNDS, mihomoInboundMeta } from '../lib/mihomo'

const protocols = MIHOMO_INBOUNDS.map((inbound) => inbound.type)
const protocolLabel = (type: string) => mihomoInboundMeta(type)?.label || type.toUpperCase()
const transportOptions = ['tcp', 'ws', 'grpc', 'h2', 'kcp', 'httpupgrade', 'xhttp']
const shadowsocksCiphers = [
  '2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm', '2022-blake3-chacha20-poly1305',
  'aes-128-gcm', 'aes-192-gcm', 'aes-256-gcm', 'chacha20-ietf-poly1305', 'xchacha20-ietf-poly1305',
  'none', 'dummy',
]
const defaultPaddingScheme = [
  'stop=8', '0=30-30', '1=100-400',
  '2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000',
  '3=9-9,500-1000', '4=500-1000', '5=500-1000', '6=500-1000', '7=500-1000',
]

const tlsSettings = () => ({
  server_name: '', allow_insecure: false,
  ech: { enabled: false, config: '', query_server_name: '', key: '' },
})

const multiplexSettings = () => ({
  enabled: false, protocol: 'smux', max_connections: 4, padding: false,
  brutal: { enabled: false, up_mbps: 100, down_mbps: 100 },
})

function defaultProtocolSettings(type: string): Record<string, unknown> {
  if (type === 'vless') return { tls: 0, tls_settings: tlsSettings(), flow: '', encryption: { enabled: false, encryption: '', decryption: '' }, network: 'tcp', network_settings: {}, multiplex: multiplexSettings(), utls: { enabled: true, fingerprint: 'chrome' } }
  if (type === 'vmess') return { tls: 0, tls_settings: tlsSettings(), network: 'tcp', network_settings: {}, rules: [], multiplex: multiplexSettings(), utls: { enabled: true, fingerprint: 'chrome' } }
  if (type === 'trojan') return { tls: 1, network: 'tcp', network_settings: {}, tls_settings: tlsSettings(), multiplex: multiplexSettings(), utls: { enabled: true, fingerprint: 'chrome' } }
  if (type === 'shadowsocks') return { cipher: 'aes-128-gcm', password: '', udp: true, obfs: '', obfs_settings: { path: '', host: '' }, plugin: '', plugin_opts: '' }
  if (type === 'hysteria2') return { version: 2, up: null, down: null, obfs: { open: false, type: 'salamander', password: '' }, tls: tlsSettings(), hop_interval: null, bbr_profile: '' }
  if (type === 'tuic') return { version: 5, congestion_controller: 'bbr', alpn: ['h3'], udp_relay_mode: 'native', tls: tlsSettings() }
  if (type === 'anytls') return { tls: tlsSettings(), padding_scheme: defaultPaddingScheme }
  if (type === 'socks' || type === 'mixed') return { tls: 0, udp: true, tls_settings: tlsSettings() }
  if (type === 'http') return { tls: 1, tls_settings: tlsSettings() }
  if (type === 'mieru') return { transport: 'TCP', traffic_pattern: '', multiplex: multiplexSettings() }
  if (type === 'snell') return { psk: '', version: 4, udp: true, obfs_opts: { mode: '', host: '' } }
  if (type === 'sudoku') return { key: '', aead_method: 'chacha20-poly1305', table_type: 'prefer_ascii', enable_pure_downlink: false }
  if (type === 'shadowquic') return { jls_upstream: { addr: '', sni: '', proxy: '' }, alpn: ['h3'], quic_versions: ['v1'], zero_rtt: true, congestion_controller: 'bbr', cwnd: 32 }
  if (type === 'trusttunnel') return { network: ['tcp'], congestion_controller: '', bbr_profile: '' }
  return {}
}

interface NodeDraft {
  id?: number
  name: string
  type: string
  host: string
  port: string
  serverPort: string
  rate: string
  code: string
  show: boolean
  enabled: boolean
  parentId: string
  groupIds: number[]
  protocolSettings: string
  certificate: CertificateDraft
  customOutbounds: string
  customRoutes: string
  bindings: MachineBinding[]
}

interface CertificateDraft {
  certMode: string
  domain: string
  email: string
  dnsProvider: string
  dnsEnv: string
  httpPort: string
  certFile: string
  keyFile: string
  certContent: string
  keyContent: string
}

function toCertificateDraft(node?: NodeRecord): CertificateDraft {
  const config = node?.cert_config || {}
  const dnsEnv = config.dns_env && typeof config.dns_env === 'object'
    ? Object.entries(config.dns_env).map(([key, value]) => `${key}=${value}`).join('\n')
    : ''

  return {
    certMode: String(config.cert_mode || config.mode || 'none'),
    domain: String(config.domain || ''),
    email: String(config.email || ''),
    dnsProvider: String(config.dns_provider || ''),
    dnsEnv,
    httpPort: String(config.http_port || 80),
    certFile: String(config.cert_file || ''),
    keyFile: String(config.key_file || ''),
    certContent: String(config.cert_content || ''),
    keyContent: String(config.key_content || ''),
  }
}

function toDraft(node?: NodeRecord): NodeDraft {
  const type = node?.type || 'vless'
  return {
    id: node?.id,
    name: node?.name || '',
    type,
    host: node?.host || '',
    port: String(node?.port || '443'),
    serverPort: String(node?.server_port || node?.port || '443'),
    rate: String(node?.rate ?? 1),
    code: node?.code || '',
    show: node ? Boolean(node.show) : true,
    enabled: node ? Boolean(node.enabled) : true,
    parentId: node?.parent_id ? String(node.parent_id) : '',
    groupIds: (node?.group_ids || []).map(Number).filter(Number.isFinite),
    protocolSettings: JSON.stringify(node?.protocol_settings || defaultProtocolSettings(type), null, 2),
    certificate: toCertificateDraft(node),
    customOutbounds: JSON.stringify(node?.custom_outbounds || [], null, 2),
    customRoutes: JSON.stringify(node?.custom_routes || [], null, 2),
    bindings: node?.machine_bindings?.map((binding) => ({ ...binding })) || [],
  }
}

function toCopiedChildDraft(source: NodeRecord, nodes: NodeRecord[]): NodeDraft {
  const parent = source.parent_id
    ? nodes.find((candidate) => candidate.id === source.parent_id)
    : source
  const draft = toDraft(source)

  return {
    ...draft,
    id: undefined,
    name: `${parent?.name || source.name}@`,
    host: '',
    code: '',
    parentId: parent ? String(parent.id) : '',
    bindings: [],
  }
}

function parseDnsEnv(value: string): Record<string, string> {
  if (!value.trim()) return {}
  if (value.trim().startsWith('{')) {
    let parsed: unknown
    try {
      parsed = JSON.parse(value)
    } catch {
      throw new Error('DNS 环境变量不是有效的 JSON。')
    }
    if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') throw new Error('DNS 环境变量必须是对象或 KEY=VALUE 列表。')
    return Object.fromEntries(Object.entries(parsed).map(([key, item]) => [key, String(item)]))
  }

  const result: Record<string, string> = {}
  value.split('\n').forEach((line, index) => {
    const trimmed = line.trim()
    if (!trimmed || trimmed.startsWith('#')) return
    const separator = trimmed.indexOf('=')
    if (separator < 1) throw new Error(`DNS 环境变量第 ${index + 1} 行应为 KEY=VALUE。`)
    result[trimmed.slice(0, separator).trim()] = trimmed.slice(separator + 1).trim()
  })
  return result
}

function parseJsonArray(value: string, label: string): unknown[] {
  let parsed: unknown
  try {
    parsed = value.trim() ? JSON.parse(value) : []
  } catch {
    throw new Error(`${label} 不是有效的 JSON。`)
  }
  if (!Array.isArray(parsed)) throw new Error(`${label} 必须是 JSON 数组 []。`)
  return parsed
}

function jsonArrayError(value: string): string {
  try {
    return Array.isArray(value.trim() ? JSON.parse(value) : []) ? '' : '必须是 JSON 数组 []。'
  } catch {
    return 'JSON 格式无效。'
  }
}

function NodeModal({ node, copyFrom, nodes, machines, groups, open, onClose, onSaved }: {
  node?: NodeRecord
  copyFrom?: NodeRecord
  nodes: NodeRecord[]
  machines: Machine[]
  groups: Group[]
  open: boolean
  onClose: () => void
  onSaved: () => void
}) {
  const template = node || copyFrom
  const [draft, setDraft] = useState<NodeDraft>(() => copyFrom ? toCopiedChildDraft(copyFrom, nodes) : toDraft(node))
  const [saving, setSaving] = useState(false)
  const [generatingReality, setGeneratingReality] = useState(false)
  const [routingTab, setRoutingTab] = useState<'outbounds' | 'routes'>('outbounds')
  const [error, setError] = useState('')
  const { notify } = useToast()
  const parsedProtocolSettings = useMemo<Record<string, any>>(() => {
    try {
      const value = JSON.parse(draft.protocolSettings)
      return value && !Array.isArray(value) && typeof value === 'object' ? value : {}
    } catch {
      return {}
    }
  }, [draft.protocolSettings])
  const realitySettings = (parsedProtocolSettings.reality_settings || {}) as Record<string, any>
  const protocolTLSMode = Number(parsedProtocolSettings.tls ?? (draft.type === 'trojan' ? 1 : 0))
  const inboundMeta = mihomoInboundMeta(draft.type)
  const supportsTLSCertificate = Boolean(inboundMeta && inboundMeta.tls !== 'none')
  // tls === 2 on vmess/vless/trojan means REALITY, which replaces the certificate.
  const usesTLSCertificate = inboundMeta?.tls === 'required'
    || (inboundMeta?.tls === 'optional' && protocolTLSMode === 1)
  const requiresTLSCertificate = inboundMeta?.tls === 'required'
    || (draft.type === 'trojan' && protocolTLSMode !== 2)
  const outboundError = useMemo(() => jsonArrayError(draft.customOutbounds), [draft.customOutbounds])
  const routeError = useMemo(() => jsonArrayError(draft.customRoutes), [draft.customRoutes])
  const outboundCount = useMemo(() => {
    try { return (JSON.parse(draft.customOutbounds) as unknown[]).length || 0 } catch { return 0 }
  }, [draft.customOutbounds])
  const routeCount = useMemo(() => {
    try { return (JSON.parse(draft.customRoutes) as unknown[]).length || 0 } catch { return 0 }
  }, [draft.customRoutes])
  const parentCandidates = useMemo(() => nodes.filter((candidate) => (
    candidate.id !== node?.id
    && candidate.type === draft.type
    && !candidate.parent_id
  )), [draft.type, node?.id, nodes])
  const selectedParent = parentCandidates.find((candidate) => String(candidate.id) === draft.parentId)
  const googleWarpConfigured = useMemo(() => {
    try {
      const outbounds = JSON.parse(draft.customOutbounds) as Array<Record<string, any>>
      const routes = JSON.parse(draft.customRoutes) as Array<Record<string, any>>
      return outbounds.some((item) => item.tag === 'warp') && routes.some((item) => item.outboundTag === 'warp' && Array.isArray(item.domain) && item.domain.includes('geosite:google'))
    } catch {
      return false
    }
  }, [draft.customOutbounds, draft.customRoutes])

  function replaceProtocolSettings(settings: Record<string, unknown>) {
    setDraft((current) => ({ ...current, protocolSettings: JSON.stringify(settings, null, 2) }))
  }

  function updateProtocolValue(key: string, value: unknown) {
    replaceProtocolSettings({ ...parsedProtocolSettings, [key]: value })
  }

  function updateCertificate<K extends keyof CertificateDraft>(key: K, value: CertificateDraft[K]) {
    setDraft((current) => ({ ...current, certificate: { ...current.certificate, [key]: value } }))
  }

  function protocolValue(path: string, fallback: unknown = ''): any {
    const value = path.split('.').reduce<any>((current, key) => current?.[key], parsedProtocolSettings)
    return value ?? fallback
  }

  function updateProtocolPath(path: string, value: unknown) {
    const next = JSON.parse(JSON.stringify(parsedProtocolSettings || {})) as Record<string, any>
    const keys = path.split('.')
    let cursor: Record<string, any> = next
    keys.slice(0, -1).forEach((key) => {
      if (!cursor[key] || Array.isArray(cursor[key]) || typeof cursor[key] !== 'object') cursor[key] = {}
      cursor = cursor[key]
    })
    cursor[keys[keys.length - 1]] = value
    replaceProtocolSettings(next)
  }

  function optionalNumber(value: string): number | null {
    return value === '' ? null : Number(value)
  }

  function updateRealityValue(key: string, value: unknown) {
    replaceProtocolSettings({
      ...parsedProtocolSettings,
      tls: 2,
      reality_settings: { ...realitySettings, [key]: value },
    })
  }

  function applyGoogleWarpPreset() {
    try {
      const outbounds = parseJsonArray(draft.customOutbounds, '自定义 Outbounds') as Array<Record<string, unknown>>
      const routes = parseJsonArray(draft.customRoutes, '自定义 Routes') as Array<Record<string, unknown>>
      const nextOutbounds = [
        ...outbounds.filter((item) => item.tag !== 'warp'),
        { tag: 'warp', protocol: 'socks', settings: { server: '127.0.0.1', server_port: 1080 } },
      ]
      const nextRoutes = [
        ...routes.filter((item) => !(item.outboundTag === 'warp' && Array.isArray(item.domain) && item.domain.includes('geosite:google'))),
        { domain: ['geosite:google'], outboundTag: 'warp' },
      ]
      setDraft((current) => ({ ...current, customOutbounds: JSON.stringify(nextOutbounds, null, 2), customRoutes: JSON.stringify(nextRoutes, null, 2) }))
      setError('')
      notify('已填入 Google → 本机 WARP SOCKS 预设，请保存节点')
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '无法应用 WARP 预设')
    }
  }

  async function generateReality() {
    setGeneratingReality(true)
    setError('')
    try {
      const result = await api.adminPost<{ private_key: string; public_key: string; short_id: string }>('server/routing-template/reality/generate', {})
      replaceProtocolSettings({
        ...parsedProtocolSettings,
        tls: 2,
        network: parsedProtocolSettings.network || 'tcp',
        flow: parsedProtocolSettings.flow || 'xtls-rprx-vision',
        reality_settings: { ...realitySettings, ...result },
      })
      notify('已生成节点 REALITY 密钥')
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '生成 REALITY 密钥失败')
    } finally {
      setGeneratingReality(false)
    }
  }

  function updateBinding(machineId: number, checked: boolean) {
    setDraft((current) => ({
      ...current,
      bindings: checked
        ? [...current.bindings, { machine_id: machineId, state: 'active' }]
        : current.bindings.filter((binding) => binding.machine_id !== machineId),
    }))
  }

  function updateGroup(groupId: number, checked: boolean) {
    setDraft((current) => ({
      ...current,
      groupIds: checked
        ? Array.from(new Set([...current.groupIds, groupId]))
        : current.groupIds.filter((id) => id !== groupId),
    }))
  }

  function updateBindingState(machineId: number, state: MachineBindingState) {
    setDraft((current) => ({
      ...current,
      bindings: current.bindings.map((binding) => binding.machine_id === machineId ? { ...binding, state } : binding),
    }))
  }

  async function save(event: FormEvent) {
    event.preventDefault()
    setError('')
    let protocolSettings: Record<string, unknown>
    let customOutbounds: unknown[]
    let customRoutes: unknown[]
    let dnsEnv: Record<string, string>
    try {
      protocolSettings = JSON.parse(draft.protocolSettings)
      if (!protocolSettings || Array.isArray(protocolSettings) || typeof protocolSettings !== 'object') throw new Error()
    } catch {
      setError('协议配置必须是有效的 JSON 对象。')
      return
    }
    try {
      customOutbounds = parseJsonArray(draft.customOutbounds, '自定义 Outbounds')
      customRoutes = parseJsonArray(draft.customRoutes, '自定义 Routes')
      dnsEnv = parseDnsEnv(draft.certificate.dnsEnv)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '节点配置无效。')
      return
    }
    const certMode = draft.certificate.certMode
    if (requiresTLSCertificate && certMode === 'none') {
      setError(`${draft.type.toUpperCase()} 当前配置需要 TLS 证书，请在“TLS 证书”中选择证书模式。`)
      return
    }
    if (certMode === 'content' && (!draft.certificate.certContent.trim() || !draft.certificate.keyContent.trim())) {
      setError('内容推送模式需要同时填写证书内容和私钥内容。')
      return
    }
    if (certMode === 'file' && (!draft.certificate.certFile.trim() || !draft.certificate.keyFile.trim())) {
      setError('本地文件模式需要同时填写证书文件和私钥文件路径。')
      return
    }
    if (['http', 'dns'].includes(certMode) && !draft.certificate.domain.trim()) {
      setError(`${certMode.toUpperCase()} ACME 模式需要填写证书域名。`)
      return
    }
    if (certMode === 'dns' && !draft.certificate.dnsProvider.trim()) {
      setError('DNS ACME 模式需要填写 DNS 提供商。')
      return
    }
    if (draft.show && !draft.groupIds.length) {
      setError('用户可见节点至少需要选择一个权限组，否则不会出现在任何用户订阅中。')
      return
    }
    setSaving(true)
    try {
      await api.adminPost('server/manage/save', {
        ...(node || {}),
        id: draft.id,
        name: draft.name,
        type: draft.type,
        host: draft.host,
        port: Number(draft.port),
        server_port: Number(draft.serverPort),
        rate: Number(draft.rate),
        code: draft.code || null,
        show: draft.show ? 1 : 0,
        enabled: draft.enabled,
        parent_id: draft.parentId ? Number(draft.parentId) : null,
        group_ids: draft.groupIds.map(String),
        route_ids: template?.route_ids || [],
        tags: template?.tags || [],
        protocol_settings: protocolSettings,
        cert_config: {
          cert_mode: certMode,
          domain: draft.certificate.domain.trim(),
          email: draft.certificate.email.trim(),
          dns_provider: draft.certificate.dnsProvider.trim(),
          dns_env: dnsEnv,
          http_port: Number(draft.certificate.httpPort || 80),
          cert_file: draft.certificate.certFile.trim(),
          key_file: draft.certificate.keyFile.trim(),
          cert_content: draft.certificate.certContent,
          key_content: draft.certificate.keyContent,
        },
        custom_outbounds: customOutbounds,
        custom_routes: customRoutes,
        machine_bindings: draft.parentId ? [] : draft.bindings.map((binding) => ({ machine_id: binding.machine_id, state: binding.state })),
      })
      notify(node ? '节点已更新' : copyFrom ? '子节点已创建' : '节点已创建')
      onSaved()
      onClose()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存节点失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      width="large"
      title={node ? `编辑节点 #${node.id}` : copyFrom ? `复制「${copyFrom.name}」为子节点` : '新建节点'}
      description={copyFrom ? '已继承协议、端口、权限组和父节点；填写名称后缀与新的入口地址即可保存。' : '一个节点可以同时绑定多台 xbnode 服务器；不绑定时仍可使用原 node_id 方式独立接入。'}
      footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} onClick={() => document.getElementById('node-form-submit')?.click()}>保存节点</Button></>}
    >
      <form id="node-form" onSubmit={save}>
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <div className="form-section">
          <h3 className="section-heading">基础配置</h3>
          <div className="form-grid">
            <Field label="节点名称" required><Input value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} placeholder="例如 HK-HKT" required /></Field>
            <Field label="协议类型" required>
              <Select value={draft.type} onChange={(event) => {
                const type = event.target.value
                setDraft({ ...draft, type, parentId: '', protocolSettings: JSON.stringify(defaultProtocolSettings(type), null, 2) })
              }}>{protocols.map((protocol) => <option value={protocol} key={protocol}>{protocolLabel(protocol)}</option>)}</Select>
            </Field>
            <Field label="连接地址" required><Input value={draft.host} onChange={(event) => setDraft({ ...draft, host: event.target.value })} placeholder="node.example.com" required /></Field>
            <Field label="自定义 node_id" hint="可留空使用数据库 ID；原 node_id 模式和 machine 模式可并存。"><Input value={draft.code} onChange={(event) => setDraft({ ...draft, code: event.target.value })} placeholder="留空自动使用节点 ID" /></Field>
            <Field label="父级节点" hint="用于仅改变订阅入口地址的别名节点；实际监听、用户和流量统计复用父节点。">
              <Select value={draft.parentId} onChange={(event) => {
                const parent = nodes.find((candidate) => candidate.id === Number(event.target.value))
                setDraft((current) => parent ? {
                  ...current,
                  parentId: String(parent.id),
                  type: parent.type,
                  serverPort: String(parent.server_port),
                  protocolSettings: JSON.stringify(parent.protocol_settings || defaultProtocolSettings(parent.type), null, 2),
                  certificate: toCertificateDraft(parent),
                  bindings: [],
                } : { ...current, parentId: '' })
              }}>
                <option value="">无（实际后端节点）</option>
                {parentCandidates.map((candidate) => <option key={candidate.id} value={candidate.id}>#{candidate.id} · {candidate.name}</option>)}
              </Select>
            </Field>
            <Field label="用户连接端口" required><Input type="number" min="1" max="65535" value={draft.port} onChange={(event) => setDraft({ ...draft, port: event.target.value })} required /></Field>
            <Field label="后端监听端口" required><Input type="number" min="1" max="65535" value={draft.serverPort} onChange={(event) => setDraft({ ...draft, serverPort: event.target.value })} required /></Field>
            <Field label="流量倍率" required><Input type="number" min="0" step="0.1" value={draft.rate} onChange={(event) => setDraft({ ...draft, rate: event.target.value })} required /></Field>
            <div style={{ display: 'flex', gap: 20, alignItems: 'end', paddingBottom: 8 }}>
              <Switch checked={draft.show} onChange={(show) => setDraft({ ...draft, show })} label="用户可见" />
              <Switch checked={draft.enabled} onChange={(enabled) => setDraft({ ...draft, enabled })} label="后端启用" />
            </div>
            <div className="field span-2">
              <span className="field-label">用户权限组{draft.show ? <span className="required-mark"> *</span> : null}</span>
              <div className="permission-group-grid">
                {groups.map((group) => <label className="permission-group-choice" key={group.id}>
                  <input type="checkbox" checked={draft.groupIds.includes(group.id)} onChange={(event) => updateGroup(group.id, event.target.checked)} />
                  <span><strong>{group.name}</strong><small>{group.users_count ?? 0} 位用户</small></span>
                </label>)}
                {!groups.length ? <span className="field-error">尚无权限组，请先在「访问控制 → 权限组」中创建。</span> : null}
              </div>
              <span className="field-hint">只有所属权限组的用户会在订阅中收到该节点；“用户可见”并不会自动授权所有用户。</span>
            </div>
          </div>
        </div>

        {supportsTLSCertificate ? <div className="form-section">
          <h3 className="section-heading">TLS 证书</h3>
          <div className={`callout ${usesTLSCertificate && draft.certificate.certMode === 'none' ? 'callout-danger' : 'callout-info'}`}>
            <KeyRound size={17} />
            <div>
              <strong>{usesTLSCertificate && draft.certificate.certMode === 'none' ? 'TLS 已启用，但尚未配置证书' : '证书由面板下发给 xbnode'}</strong>
              <p>{requiresTLSCertificate
                ? `${draft.type.toUpperCase()} 必须取得证书后才能启动。多台后端建议使用“内容推送”让所有机器获得同一套证书。`
                : '若 TLS 在 Nginx/CDN 终止可保留“无”；由 xbnode 直接承载 TLS 时请选择一种证书模式。'}</p>
            </div>
          </div>
          <div className="form-grid">
            <Field label="证书模式" required hint="与旧版 TLS 证书选项一致；内容推送适合多机器和 DNS 均衡。">
              <Select value={draft.certificate.certMode} onChange={(event) => updateCertificate('certMode', event.target.value)}>
                <option value="none">无</option>
                <option value="content">内容推送（Cert Push）</option>
                <option value="http">HTTP-01 自动申请</option>
                <option value="dns">DNS-01 自动申请</option>
                <option value="self">自签名证书</option>
                <option value="file">节点本地文件</option>
              </Select>
            </Field>
            {['content', 'http', 'dns', 'self'].includes(draft.certificate.certMode) ? <Field label="证书域名" required={['http', 'dns'].includes(draft.certificate.certMode)}><Input value={draft.certificate.domain} onChange={(event) => updateCertificate('domain', event.target.value)} placeholder={draft.host || 'example.com'} /></Field> : null}
            {['http', 'dns'].includes(draft.certificate.certMode) ? <Field label="通知邮箱"><Input type="email" value={draft.certificate.email} onChange={(event) => updateCertificate('email', event.target.value)} placeholder="admin@example.com" /></Field> : null}
            {draft.certificate.certMode === 'http' ? <Field label="认证端口" hint="HTTP-01 挑战端口，默认 80；需确保外网能访问这台机器。"><Input type="number" min="1" max="65535" value={draft.certificate.httpPort} onChange={(event) => updateCertificate('httpPort', event.target.value)} /></Field> : null}
            {draft.certificate.certMode === 'dns' ? <>
              <Field label="DNS 提供商" required hint="xbnode 支持 cloudflare、alidns、tencentcloud、route53 等。"><Input list="dns-certificate-providers" value={draft.certificate.dnsProvider} onChange={(event) => updateCertificate('dnsProvider', event.target.value)} placeholder="cloudflare" /><datalist id="dns-certificate-providers">{['cloudflare', 'alidns', 'tencentcloud', 'route53', 'azure', 'googleclouddns', 'digitalocean', 'hetzner', 'vultr', 'porkbun', 'namecheap', 'namesilo', 'godaddy', 'gandi', 'ovh', 'linode', 'huaweicloud', 'bunny', 'duckdns', 'desec', 'netlify'].map((provider) => <option key={provider} value={provider} />)}</datalist></Field>
              <Field className="span-2" label="环境变量（API 密钥）" hint="每行一个 KEY=VALUE；也兼容 JSON 对象。密钥会随节点配置下发到绑定的机器。"><Textarea value={draft.certificate.dnsEnv} onChange={(event) => updateCertificate('dnsEnv', event.target.value)} placeholder={'CLOUDFLARE_DNS_API_TOKEN=...'} spellCheck={false} /></Field>
            </> : null}
            {draft.certificate.certMode === 'content' ? <>
              <Field className="span-2" label="证书内容（Public Key / fullchain.pem）" required><Textarea value={draft.certificate.certContent} onChange={(event) => updateCertificate('certContent', event.target.value)} placeholder="-----BEGIN CERTIFICATE-----" spellCheck={false} /></Field>
              <Field className="span-2" label="密钥内容（Private Key / privkey.pem）" required><Textarea value={draft.certificate.keyContent} onChange={(event) => updateCertificate('keyContent', event.target.value)} placeholder="-----BEGIN PRIVATE KEY-----" spellCheck={false} /></Field>
            </> : null}
            {draft.certificate.certMode === 'file' ? <>
              <Field label="证书文件路径" required><Input className="mono" value={draft.certificate.certFile} onChange={(event) => updateCertificate('certFile', event.target.value)} placeholder="/etc/xboard-node/certs/fullchain.pem" /></Field>
              <Field label="私钥文件路径" required><Input className="mono" value={draft.certificate.keyFile} onChange={(event) => updateCertificate('keyFile', event.target.value)} placeholder="/etc/xboard-node/certs/privkey.pem" /></Field>
            </> : null}
          </div>
          {draft.certificate.certMode === 'http' && draft.bindings.length > 1 ? <div className="callout callout-danger"><Network size={17} /><div><strong>多后端不建议 HTTP-01</strong><p>DNS 可能把 ACME 验证请求送到另一台机器。请优先使用内容推送或 DNS-01。</p></div></div> : null}
        </div> : null}

        <div className="form-section">
          <h3 className="section-heading">多后端绑定</h3>
          {selectedParent ? <div className="callout callout-info">
            <Waypoints size={17} />
            <div><strong>订阅入口复用父节点 #{selectedParent.id}</strong><p>此节点只下发 {draft.host}:{draft.port}；xbnode 不会为它重复启动端口，认证、出口和流量统计均归入「{selectedParent.name}」。</p></div>
          </div> : <><div className="callout">
            <Network size={17} />
            <div><strong>{draft.bindings.length ? `已选择 ${draft.bindings.length} 台服务器` : '独立部署'}</strong><p>同一运营商的多台机器可以同时选择，由 DNS 或上层入口做均衡。每个来源的在线设备快照独立保存后再取并集。</p></div>
          </div>
          <div className="callout callout-info routing-preset-callout">
            <Sparkles size={17} />
            <div><strong>Google → WARP {googleWarpConfigured ? '已配置' : '预设'}</strong><p>将 Google 域名流量送到本机 SOCKS <code>127.0.0.1:1080</code>。需先在节点服务器启动 WARP SOCKS；mihomo 会用 RULE-SET 或 GEOSITE 匹配这些域名。</p></div>
            <Button variant="secondary" size="small" onClick={applyGoogleWarpPreset}>{googleWarpConfigured ? '重新应用' : '应用预设'}</Button>
          </div>
          <div className="binding-list">
            {machines.map((machine) => {
              const binding = draft.bindings.find((item) => item.machine_id === machine.id)
              return (
                <div className="binding-row" key={machine.id}>
                  <label className="binding-check">
                    <input type="checkbox" checked={Boolean(binding)} onChange={(event) => updateBinding(machine.id, event.target.checked)} />
                    <span><strong>{machine.name}</strong><small>SID {machine.id} · {machine.is_active ? '服务器启用' : '服务器停用'}</small></span>
                  </label>
                  <Select aria-label={`${machine.name} 绑定状态`} disabled={!binding} value={binding?.state || 'active'} onChange={(event) => updateBindingState(machine.id, event.target.value as MachineBindingState)}>
                    <option value="active">Active</option><option value="draining">Draining</option><option value="disabled">Disabled</option>
                  </Select>
                </div>
              )
            })}
            {!machines.length ? <EmptyState title="尚无 xbnode 服务器" description="可先保存为独立节点，再到服务器管理创建机器记录。" /> : null}
          </div>
          </>}
        </div>

        <div className="form-section">
          <h3 className="section-heading">协议配置</h3>
          {['vless', 'vmess', 'trojan'].includes(draft.type) ? <>
            <div className="form-grid">
              <Field label="安全性" required><Select value={String(protocolValue('tls', draft.type === 'trojan' ? 1 : 0))} onChange={(event) => updateProtocolValue('tls', Number(event.target.value))}>{draft.type !== 'trojan' ? <option value="0">无</option> : null}<option value="1">TLS</option>{draft.type !== 'vmess' ? <option value="2">REALITY</option> : null}</Select></Field>
              <Field label="传输协议" required><Select value={String(protocolValue('network', 'tcp'))} onChange={(event) => updateProtocolValue('network', event.target.value)}>{!transportOptions.includes(String(protocolValue('network', 'tcp'))) ? <option value={String(protocolValue('network'))}>{String(protocolValue('network')).toUpperCase()}</option> : null}{transportOptions.map((network) => <option value={network} key={network}>{network.toUpperCase()}</option>)}</Select></Field>
              <NetworkSettingsEditor
                key={String(protocolValue('network', 'tcp'))}
                network={String(protocolValue('network', 'tcp'))}
                value={protocolValue('network_settings', {})}
                onChange={(value) => updateProtocolValue('network_settings', value)}
                onApplied={(label) => notify(`已应用旧版 ${label} 传输模板`)}
              />
            </div>

            {Number(protocolValue('tls', 0)) === 1 ? <div className="form-grid">
              <Field label="服务器名称指示 (SNI)"><Input value={String(protocolValue('tls_settings.server_name'))} onChange={(event) => updateProtocolPath('tls_settings.server_name', event.target.value)} placeholder="不使用请留空" /></Field>
              <div style={{ display: 'flex', alignItems: 'end', paddingBottom: 8 }}><Switch checked={Boolean(protocolValue('tls_settings.allow_insecure', false))} onChange={(value) => updateProtocolPath('tls_settings.allow_insecure', value)} label="允许不安全连接" /></div>
            </div> : null}

            {Number(protocolValue('tls', 0)) === 2 ? <>
              <div className="callout callout-info"><KeyRound size={17} /><div><strong>{draft.type.toUpperCase()} REALITY</strong><p>先在上方选择传输协议并点击对应预设，再填写握手站点与密钥。Short ID 只用于 REALITY 握手，不用于出口路由。</p></div></div>
              {draft.type === 'vless' ? <Field label="流控"><Select value={String(protocolValue('flow'))} onChange={(event) => updateProtocolValue('flow', event.target.value)}><option value="xtls-rprx-vision">XTLS Vision</option><option value="">无</option></Select></Field> : null}
              <div className="form-grid">
              <Field label="伪装站点 (SNI / dest)" required><Input value={String(realitySettings.server_name || '')} onChange={(event) => updateRealityValue('server_name', event.target.value)} placeholder="cas-bridge.xethub.hf.co" /></Field>
              <Field label="伪装端口" required><Input type="number" min="1" max="65535" value={String(realitySettings.server_port || 443)} onChange={(event) => updateRealityValue('server_port', Number(event.target.value))} /></Field>
              <Field label="Private Key"><Input className="mono" type="password" value={String(realitySettings.private_key || '')} onChange={(event) => updateRealityValue('private_key', event.target.value)} /></Field>
              <Field label="Public Key"><Input className="mono" value={String(realitySettings.public_key || '')} onChange={(event) => updateRealityValue('public_key', event.target.value)} /></Field>
              <Field label="Short ID" hint="握手选择字段，不用于出口路由。"><Input className="mono" value={String(realitySettings.short_id || '')} onChange={(event) => updateRealityValue('short_id', event.target.value.toLowerCase())} maxLength={16} /></Field>
              <div style={{ display: 'flex', alignItems: 'end', paddingBottom: 1 }}><Button variant="secondary" loading={generatingReality} onClick={() => void generateReality()}><Sparkles size={14} />生成密钥与 Short ID</Button></div>
              </div>
            </> : null}

            {draft.type === 'vless' && Number(protocolValue('tls', 0)) !== 2 ? <Field label="流控"><Select value={String(protocolValue('flow'))} onChange={(event) => updateProtocolValue('flow', event.target.value)}><option value="">无</option><option value="xtls-rprx-vision">XTLS Vision</option></Select></Field> : null}
            {draft.type === 'vless' ? <div className="form-grid">
              <div style={{ display: 'flex', alignItems: 'end', paddingBottom: 8 }}><Switch checked={Boolean(protocolValue('encryption.enabled', false))} onChange={(value) => updateProtocolPath('encryption.enabled', value)} label="VLESS Encryption" /></div>
              {protocolValue('encryption.enabled', false) ? <><Field label="客户端 encryption" hint="mihomo vless 客户端未使用该字段，留空即可"><Input className="mono" value={String(protocolValue('encryption.encryption'))} onChange={(event) => updateProtocolPath('encryption.encryption', event.target.value)} placeholder="可留空" /></Field><Field label="服务端 decryption" hint="写入 mihomo vless listener 的 decryption"><Input className="mono" value={String(protocolValue('encryption.decryption'))} onChange={(event) => updateProtocolPath('encryption.decryption', event.target.value)} placeholder="服务端下发的 decryption 值" /></Field></> : null}
            </div> : null}

            <div className="form-grid">
              <div style={{ display: 'flex', alignItems: 'end', paddingBottom: 8 }}><Switch checked={Boolean(protocolValue('utls.enabled', false))} onChange={(value) => updateProtocolPath('utls.enabled', value)} label="uTLS 客户端指纹" /></div>
              {protocolValue('utls.enabled', false) ? <Field label="uTLS 指纹"><Select value={String(protocolValue('utls.fingerprint', 'chrome'))} onChange={(event) => updateProtocolPath('utls.fingerprint', event.target.value)}>{['chrome', 'firefox', 'safari', 'edge', 'ios', 'android', 'random', 'randomized'].map((fingerprint) => <option value={fingerprint} key={fingerprint}>{fingerprint}</option>)}</Select></Field> : null}
            </div>
          </> : null}

          {draft.type === 'shadowsocks' ? <div className="form-grid">
            <Field label="加密算法" required><Input list="shadowsocks-ciphers" value={String(protocolValue('cipher', 'aes-128-gcm'))} onChange={(event) => updateProtocolValue('cipher', event.target.value)} /><datalist id="shadowsocks-ciphers">{shadowsocksCiphers.map((cipher) => <option value={cipher} key={cipher} />)}</datalist></Field>
            <Field label="共享密码 (PSK)" hint="mihomo 的 Shadowsocks 仅支持单一密码；留空自动由节点创建时间派生"><Input className="mono" value={String(protocolValue('password'))} onChange={(event) => updateProtocolValue('password', event.target.value)} placeholder="留空自动生成" /></Field>
            <Field label="插件" hint="仅列出 mihomo shadowsocks listener 原生支持的混淆"><Select value={String(protocolValue('plugin'))} onChange={(event) => updateProtocolValue('plugin', event.target.value)}><option value="">无</option><option value="obfs-local">simple-obfs</option><option value="shadow-tls">shadow-tls</option><option value="restls">restls</option><option value="jls">jls</option><option value="kcptun">kcptun</option></Select></Field>
            <Field label="插件选项" hint="key=value;key2=value2"><Input value={String(protocolValue('plugin_opts'))} onChange={(event) => updateProtocolValue('plugin_opts', event.target.value)} placeholder="例如 mode=websocket;host=example.com" /></Field>
            <Field label="混淆"><Select value={String(protocolValue('obfs'))} onChange={(event) => updateProtocolValue('obfs', event.target.value)}><option value="">无</option><option value="http">HTTP</option></Select></Field>
            {protocolValue('obfs') === 'http' ? <><Field label="混淆 Host"><Input value={String(protocolValue('obfs_settings.host'))} onChange={(event) => updateProtocolPath('obfs_settings.host', event.target.value)} /></Field><Field label="混淆 Path"><Input value={String(protocolValue('obfs_settings.path'))} onChange={(event) => updateProtocolPath('obfs_settings.path', event.target.value)} /></Field></> : null}
          </div> : null}

          {draft.type === 'hysteria2' ? <>
            <div className="form-grid">
              <Field label="服务器名称指示 (SNI)"><Input value={String(protocolValue('tls.server_name'))} onChange={(event) => updateProtocolPath('tls.server_name', event.target.value)} /></Field>
              <Field label="上行带宽 (Mbps)" hint="留空使用 BBR"><Input type="number" min="0" value={String(protocolValue('up', ''))} onChange={(event) => updateProtocolValue('up', optionalNumber(event.target.value))} placeholder="留空使用 BBR" /></Field>
              <Field label="下行带宽 (Mbps)" hint="留空使用 BBR"><Input type="number" min="0" value={String(protocolValue('down', ''))} onChange={(event) => updateProtocolValue('down', optionalNumber(event.target.value))} placeholder="留空使用 BBR" /></Field>
              <Field label="端口跳跃间隔" hint="设置端口范围后生效"><Input type="number" min="0" value={String(protocolValue('hop_interval', ''))} onChange={(event) => updateProtocolValue('hop_interval', optionalNumber(event.target.value))} /></Field>
              <Field label="Masquerade 伪装站点" hint="file:///path 或 https://host:port"><Input className="mono" value={String(protocolValue('masquerade'))} onChange={(event) => updateProtocolValue('masquerade', event.target.value)} /></Field>
              <Field label="BBR Profile"><Select value={String(protocolValue('bbr_profile'))} onChange={(event) => updateProtocolValue('bbr_profile', event.target.value)}><option value="">默认</option><option value="standard">standard</option><option value="conservative">conservative</option><option value="aggressive">aggressive</option></Select></Field>
              <div style={{ display: 'flex', gap: 20, alignItems: 'end', paddingBottom: 8 }}><Switch checked={Boolean(protocolValue('tls.allow_insecure', false))} onChange={(value) => updateProtocolPath('tls.allow_insecure', value)} label="允许不安全连接" /><Switch checked={Boolean(protocolValue('obfs.open', false))} onChange={(value) => updateProtocolPath('obfs.open', value)} label="启用混淆" /></div>
              {protocolValue('obfs.open', false) ? <><Field label="混淆实现"><Select value={String(protocolValue('obfs.type', 'salamander'))} onChange={(event) => updateProtocolPath('obfs.type', event.target.value)}><option value="salamander">Salamander</option></Select></Field><Field label="混淆密码"><Input type="password" value={String(protocolValue('obfs.password'))} onChange={(event) => updateProtocolPath('obfs.password', event.target.value)} /></Field></> : null}
            </div>
          </> : null}

          {draft.type === 'tuic' ? <div className="form-grid">
            <Field label="协议版本"><Select value={String(protocolValue('version', 5))} onChange={(event) => updateProtocolValue('version', Number(event.target.value))}><option value="5">TUIC v5</option><option value="4">TUIC v4</option></Select></Field>
            <Field label="拥塞控制"><Select value={String(protocolValue('congestion_control', 'cubic'))} onChange={(event) => updateProtocolValue('congestion_control', event.target.value)}><option value="cubic">cubic</option><option value="bbr">bbr</option><option value="new_reno">new_reno</option></Select></Field>
            <Field label="UDP 中继模式"><Select value={String(protocolValue('udp_relay_mode', 'native'))} onChange={(event) => updateProtocolValue('udp_relay_mode', event.target.value)}><option value="native">native</option><option value="quic">quic</option></Select></Field>
            <Field label="ALPN" hint="多个值用逗号分隔"><Input value={(protocolValue('alpn', ['h3']) as string[]).join(', ')} onChange={(event) => updateProtocolValue('alpn', event.target.value.split(',').map((value) => value.trim()).filter(Boolean))} /></Field>
            <Field label="服务器名称指示 (SNI)"><Input value={String(protocolValue('tls.server_name'))} onChange={(event) => updateProtocolPath('tls.server_name', event.target.value)} /></Field>
            <div style={{ display: 'flex', alignItems: 'end', paddingBottom: 8 }}><Switch checked={Boolean(protocolValue('tls.allow_insecure', false))} onChange={(value) => updateProtocolPath('tls.allow_insecure', value)} label="允许不安全连接" /></div>
          </div> : null}

          {draft.type === 'anytls' ? <div className="form-grid">
            <Field label="服务器名称指示 (SNI)"><Input value={String(protocolValue('tls.server_name'))} onChange={(event) => updateProtocolPath('tls.server_name', event.target.value)} /></Field>
            <div style={{ display: 'flex', alignItems: 'end', paddingBottom: 8 }}><Switch checked={Boolean(protocolValue('tls.allow_insecure', false))} onChange={(value) => updateProtocolPath('tls.allow_insecure', value)} label="允许不安全连接" /></div>
            <Field className="span-2" label="填充方案" hint="每行一条规则；留空可使用核心默认值。"><Textarea value={(protocolValue('padding_scheme', defaultPaddingScheme) as string[]).join('\n')} onChange={(event) => updateProtocolValue('padding_scheme', event.target.value.split('\n').map((value) => value.trim()).filter(Boolean))} /></Field>
          </div> : null}

          {['socks', 'mixed', 'http'].includes(draft.type) ? <div className="form-grid">
            <Field label="TLS"><Select value={String(protocolValue('tls', draft.type === 'http' ? 1 : 0))} onChange={(event) => updateProtocolValue('tls', Number(event.target.value))}><option value="0">不启用</option><option value="1">启用</option></Select></Field>
            {['socks', 'mixed'].includes(draft.type) ? <div style={{ display: 'flex', alignItems: 'end', paddingBottom: 8 }}><Switch checked={Boolean(protocolValue('udp', true))} onChange={(value) => updateProtocolValue('udp', value)} label="监听 UDP" /></div> : null}
            {Number(protocolValue('tls', 0)) === 1 ? <><Field label="服务器名称指示 (SNI)"><Input value={String(protocolValue('tls_settings.server_name'))} onChange={(event) => updateProtocolPath('tls_settings.server_name', event.target.value)} /></Field><div style={{ display: 'flex', alignItems: 'end', paddingBottom: 8 }}><Switch checked={Boolean(protocolValue('tls_settings.allow_insecure', false))} onChange={(value) => updateProtocolPath('tls_settings.allow_insecure', value)} label="允许不安全连接" /></div></> : null}
          </div> : null}

          {draft.type === 'mieru' ? <div className="form-grid">
            <Field label="传输协议"><Select value={String(protocolValue('transport', 'TCP'))} onChange={(event) => updateProtocolValue('transport', event.target.value)}><option value="TCP">TCP</option><option value="UDP">UDP</option></Select></Field>
            <Field label="流量模式 (Base64)"><Input className="mono" value={String(protocolValue('traffic_pattern'))} onChange={(event) => updateProtocolValue('traffic_pattern', event.target.value)} /></Field>
          </div> : null}

          <div className="advanced-options">
            <p className="field-hint">{inboundMeta?.description}</p>
            <MihomoInboundEditor type={draft.type} settings={parsedProtocolSettings} onChange={updateProtocolPath} />
          </div>

          {['vless', 'vmess', 'trojan', 'mieru'].includes(draft.type) ? <div className="advanced-options">
            <div className="form-grid">
              <div style={{ display: 'flex', alignItems: 'end', paddingBottom: 8 }}><Switch checked={Boolean(protocolValue('multiplex.enabled', false))} onChange={(value) => updateProtocolPath('multiplex.enabled', value)} label="多路复用 (Multiplex)" /></div>
              {protocolValue('multiplex.enabled', false) ? <Field label="复用协议"><Select value={String(protocolValue('multiplex.protocol', 'smux'))} onChange={(event) => updateProtocolPath('multiplex.protocol', event.target.value)}><option value="smux">smux</option><option value="yamux">yamux</option><option value="h2mux">h2mux</option></Select></Field> : null}
              {protocolValue('multiplex.enabled', false) ? <><Field label="最大连接数"><Input type="number" min="1" value={String(protocolValue('multiplex.max_connections', 4))} onChange={(event) => updateProtocolPath('multiplex.max_connections', Number(event.target.value))} /></Field><div style={{ display: 'flex', gap: 20, alignItems: 'end', paddingBottom: 8 }}><Switch checked={Boolean(protocolValue('multiplex.padding', false))} onChange={(value) => updateProtocolPath('multiplex.padding', value)} label="启用填充" /><Switch checked={Boolean(protocolValue('multiplex.brutal.enabled', false))} onChange={(value) => updateProtocolPath('multiplex.brutal.enabled', value)} label="TCP Brutal" /></div></> : null}
              {protocolValue('multiplex.enabled', false) && protocolValue('multiplex.brutal.enabled', false) ? <><Field label="Brutal 上行 (Mbps)"><Input type="number" min="1" value={String(protocolValue('multiplex.brutal.up_mbps', 100))} onChange={(event) => updateProtocolPath('multiplex.brutal.up_mbps', Number(event.target.value))} /></Field><Field label="Brutal 下行 (Mbps)"><Input type="number" min="1" value={String(protocolValue('multiplex.brutal.down_mbps', 100))} onChange={(event) => updateProtocolPath('multiplex.brutal.down_mbps', Number(event.target.value))} /></Field></> : null}
            </div>
          </div> : null}

          <details className="advanced-json">
            <summary>高级协议配置（JSON）</summary>
            <p>用于回填旧版 transport、ECH 或实验字段；上方选项修改时会同步更新这里。</p>
            <Textarea className="textarea-large" aria-label="protocol_settings JSON" value={draft.protocolSettings} onChange={(event) => setDraft({ ...draft, protocolSettings: event.target.value })} spellCheck={false} />
          </details>
        </div>

        <div className="form-section">
          <div className="routing-editor-heading">
            <div>
              <h3 className="section-heading">高级配置 · 自定义 Outbound / Route</h3>
              <p>还原原节点管理的 JSON 编辑器。配置直接保存到当前节点，可与“路由模板”中的可复用模板同时使用。</p>
            </div>
            <div className="connection-methods" aria-label="自定义路由配置数量">
              <Badge tone={outboundCount ? 'info' : 'neutral'}>Outbound {outboundCount}</Badge>
              <Badge tone={routeCount ? 'info' : 'neutral'}>Route {routeCount}</Badge>
            </div>
          </div>
          <div className="callout">
            <RouteIcon size={17} />
            <div><strong>mihomo 原生 JSON</strong><p>Outbound 支持 Shadowsocks、SOCKS5 及 mihomo 可识别的其他协议；Route 的字段结构按 mihomo 原配置填写。保存前只校验 JSON 和数组结构，运行时由 xbnode 按 mihomo 校验。</p></div>
          </div>
          <div className="tabs" role="tablist" aria-label="自定义路由配置">
            <button type="button" role="tab" aria-selected={routingTab === 'outbounds'} className={`tab ${routingTab === 'outbounds' ? 'is-active' : ''}`} onClick={() => setRoutingTab('outbounds')}><Braces size={14} />自定义 Outbounds</button>
            <button type="button" role="tab" aria-selected={routingTab === 'routes'} className={`tab ${routingTab === 'routes' ? 'is-active' : ''}`} onClick={() => setRoutingTab('routes')}><RouteIcon size={14} />自定义 Routes</button>
          </div>
          {routingTab === 'outbounds' ? (
            <Field label="自定义 Outbounds（JSON）" error={outboundError || undefined} hint="必须是数组；tag 供自定义 Route 或派生 UUID 路由引用。">
              <Textarea className="textarea-large" aria-label="custom_outbounds JSON" value={draft.customOutbounds} onChange={(event) => setDraft({ ...draft, customOutbounds: event.target.value })} placeholder={'[{\n  "tag": "proxy",\n  "protocol": "socks",\n  "settings": { "server": "127.0.0.1", "server_port": 1080 }\n}]'} spellCheck={false} />
            </Field>
          ) : (
            <Field label="自定义 Routes（JSON）" error={routeError || undefined} hint="必须是数组；规则中的 outbound / outboundTag 应对应已存在的 outbound tag。">
              <Textarea className="textarea-large" aria-label="custom_routes JSON" value={draft.customRoutes} onChange={(event) => setDraft({ ...draft, customRoutes: event.target.value })} placeholder={'[{\n  "outboundTag": "proxy",\n  "domain": ["domain:example.com"]\n}]'} spellCheck={false} />
            </Field>
          )}
        </div>
        <button id="node-form-submit" type="submit" hidden />
      </form>
    </Modal>
  )
}

export function NodesPage() {
  const nodeRequest = useSWR('nodes', () => api.adminGet<NodeRecord[]>('server/manage/getNodes'))
  const machineRequest = useSWR('machines', () => api.adminGet<Machine[]>('server/machine/fetch'))
  const groupRequest = useSWR('groups', () => api.adminGet<Group[]>('server/group/fetch'))
  const routingRequest = useSWR('routing-templates', () => api.adminGet<RoutingData>('server/routing-template/fetch'))
  const [search, setSearch] = useState('')
  const [protocol, setProtocol] = useState('all')
  const [status, setStatus] = useState<'enabled' | 'disabled' | 'all'>('enabled')
  const [editor, setEditor] = useState<NodeRecord | 'new' | null>(null)
  const [copySource, setCopySource] = useState<NodeRecord | null>(null)
  const [configureNode, setConfigureNode] = useState<NodeRecord | null>(null)
  const [sorting, setSorting] = useState(false)
  const [savingSort, setSavingSort] = useState(false)
  const [sortedNodes, setSortedNodes] = useState<NodeRecord[]>([])
  const { notify } = useToast()
  const nodes = nodeRequest.data || []

  const filtered = useMemo(() => nodes.filter((node) => {
    const term = search.trim().toLowerCase()
    const matchesSearch = !term || [node.name, node.host, node.type, node.id, node.code].some((value) => String(value || '').toLowerCase().includes(term))
    const matchesStatus = status === 'all' || (status === 'enabled' ? Boolean(node.enabled) : !Boolean(node.enabled))
    return matchesSearch && matchesStatus && (protocol === 'all' || node.type === protocol)
  }), [nodes, protocol, search, status])
  const displayedNodes = sorting ? sortedNodes : filtered

  function startSorting() {
    setSearch('')
    setProtocol('all')
    setStatus('all')
    setSortedNodes([...nodes])
    setSorting(true)
  }

  function dropSortedNode(event: DragEvent<HTMLTableRowElement>, targetIndex: number) {
    event.preventDefault()
    const sourceIndex = Number(event.dataTransfer.getData('text/plain'))
    if (!Number.isInteger(sourceIndex) || sourceIndex === targetIndex) return
    setSortedNodes((current) => {
      const next = [...current]
      const [moved] = next.splice(sourceIndex, 1)
      if (!moved) return current
      next.splice(targetIndex, 0, moved)
      return next
    })
  }

  async function saveSorting() {
    setSavingSort(true)
    try {
      await api.adminPost('server/manage/sort', sortedNodes.map((node, index) => ({ id: node.id, order: index + 1 })))
      setSorting(false)
      await nodeRequest.mutate()
      notify('节点排序已保存')
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '保存排序失败', 'danger')
    } finally {
      setSavingSort(false)
    }
  }

  async function quickUpdate(node: NodeRecord, payload: Record<string, unknown>) {
    try {
      await api.adminPost('server/manage/update', { id: node.id, ...payload })
      await nodeRequest.mutate()
      notify('节点状态已更新')
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '更新失败', 'danger')
    }
  }

  async function remove(node: NodeRecord) {
    if (!window.confirm(`确认删除节点「${node.name}」？此操作不可恢复。`)) return
    try {
      await api.adminPost('server/manage/drop', { id: node.id })
      await nodeRequest.mutate()
      notify('节点已删除')
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '删除失败', 'danger')
    }
  }

  return (
    <>
      <PageHeader
        title="节点管理"
        description="节点是用户看到的连接入口。每个节点既可由原 node_id 独立接入，也可绑定一台或多台 xbnode 服务器。"
        actions={<><Button variant="secondary" onClick={() => void nodeRequest.mutate()}><RefreshCw size={15} />刷新</Button><Button onClick={() => setEditor('new')}><Plus size={15} />新建节点</Button></>}
      />
      <div className="callout callout-info" style={{ marginBottom: 16 }}>
        <Waypoints size={18} />
        <div><strong>多出口在主节点上配置</strong><p>无需启用旧的多端口节点。主节点保持一个监听端口；点击节点行的“配置出口”，用独立 UUID 将不同订阅入口映射到不同 Outbound。</p></div>
      </div>
      <Card>
        <div className="card-toolbar">
          {sorting ? <>
            <div className="sort-mode-hint"><GripVertical size={16} /><span><strong>拖动节点调整顺序</strong><small>排序包含启用与停用节点，保存后直接影响订阅中的节点顺序。</small></span></div>
            <Button variant="secondary" size="small" onClick={() => setSorting(false)}><X size={14} />取消</Button>
            <Button size="small" loading={savingSort} onClick={() => void saveSorting()}><Save size={14} />保存排序</Button>
          </> : <>
            <div className="search-field" style={{ position: 'relative' }}><Search size={15} style={{ position: 'absolute', left: 11, top: 11, color: 'var(--muted-foreground)' }} /><Input style={{ paddingLeft: 34 }} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="搜索节点名称、地址、ID…" /></div>
            <Select style={{ width: 170 }} value={protocol} onChange={(event) => setProtocol(event.target.value)}><option value="all">全部协议</option>{protocols.map((item) => <option value={item} key={item}>{protocolLabel(item)}</option>)}</Select>
            <Select style={{ width: 140 }} value={status} onChange={(event) => setStatus(event.target.value as typeof status)}><option value="enabled">仅看启用</option><option value="all">全部节点</option><option value="disabled">仅看停用</option></Select>
            <Badge tone="info">{filtered.length} 个节点</Badge>
            <Button variant="secondary" size="small" disabled={!nodes.length} onClick={startSorting}><ListOrdered size={14} />编辑排序</Button>
          </>}
        </div>
        {nodeRequest.error ? <ErrorState error={nodeRequest.error} retry={() => void nodeRequest.mutate()} /> : !nodeRequest.data ? <LoadingTable rows={8} columns={10} /> : displayedNodes.length ? (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr>{sorting ? <th aria-label="拖动排序" /> : null}<th>ID</th><th>节点</th><th>入口</th><th>多出口</th><th>后端服务器</th><th>在线</th><th>状态</th><th style={{ textAlign: 'right' }}>操作</th></tr></thead>
              <tbody>{displayedNodes.map((node, index) => (
                <tr
                  key={node.id}
                  draggable={sorting}
                  onDragStart={sorting ? (event) => {
                    event.dataTransfer.effectAllowed = 'move'
                    event.dataTransfer.setData('text/plain', String(index))
                    event.currentTarget.classList.add('is-dragging')
                  } : undefined}
                  onDragEnd={sorting ? (event) => event.currentTarget.classList.remove('is-dragging') : undefined}
                  onDragOver={sorting ? (event) => { event.preventDefault(); event.dataTransfer.dropEffect = 'move' } : undefined}
                  onDrop={sorting ? (event) => dropSortedNode(event, index) : undefined}
                >
                  {sorting ? <td className="drag-handle" title="拖动排序"><GripVertical size={17} /><span className="mono">{index + 1}</span></td> : null}
                  <td className="mono">#{node.id}</td>
                  <td><div className="node-cell"><span className={`status-dot ${node.is_online ? 'online' : node.enabled ? 'warning' : ''}`} /><div className="table-primary"><strong>{node.name}</strong><span>{node.type.toUpperCase()} · {node.rate}x · {formatBytes((node.u || 0) + (node.d || 0))}</span></div></div></td>
                  <td><div className="table-primary"><strong>{node.host}:{node.port}</strong><span>监听 {node.server_port}</span></div></td>
                  <td><div className="table-primary"><div className="connection-methods"><Badge tone={node.outbound_count ? 'info' : 'neutral'}>{node.outbound_count || 0} Outbound</Badge><Badge tone={node.route_profile_count ? 'success' : 'neutral'}>{node.route_profile_count || 0} 出口档案</Badge></div><span title={node.route_profile_names?.join('、')}>{node.route_profile_names?.join('、') || '尚未配置'}</span></div></td>
                  <td>{node.parent_id ? <span className="muted">复用 {node.parent?.name || `#${node.parent_id}`}</span> : node.machine_ids?.length ? <div className="connection-methods">{node.machine_bindings.map((binding) => <Badge key={binding.machine_id} tone={binding.state === 'active' ? 'success' : binding.state === 'draining' ? 'warning' : 'neutral'}>SID {binding.machine_id} · {binding.state}</Badge>)}</div> : <span className="muted">独立部署</span>}</td>
                  <td>{node.online ?? 0} / {node.online_conn ?? 0}</td>
                  <td><div className="connection-methods"><button type="button" onClick={() => void quickUpdate(node, { enabled: !Boolean(node.enabled) })} className={`badge ${node.enabled ? 'badge-success' : 'badge-neutral'}`}>{node.enabled ? '已启用' : '已停用'}</button><button type="button" onClick={() => void quickUpdate(node, { show: node.show ? 0 : 1 })} className={`badge ${node.show ? 'badge-info' : 'badge-neutral'}`}>{node.show ? '可见' : '隐藏'}</button></div></td>
                  <td><div className="table-actions"><Button variant="secondary" size="small" disabled={sorting || !routingRequest.data} onClick={() => setConfigureNode(node)} title={routingRequest.error ? '出口模板加载失败，请刷新页面' : undefined}><Waypoints size={14} />配置出口</Button><Button variant="ghost" size="icon" disabled={sorting} onClick={() => setCopySource(node)} aria-label={`复制 ${node.name} 为子节点`} title="复制为子节点"><Copy size={15} /></Button><Button variant="ghost" size="icon" disabled={sorting} onClick={() => setEditor(node)} aria-label={`编辑 ${node.name}`}><Pencil size={15} /></Button><Button variant="ghost" size="icon" disabled={sorting} onClick={() => void remove(node)} aria-label={`删除 ${node.name}`}><Trash2 size={15} /></Button></div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : <EmptyState icon={<Network size={19} />} title="没有匹配的节点" description="调整筛选条件，或创建第一个节点。" action={<Button onClick={() => setEditor('new')}><Plus size={15} />新建节点</Button>} />}
      </Card>
      {editor ? <NodeModal key={editor === 'new' ? 'new' : editor.id} node={editor === 'new' ? undefined : editor} nodes={nodes} machines={machineRequest.data || []} groups={groupRequest.data || []} open onClose={() => setEditor(null)} onSaved={() => void nodeRequest.mutate()} /> : null}
      {copySource ? <NodeModal key={`copy-${copySource.id}`} copyFrom={copySource} nodes={nodes} machines={machineRequest.data || []} groups={groupRequest.data || []} open onClose={() => setCopySource(null)} onSaved={() => void nodeRequest.mutate()} /> : null}
      {configureNode && routingRequest.data ? <NodeRoutingConfigurationModal node={configureNode} routing={routingRequest.data} onClose={() => setConfigureNode(null)} onSaved={() => void nodeRequest.mutate()} /> : null}
    </>
  )
}
