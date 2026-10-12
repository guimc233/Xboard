import { useState, type FormEvent } from 'react'
import { Database } from 'lucide-react'
import { api } from '../lib/api'
import type { RuleSetTemplate } from '../types'
import { Badge, Button, Field, Input, Modal, Select, Switch, Textarea, useToast } from './ui'

const MRS_BEHAVIORS = ['domain', 'ipcidr']

export function RuleSetModal({ ruleSet, onClose, onSaved }: {
  ruleSet?: RuleSetTemplate
  onClose: () => void
  onSaved: () => void
}) {
  const mihomo = ruleSet?.settings?.mihomo
  const [name, setName] = useState(ruleSet?.name || '')
  const [tag, setTag] = useState(ruleSet?.tag || '')
  const [enabled, setEnabled] = useState(ruleSet ? ruleSet.enabled : true)
  const [type, setType] = useState<'http' | 'file' | 'inline'>(mihomo?.type || 'http')
  const [behavior, setBehavior] = useState<'domain' | 'ipcidr' | 'classical'>(mihomo?.behavior || 'domain')
  const [format, setFormat] = useState<'yaml' | 'text' | 'mrs'>(mihomo?.format || 'yaml')
  const [url, setURL] = useState(mihomo?.url || '')
  const [path, setPath] = useState(mihomo?.path || '')
  const [interval, setInterval] = useState(mihomo?.interval || '1d')
  const [proxy, setProxy] = useState(mihomo?.proxy || '')
  const [sizeLimit, setSizeLimit] = useState(mihomo?.size_limit ? String(mihomo.size_limit) : '')
  const [payload, setPayload] = useState((mihomo?.payload || []).join('\n'))
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const { notify } = useToast()

  const effectiveFormat = format === 'mrs' && !MRS_BEHAVIORS.includes(behavior) ? 'yaml' : format

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.adminPost('server/routing-template/rule-set/save', {
        id: ruleSet?.id,
        name,
        tag,
        enabled,
        settings: {
          mihomo: {
            type,
            behavior,
            format: effectiveFormat,
            url: type === 'http' ? url : null,
            path: type === 'file' ? path : null,
            interval: type === 'http' ? interval || null : null,
            proxy: type === 'http' ? proxy || null : null,
            size_limit: type === 'http' && sizeLimit !== '' ? Number(sizeLimit) : null,
            payload: type === 'inline'
              ? payload.split('\n').map((line) => line.trim()).filter(Boolean)
              : null,
          },
        },
      })
      notify(ruleSet ? '规则集已更新并准备重新下发' : '规则集已创建')
      onSaved()
      onClose()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存规则集失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} width="large" title={ruleSet ? `编辑 ${ruleSet.name}` : '新建规则集'} description="一个逻辑标签对应一个 mihomo rule-provider；路由规则通过 RULE-SET 引用它。" footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} onClick={() => document.getElementById('rule-set-submit')?.click()}>保存规则集</Button></>}>
      <form className="form-section" onSubmit={submit}>
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <div className="form-grid">
          <Field label="显示名称" required><Input value={name} onChange={(event) => setName(event.target.value)} placeholder="例如 AI 非中国区" required /></Field>
          <Field label="逻辑标签" hint="路由规则与 RULE-SET 引用的稳定标识，只允许字母、数字、点、下划线和短横线。" required><Input className="mono" value={tag} onChange={(event) => setTag(event.target.value)} placeholder="ai-non-cn" pattern="[A-Za-z0-9_.-]+" required /></Field>
        </div>

        <section className="ruleset-source-card" aria-labelledby="mihomo-source-title">
          <header><div><Database size={18} /><div><h3 id="mihomo-source-title">mihomo rule-provider</h3><p>生成 `rule-providers` 条目，由核心按 interval 缓存更新。</p></div></div><Badge tone="info">mihomo</Badge></header>
          <div className="form-grid">
            <Field label="来源类型"><Select value={type} onChange={(event) => setType(event.target.value as typeof type)}><option value="http">远程 URL</option><option value="file">本地文件</option><option value="inline">内联列表</option></Select></Field>
            <Field label="行为 (behavior)" hint="domain=域名，ipcidr=网段，classical=完整规则行"><Select value={behavior} onChange={(event) => setBehavior(event.target.value as typeof behavior)}><option value="domain">domain</option><option value="ipcidr">ipcidr</option><option value="classical">classical</option></Select></Field>
            <Field label="格式 (format)" hint="mrs 仅支持 domain / ipcidr">
              <Select value={effectiveFormat} onChange={(event) => setFormat(event.target.value as typeof format)}>
                <option value="yaml">yaml</option>
                <option value="text">text</option>
                <option value="mrs" disabled={!MRS_BEHAVIORS.includes(behavior)}>mrs</option>
              </Select>
            </Field>
            {type === 'http' ? <>
              <Field className="span-2" label="规则 URL" required><Input type="url" value={url} onChange={(event) => setURL(event.target.value)} placeholder="https://example.com/rules/ai-non-cn.yaml" required /></Field>
              <Field label="更新间隔" hint="例如 12h、1d，或直接填秒数"><Input value={interval} onChange={(event) => setInterval(event.target.value)} placeholder="1d" /></Field>
              <Field label="下载出口" hint="留空使用默认直连"><Input value={proxy} onChange={(event) => setProxy(event.target.value)} placeholder="DIRECT" /></Field>
              <Field label="大小上限 (byte)" hint="0 或留空表示不限制"><Input type="number" min="0" value={sizeLimit} onChange={(event) => setSizeLimit(event.target.value)} /></Field>
            </> : null}
            {type === 'file' ? <Field className="span-2" label="节点本地路径" required><Input className="mono" value={path} onChange={(event) => setPath(event.target.value)} placeholder="/etc/xboard-node/rules/ai-non-cn.yaml" required /></Field> : null}
            {type === 'inline' ? <Field className="span-2" label="内联规则列表" hint="每行一条 payload，例如 +.example.com 或 10.0.0.0/8"><Textarea className="mono" value={payload} onChange={(event) => setPayload(event.target.value)} rows={6} spellCheck={false} /></Field> : null}
          </div>
        </section>

        <Switch checked={enabled} onChange={setEnabled} label="启用规则集" />
        <button id="rule-set-submit" type="submit" hidden />
      </form>
    </Modal>
  )
}
