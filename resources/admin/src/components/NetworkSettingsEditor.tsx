import { useState } from 'react'
import { Braces, Check } from 'lucide-react'
import { Badge, Button, Textarea } from './ui'

interface NetworkPreset {
  id: string
  label: string
  content: Record<string, unknown>
}

const networkPresets: Record<string, NetworkPreset[]> = {
  tcp: [
    {
      id: 'tcp',
      label: 'TCP',
      content: { acceptProxyProtocol: false, header: { type: 'none' } },
    },
    {
      id: 'tcp-http',
      label: 'TCP + HTTP',
      content: {
        acceptProxyProtocol: false,
        header: {
          type: 'http',
          request: {
            version: '1.1',
            method: 'GET',
            path: ['/'],
            headers: { Host: ['www.example.com'] },
          },
          response: { version: '1.1', status: '200', reason: 'OK' },
        },
      },
    },
  ],
  grpc: [{ id: 'grpc', label: 'gRPC', content: { serviceName: 'GunService' } }],
  ws: [{ id: 'ws', label: 'WebSocket', content: { path: '/', headers: { Host: 'v2ray.com' } } }],
  h2: [{ id: 'h2', label: 'HTTP/2', content: { path: '/', host: ['www.google.com'] } }],
  httpupgrade: [{
    id: 'httpupgrade',
    label: 'HttpUpgrade',
    content: { acceptProxyProtocol: false, path: '/', host: 'example.com', headers: { key: 'value' } },
  }],
  xhttp: [{
    id: 'xhttp',
    label: 'XHTTP',
    content: {
      host: 'example.com',
      path: '/yourpath',
      mode: 'auto',
      extra: {
        headers: {},
        xPaddingBytes: '100-1000',
        noGRPCHeader: false,
        noSSEHeader: false,
        scMaxEachPostBytes: 1000000,
        scMinPostsIntervalMs: 30,
        scMaxBufferedPosts: 30,
        xmux: {
          maxConcurrency: '16-32',
          maxConnections: 0,
          cMaxReuseTimes: '64-128',
          cMaxLifetimeMs: 0,
          hMaxRequestTimes: '800-900',
          hKeepAlivePeriod: 0,
        },
        downloadSettings: {
          address: '',
          port: 443,
          network: 'xhttp',
          security: 'tls',
          tlsSettings: {},
          xhttpSettings: { path: '/yourpath' },
          sockopt: {},
        },
      },
    },
  }],
}

function asNetworkSettings(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {}
}

export function NetworkSettingsEditor({ network, value, onChange, onApplied }: {
  network: string
  value: unknown
  onChange: (value: Record<string, unknown>) => void
  onApplied?: (label: string) => void
}) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState('')
  const [error, setError] = useState('')
  const presets = networkPresets[network] || []
  const settings = asNetworkSettings(value)

  function applyPreset(preset: NetworkPreset) {
    const next = JSON.parse(JSON.stringify(preset.content)) as Record<string, unknown>
    onChange(next)
    setDraft(JSON.stringify(next, null, 2))
    setError('')
    onApplied?.(preset.label)
  }

  function openEditor() {
    setDraft(JSON.stringify(settings, null, 2))
    setError('')
    setEditing(true)
  }

  function saveJson() {
    try {
      const parsed: unknown = draft.trim() ? JSON.parse(draft) : {}
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
        setError('传输配置必须是 JSON 对象。')
        return
      }
      onChange(parsed as Record<string, unknown>)
      setEditing(false)
      setError('')
    } catch {
      setError('JSON 格式无效。')
    }
  }

  return (
    <section className="network-settings-editor span-2" aria-labelledby="network-settings-title">
      <div className="network-settings-head">
        <div>
          <span className="field-label" id="network-settings-title">传输配置（network_settings）</span>
          <p>选择与旧版一致的预设即可自动补全，也可以继续编辑完整 JSON。</p>
        </div>
        <Badge tone={Object.keys(settings).length ? 'info' : 'neutral'}>{network.toUpperCase()}</Badge>
      </div>

      <div className="network-preset-actions" aria-label={`${network} 传输配置模板`}>
        {presets.map((preset) => (
          <Button variant="secondary" size="small" key={preset.id} onClick={() => applyPreset(preset)}>
            <Check size={13} />使用 {preset.label} 模板
          </Button>
        ))}
        <Button variant="ghost" size="small" onClick={openEditor}><Braces size={13} />编辑 JSON</Button>
      </div>

      {presets.length === 0 ? <p className="network-settings-empty">旧版没有为 {network.toUpperCase()} 提供预设，可直接编辑 JSON。</p> : null}

      {editing ? (
        <div className="network-json-editor">
          <Textarea
            className="textarea-large"
            aria-label="network_settings JSON"
            value={draft}
            onChange={(event) => {
              setDraft(event.target.value)
              if (error) setError('')
            }}
            spellCheck={false}
          />
          {error ? <p className="field-error" role="alert">{error}</p> : null}
          <div className="network-json-actions">
            <Button variant="ghost" size="small" onClick={() => setEditing(false)}>取消</Button>
            <Button size="small" onClick={saveJson}>应用 JSON</Button>
          </div>
        </div>
      ) : (
        <pre className="network-settings-preview">{JSON.stringify(settings, null, 2)}</pre>
      )}
    </section>
  )
}
