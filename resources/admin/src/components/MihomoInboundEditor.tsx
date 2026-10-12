import { Field, Input, Select, Switch, Textarea } from './ui'
import { mihomoFieldsFor, type MihomoFieldDef } from '../lib/mihomo'

function readPath(settings: Record<string, unknown>, path: string): unknown {
  return path.split('.').reduce<unknown>((current, key) => {
    if (current && typeof current === 'object' && !Array.isArray(current)) {
      return (current as Record<string, unknown>)[key]
    }
    return undefined
  }, settings)
}

function asListString(value: unknown): string {
  if (Array.isArray(value)) return value.map((item) => String(item)).join(', ')
  if (value === null || value === undefined) return ''
  return String(value)
}

/**
 * Spec-driven editor for mihomo inbound options. It is used for the protocols
 * that do not have a hand-written form, and to expose the listener-root TLS
 * options on every inbound. Unknown options stay reachable through the raw
 * protocol_settings JSON editor on the node form.
 */
export function MihomoInboundEditor({ type, settings, onChange }: {
  type: string
  settings: Record<string, any>
  onChange: (path: string, value: unknown) => void
}) {
  const fields = mihomoFieldsFor(type)
  if (!fields.length) return null

  function renderField(field: MihomoFieldDef) {
    const value = readPath(settings, field.key)
    const id = `mihomo-${type}-${field.key.replace(/\./g, '-')}`

    if (field.kind === 'switch') {
      return (
        <div className="field switch-field" key={field.key}>
          <Switch
            checked={value === undefined ? Boolean(field.default) : Boolean(value)}
            onChange={(checked) => onChange(field.key, checked)}
            label={field.label}
          />
          {field.hint ? <span className="field-hint">{field.hint}</span> : null}
        </div>
      )
    }

    if (field.kind === 'select') {
      return (
        <Field label={field.label} hint={field.hint} key={field.key}>
          <Select
            id={id}
            value={value === undefined || value === null ? String(field.default ?? '') : String(value)}
            onChange={(event) => onChange(field.key, event.target.value)}
          >
            {(field.options || []).map((option) => <option value={option.value} key={option.value}>{option.label}</option>)}
          </Select>
        </Field>
      )
    }

    if (field.kind === 'list') {
      return (
        <Field label={field.label} hint={field.hint || '多个值用逗号分隔'} key={field.key}>
          <Input
            id={id}
            className="mono"
            value={asListString(value)}
            placeholder={field.placeholder}
            onChange={(event) => {
              const items = event.target.value.split(',').map((item) => item.trim()).filter(Boolean)
              onChange(field.key, items)
            }}
          />
        </Field>
      )
    }

    if (field.kind === 'textarea') {
      return (
        <Field label={field.label} hint={field.hint} key={field.key} className="span-2">
          <Textarea
            id={id}
            className="mono textarea-large"
            value={value === null || value === undefined ? '' : String(value)}
            placeholder={field.placeholder}
            onChange={(event) => onChange(field.key, event.target.value)}
            spellCheck={false}
          />
        </Field>
      )
    }

    if (field.kind === 'number') {
      return (
        <Field label={field.label} hint={field.hint} key={field.key}>
          <Input
            id={id}
            type="number"
            value={value === null || value === undefined ? '' : String(value)}
            placeholder={field.placeholder}
            onChange={(event) => onChange(field.key, event.target.value === '' ? null : Number(event.target.value))}
          />
        </Field>
      )
    }

    return (
      <Field label={field.label} hint={field.hint} key={field.key}>
        <Input
          id={id}
          className="mono"
          value={value === null || value === undefined ? '' : String(value)}
          placeholder={field.placeholder}
          onChange={(event) => onChange(field.key, event.target.value)}
        />
      </Field>
    )
  }

  return <div className="form-grid">{fields.map(renderField)}</div>
}
