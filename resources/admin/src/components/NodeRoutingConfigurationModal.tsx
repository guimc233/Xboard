import { useEffect, useState } from 'react'
import useSWR from 'swr'
import { Plus, ShieldQuestion, Trash2, Waypoints } from 'lucide-react'
import { api } from '../lib/api'
import type { NodeRecord, OutboundTemplate, RouteProfile, RouteTemplate, RoutingData } from '../types'
import { Button, EmptyState, ErrorState, Field, Input, LoadingTable, Modal, Select, Switch, useToast } from './ui'

interface ServerConfiguration {
  outbounds: Array<{ template_id: number; tag: string; enabled: boolean }>
  routes: Array<{ template_id: number; enabled: boolean }>
  profiles: RouteProfile[]
  config_version: number
}

export function NodeRoutingConfigurationModal({ node, routing, onClose, onSaved }: {
  node: NodeRecord
  routing: RoutingData
  onClose: () => void
  onSaved?: () => void
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

  const identityRoutingSupported = node.type === 'trojan'
    || node.type === 'shadowsocks'
    || (node.type === 'vless' && Number((node.protocol_settings as { tls?: unknown } | undefined)?.tls) === 2)
  const credentialName = node.type === 'trojan' ? 'Trojan 密码' : node.type === 'shadowsocks' ? 'Shadowsocks 密码' : 'VLESS UUID'
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
      notify(`节点 ${node.name} 的多出口配置已下发`)
      await request.mutate()
      onSaved?.()
      onClose()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存节点路由配置失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} width="large" title={`配置多出口 · ${node.name}`} description={`节点 #${node.id} 共用一个入站端口 ${node.server_port}；每个出口档案使用独立${credentialName}选择出口。当前配置版本 ${request.data?.config_version ?? '--'}。`} footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} onClick={() => void save()}>保存并下发</Button></>}>
      {request.error ? <ErrorState error={request.error} retry={() => void request.mutate()} /> : !request.data ? <LoadingTable rows={6} columns={2} /> : <div className="form-section">
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <div className="callout callout-info"><Waypoints size={17} /><div><strong>一个入口，多个出口</strong><p>用户仍连接 {node.host}:{node.port}。订阅为每个档案生成不同的{credentialName}；xbnode 鉴权后按认证身份映射到指定 outbound。mihomo 使用同一份面板配置。</p></div></div>

        <div className="form-section">
          <h3 className="section-heading">第一步：绑定 Outbound</h3>
          <div className="binding-list">
            {routing.outbounds.map((template) => {
              const item = outbounds.find((outbound) => outbound.template_id === template.id)
              return <div className="binding-row" key={template.id}><label className="binding-check"><input type="checkbox" checked={Boolean(item)} onChange={(event) => toggleOutbound(template, event.target.checked)} /><span><strong>{template.name}</strong><small>{template.protocol} · {template.settings.server}:{template.settings.server_port}</small></span></label><Input disabled={!item} value={item?.tag || outboundTag(template)} onChange={(event) => setOutbounds((items) => items.map((outbound) => outbound.template_id === template.id ? { ...outbound, tag: event.target.value } : outbound))} aria-label={`${template.name} outbound tag`} /></div>
            })}
            {!routing.outbounds.length ? <EmptyState title="没有 Outbound 模板" description="先到“出站与路由”创建 Shadowsocks 或 SOCKS5 模板。" /> : null}
          </div>
        </div>

        <div className="form-section">
          <h3 className="section-heading">第二步：身份路由档案</h3>
          <div className="callout"><ShieldQuestion size={17} /><div><strong>{node.type === 'trojan' ? '使用密码区分出口' : node.type === 'shadowsocks' ? '使用用户密钥区分出口' : 'Short ID 不负责区分出口'}</strong><p>{node.type === 'trojan' ? '每个档案为同一用户派生独立 Trojan 密码；端口、TLS 证书和域名保持不变。' : node.type === 'shadowsocks' ? '每个档案派生独立 Shadowsocks 用户密码；传统 AEAD 与 SS2022 AES 均由面板和双核心转换为正确格式。' : 'Short ID 只参与 Reality 握手。真正的出口路由键是每个档案派生的用户 UUID，这样双核心都能稳定识别。'}</p></div></div>
          {identityRoutingSupported ? <>
            {profiles.map((profile, index) => <div className="profile-row" key={profile.id || index}>
              <Field label="订阅中显示的出口名称"><Input value={profile.name} onChange={(event) => setProfiles((items) => items.map((item, itemIndex) => itemIndex === index ? { ...item, name: event.target.value } : item))} /></Field>
              <Field label="流量发往"><Select value={profile.outbound_template_id || ''} onChange={(event) => setProfiles((items) => items.map((item, itemIndex) => itemIndex === index ? { ...item, outbound_template_id: event.target.value ? Number(event.target.value) : null } : item))}><option value="">默认直连</option>{outbounds.map((item) => { const template = routing.outbounds.find((candidate) => candidate.id === item.template_id); return <option key={item.template_id} value={item.template_id}>{template?.name || item.tag}</option> })}</Select></Field>
              <Switch checked={profile.enabled} onChange={(enabled) => setProfiles((items) => items.map((item, itemIndex) => itemIndex === index ? { ...item, enabled } : item))} label="启用" />
              <Button variant="ghost" size="icon" onClick={() => setProfiles((items) => items.filter((_, itemIndex) => itemIndex !== index))} aria-label="删除档案"><Trash2 size={15} /></Button>
              {profile.uuid ? <div className="span-2 mono muted" style={{ gridColumn: '1 / -1', fontSize: 11 }}>{credentialName}: {profile.uuid}</div> : null}
            </div>)}
            <Button variant="secondary" size="small" onClick={() => setProfiles((items) => [...items, { name: `路由档案 ${items.length + 1}`, outbound_template_id: outbounds[0]?.template_id || null, enabled: true }])}><Plus size={13} />添加出口档案</Button>
          </> : <EmptyState title="此节点不支持身份路由" description="普通 Route / Outbound 仍可绑定；同端口多身份选出口目前支持 VLESS Reality、Trojan 与 Shadowsocks。" />}
        </div>

        <details className="advanced-details">
          <summary>可选：绑定按域名/IP 匹配的 Route 模板</summary>
          <div className="binding-list" style={{ marginTop: 12 }}>{routing.routes.map((template) => {
            const item = routes.find((route) => route.template_id === template.id)
            return <div className="binding-row" key={template.id}><label className="binding-check"><input type="checkbox" checked={Boolean(item)} onChange={(event) => toggleRoute(template, event.target.checked)} /><span><strong>{template.name}</strong><small>{template.rules.length} 条规则</small></span></label><Switch checked={item?.enabled ?? false} disabled={!item} onChange={(enabled) => setRoutes((items) => items.map((route) => route.template_id === template.id ? { ...route, enabled } : route))} label="启用" /></div>
          })}{!routing.routes.length ? <p className="muted">当前没有 Route 模板；按认证身份选择出口不需要额外 Route 模板。</p> : null}</div>
        </details>
      </div>}
    </Modal>
  )
}
