import { useState, type FormEvent } from 'react'
import useSWR from 'swr'
import { Bell, Globe2, MonitorSmartphone, RadioTower, Save, ShieldCheck, SlidersHorizontal } from 'lucide-react'
import { api } from '../lib/api'
import { Button, Card, ErrorState, Field, Input, LoadingTable, Select, Switch, Textarea, useToast } from './ui'

type ConfigSection = Record<string, any>
interface SystemConfigData {
  site: ConfigSection
  subscribe: ConfigSection
  subscribe_template: ConfigSection
  server: ConfigSection
  safe: ConfigSection
  email: ConfigSection
  telegram: ConfigSection
  frontend: ConfigSection
  app: ConfigSection
}

type SettingsTab = 'site' | 'subscribe' | 'templates' | 'server' | 'security' | 'notifications' | 'frontend' | 'client'

function SettingsForm({ section, initial, onSaved }: { section: SettingsTab; initial: ConfigSection; onSaved: () => void }) {
  const [draft, setDraft] = useState<ConfigSection>({ ...initial })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const { notify } = useToast()
  const set = (key: string, value: unknown) => setDraft((current) => ({ ...current, [key]: value }))

  async function save(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.adminPost('config/save', draft)
      notify('系统配置已保存')
      onSaved()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存配置失败')
    } finally {
      setSaving(false)
    }
  }

  return <form className="settings-form" onSubmit={save}>
    {error ? <div className="login-error" role="alert">{error}</div> : null}
    {section === 'site' ? <>
      <div className="form-grid">
        <Field label="站点名称"><Input value={draft.app_name || ''} onChange={(event) => set('app_name', event.target.value)} /></Field>
        <Field label="站点 URL" hint="必须包含 https://"><Input type="url" value={draft.app_url || ''} onChange={(event) => set('app_url', event.target.value)} /></Field>
        <Field label="订阅 URL" hint="留空使用站点 URL；多个地址使用英文逗号分隔。"><Input value={draft.subscribe_url || ''} onChange={(event) => set('subscribe_url', event.target.value)} /></Field>
        <Field label="Logo URL"><Input type="url" value={draft.logo || ''} onChange={(event) => set('logo', event.target.value)} /></Field>
        <Field label="站点说明" className="span-2"><Textarea value={draft.app_description || ''} onChange={(event) => set('app_description', event.target.value)} /></Field>
      </div>
      <div className="settings-switches"><Switch checked={Boolean(draft.force_https)} onChange={(value) => set('force_https', value ? 1 : 0)} label="强制 HTTPS" /><Switch checked={Boolean(draft.stop_register)} onChange={(value) => set('stop_register', value ? 1 : 0)} label="停止新用户注册" /></div>
    </> : null}

    {section === 'subscribe' ? <>
      <div className="form-grid">
        <Field label="订阅路径" hint={`当前格式：/${draft.subscribe_path || 's'}/xxxxxxxxxx`}><Input value={draft.subscribe_path || 's'} onChange={(event) => set('subscribe_path', event.target.value)} /></Field>
        <Field label="全局流量重置"><Select value={String(draft.reset_traffic_method ?? 0)} onChange={(event) => set('reset_traffic_method', Number(event.target.value))}><option value="0">每月 1 日</option><option value="1">按用户首次订阅日</option><option value="2">每年 1 月 1 日</option><option value="3">不重置</option><option value="4">每月到期日</option></Select></Field>
      </div>
      <div className="settings-switches"><Switch checked={Boolean(draft.show_info_to_server_enable)} onChange={(value) => set('show_info_to_server_enable', value)} label="在订阅中展示流量和到期信息" /><Switch checked={Boolean(draft.show_protocol_to_server_enable)} onChange={(value) => set('show_protocol_to_server_enable', value)} label="节点名称显示协议名" /><Switch checked={Boolean(draft.default_remind_expire)} onChange={(value) => set('default_remind_expire', value)} label="默认开启到期提醒" /><Switch checked={Boolean(draft.default_remind_traffic)} onChange={(value) => set('default_remind_traffic', value)} label="默认开启流量提醒" /></div>
    </> : null}

    {section === 'server' ? <>
      <div className="form-grid">
        <Field label="节点通讯密钥" hint="至少 16 位；修改后原 node_id 接入需同步更新。"><Input type="password" autoComplete="new-password" value={draft.server_token || ''} onChange={(event) => set('server_token', event.target.value)} /></Field>
        <Field label="设备限制模式"><Select value={String(draft.device_limit_mode ?? 0)} onChange={(event) => set('device_limit_mode', Number(event.target.value))}><option value="0">按 IP 计数</option><option value="1">按设备计数</option></Select></Field>
        <Field label="配置拉取间隔（秒）"><Input type="number" min="10" value={draft.server_pull_interval ?? 60} onChange={(event) => set('server_pull_interval', Number(event.target.value))} /></Field>
        <Field label="状态上报间隔（秒）"><Input type="number" min="10" value={draft.server_push_interval ?? 60} onChange={(event) => set('server_push_interval', Number(event.target.value))} /></Field>
        <Field label="WebSocket 地址" className="span-2"><Input type="url" value={draft.server_ws_url || ''} onChange={(event) => set('server_ws_url', event.target.value)} /></Field>
      </div>
      <div className="settings-switches"><Switch checked={Boolean(draft.server_ws_enable)} onChange={(value) => set('server_ws_enable', value)} label="启用节点 WebSocket 推送" /></div>
    </> : null}

    {section === 'security' ? <>
      <div className="form-grid">
        <Field label="后台安全路径" hint="修改后当前后台 URL 会变化，至少 8 位。"><Input value={draft.secure_path || ''} onChange={(event) => set('secure_path', event.target.value)} /></Field>
        <Field label="验证码类型"><Select value={draft.captcha_type || 'recaptcha'} onChange={(event) => set('captcha_type', event.target.value)}><option value="turnstile">Cloudflare Turnstile</option><option value="recaptcha">reCAPTCHA v2</option><option value="recaptcha-v3">reCAPTCHA v3</option></Select></Field>
        <Field label="Turnstile Site Key"><Input value={draft.turnstile_site_key || ''} onChange={(event) => set('turnstile_site_key', event.target.value)} /></Field>
        <Field label="Turnstile Secret Key"><Input type="password" value={draft.turnstile_secret_key || ''} onChange={(event) => set('turnstile_secret_key', event.target.value)} /></Field>
      </div>
      <div className="settings-switches"><Switch checked={Boolean(draft.email_verify)} onChange={(value) => set('email_verify', value)} label="注册邮箱验证" /><Switch checked={Boolean(draft.safe_mode_enable)} onChange={(value) => set('safe_mode_enable', value)} label="安全模式" /><Switch checked={Boolean(draft.captcha_enable)} onChange={(value) => set('captcha_enable', value)} label="启用验证码" /><Switch checked={Boolean(draft.register_limit_by_ip_enable)} onChange={(value) => set('register_limit_by_ip_enable', value)} label="限制同 IP 注册" /></div>
    </> : null}

    {section === 'frontend' ? <>
      <div className="form-grid">
        <Field label="用户前台主题"><Input value={draft.frontend_theme || ''} onChange={(event) => set('frontend_theme', event.target.value)} /></Field>
        <Field label="主题色"><Select value={draft.frontend_theme_color || 'default'} onChange={(event) => set('frontend_theme_color', event.target.value)}><option value="default">默认</option><option value="darkblue">深蓝</option><option value="black">黑色</option><option value="green">绿色</option></Select></Field>
        <Field label="侧栏风格"><Select value={draft.frontend_theme_sidebar || 'light'} onChange={(event) => set('frontend_theme_sidebar', event.target.value)}><option value="light">浅色</option><option value="dark">深色</option></Select></Field>
        <Field label="顶部风格"><Select value={draft.frontend_theme_header || 'dark'} onChange={(event) => set('frontend_theme_header', event.target.value)}><option value="light">浅色</option><option value="dark">深色</option></Select></Field>
        <Field label="登录背景 URL" className="span-2"><Input type="url" value={draft.frontend_background_url || ''} onChange={(event) => set('frontend_background_url', event.target.value)} /></Field>
      </div>
    </> : null}

    {section === 'client' ? <div className="form-grid">
      <Field label="Windows 版本"><Input value={draft.windows_version || ''} onChange={(event) => set('windows_version', event.target.value)} /></Field><Field label="Windows 下载地址"><Input value={draft.windows_download_url || ''} onChange={(event) => set('windows_download_url', event.target.value)} /></Field>
      <Field label="macOS 版本"><Input value={draft.macos_version || ''} onChange={(event) => set('macos_version', event.target.value)} /></Field><Field label="macOS 下载地址"><Input value={draft.macos_download_url || ''} onChange={(event) => set('macos_download_url', event.target.value)} /></Field>
      <Field label="Android 版本"><Input value={draft.android_version || ''} onChange={(event) => set('android_version', event.target.value)} /></Field><Field label="Android 下载地址"><Input value={draft.android_download_url || ''} onChange={(event) => set('android_download_url', event.target.value)} /></Field>
    </div> : null}

    {section === 'notifications' ? <>
      <div className="form-grid">
        <Field label="SMTP 主机"><Input value={draft.email_host || ''} onChange={(event) => set('email_host', event.target.value)} /></Field>
        <Field label="SMTP 端口"><Input type="number" value={draft.email_port || ''} onChange={(event) => set('email_port', Number(event.target.value))} /></Field>
        <Field label="SMTP 用户名"><Input value={draft.email_username || ''} onChange={(event) => set('email_username', event.target.value)} /></Field>
        <Field label="SMTP 密码"><Input type="password" value={draft.email_password || ''} onChange={(event) => set('email_password', event.target.value)} /></Field>
        <Field label="发件地址"><Input type="email" value={draft.email_from_address || ''} onChange={(event) => set('email_from_address', event.target.value)} /></Field>
        <Field label="加密方式"><Select value={draft.email_encryption || ''} onChange={(event) => set('email_encryption', event.target.value)}><option value="">无</option><option value="tls">TLS</option><option value="ssl">SSL</option></Select></Field>
        <Field label="Telegram Bot Token"><Input type="password" value={draft.telegram_bot_token || ''} onChange={(event) => set('telegram_bot_token', event.target.value)} /></Field>
        <Field label="Telegram Webhook URL"><Input type="url" value={draft.telegram_webhook_url || ''} onChange={(event) => set('telegram_webhook_url', event.target.value)} /></Field>
      </div>
      <div className="settings-switches"><Switch checked={Boolean(draft.remind_mail_enable)} onChange={(value) => set('remind_mail_enable', value)} label="启用邮件提醒" /><Switch checked={Boolean(draft.telegram_bot_enable)} onChange={(value) => set('telegram_bot_enable', value)} label="启用 Telegram Bot" /></div>
    </> : null}

    <div className="settings-actions"><Button type="submit" loading={saving}><Save size={14} />保存设置</Button></div>
  </form>
}

function TemplateSettings({ initial, onSaved }: { initial: ConfigSection; onSaved: () => void }) {
  const templates = [
    ['clashmeta', 'Clash Meta (mihomo)', 'YAML'],
  ] as const
  const [active, setActive] = useState<(typeof templates)[number][0]>('clashmeta')
  const [draft, setDraft] = useState<ConfigSection>({ ...initial })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const { notify } = useToast()
  const key = `subscribe_template_${active}`
  const current = templates.find(([id]) => id === active)!

  async function save() {
    setSaving(true)
    setError('')
    try {
      await api.adminPost('config/save', draft)
      notify('订阅模板已保存')
      onSaved()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存订阅模板失败')
    } finally {
      setSaving(false)
    }
  }

  return <div className="settings-form">
    {error ? <div className="login-error" role="alert">{error}</div> : null}
    <div className="tabs template-tabs" role="tablist">{templates.map(([id, label]) => <button key={id} type="button" className={`tab ${active === id ? 'is-active' : ''}`} aria-selected={active === id} onClick={() => setActive(id)}>{label}</button>)}</div>
    <Field label={`${current[1]} 订阅模板`} hint={`${current[2]} 格式。保存前请确认缩进和占位符没有被破坏。`}><Textarea className="template-editor mono" value={draft[key] || ''} onChange={(event) => setDraft((value) => ({ ...value, [key]: event.target.value }))} spellCheck={false} /></Field>
    <div className="settings-actions"><Button loading={saving} onClick={() => void save()}><Save size={14} />保存全部模板</Button></div>
  </div>
}

export function SystemSettings() {
  const request = useSWR('system-config', () => api.adminGet<SystemConfigData>('config/fetch'))
  const [tab, setTab] = useState<SettingsTab>('site')
  const data = request.data
  const tabs: Array<{ key: SettingsTab; label: string; icon: React.ReactNode }> = [
    { key: 'site', label: '站点', icon: <Globe2 size={15} /> },
    { key: 'subscribe', label: '订阅设置', icon: <SlidersHorizontal size={15} /> },
    { key: 'templates', label: '订阅模板', icon: <RadioTower size={15} /> },
    { key: 'server', label: '节点通信', icon: <RadioTower size={15} /> },
    { key: 'frontend', label: '用户前台', icon: <MonitorSmartphone size={15} /> },
    { key: 'client', label: '客户端', icon: <MonitorSmartphone size={15} /> },
    { key: 'security', label: '安全', icon: <ShieldCheck size={15} /> },
    { key: 'notifications', label: '通知', icon: <Bell size={15} /> },
  ]

  if (request.error) return <ErrorState error={request.error} retry={() => void request.mutate()} />
  if (!data) return <LoadingTable rows={6} columns={4} />
  const sectionData = tab === 'notifications' ? { ...data.email, ...data.telegram } : tab === 'security' ? data.safe : tab === 'client' ? data.app : data[tab as keyof SystemConfigData] || {}

  return <Card className="system-settings-card">
    <div className="card-header"><div><h2>系统配置</h2><p>恢复旧后台的站点、订阅模板和自托管所需设置；支付与套餐配置不在此构建中。</p></div></div>
    <div className="settings-layout">
      <nav className="settings-nav" aria-label="系统配置分类">{tabs.map((item) => <button key={item.key} type="button" className={tab === item.key ? 'is-active' : ''} onClick={() => setTab(item.key)}>{item.icon}{item.label}</button>)}</nav>
      <div className="settings-content">{tab === 'templates' ? <TemplateSettings key="templates" initial={data.subscribe_template} onSaved={() => void request.mutate()} /> : <SettingsForm key={tab} section={tab} initial={sectionData} onSaved={() => void request.mutate()} />}</div>
    </div>
  </Card>
}
