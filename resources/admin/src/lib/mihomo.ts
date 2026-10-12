/**
 * mihomo (Clash.Meta) inbound metadata for the admin UI.
 *
 * The panel has exactly one proxy kernel. Only inbounds that produce a client
 * proxy are offered; mihomo's non-proxy listeners (tun, redir, tproxy, tunnel)
 * and the Hysteria2 realm registry are intentionally absent. This table drives
 * the node type picker, the TLS
 * requirements and the generic option editor used by the protocols that do not
 * have a bespoke form.
 *
 * Every option the panel does not model here is still reachable through the
 * "高级协议配置 (JSON)" editor on the node form, which round-trips arbitrary
 * `protocol_settings` keys into the generated mihomo listener.
 *
 * @see docs/en/kernel/mihomo-inbound-listeners.md
 */

export type MihomoTlsMode = 'required' | 'optional' | 'none'

export interface MihomoInboundMeta {
  /** Canonical server type stored on the node. */
  type: string
  label: string
  /** Whether the node can appear in a user subscription. */
  subscription: boolean
  tls: MihomoTlsMode
  supportsReality: boolean
  description: string
}

export const MIHOMO_INBOUNDS: MihomoInboundMeta[] = [
  { type: 'vless', label: 'VLESS', subscription: true, tls: 'optional', supportsReality: true, description: 'mihomo vless listener，支持 VLESS、REALITY、XHTTP。' },
  { type: 'vmess', label: 'VMess', subscription: true, tls: 'optional', supportsReality: true, description: 'mihomo vmess listener，支持 ws / grpc / h2 / mkcp。' },
  { type: 'trojan', label: 'Trojan', subscription: true, tls: 'optional', supportsReality: true, description: 'mihomo trojan listener，支持 REALITY 与 Trojan-Go SS。' },
  { type: 'shadowsocks', label: 'Shadowsocks', subscription: true, tls: 'none', supportsReality: false, description: 'mihomo shadowsocks listener；2022 系列使用共享 PSK，不支持每用户密码。' },
  { type: 'hysteria2', label: 'Hysteria2', subscription: true, tls: 'required', supportsReality: false, description: 'mihomo hysteria2 listener，支持 Salamander 混淆、端口跳跃与 masquerade。' },
  { type: 'tuic', label: 'TUIC', subscription: true, tls: 'required', supportsReality: false, description: 'mihomo tuic listener（v4 token / v5 users）。' },
  { type: 'anytls', label: 'AnyTLS', subscription: true, tls: 'optional', supportsReality: false, description: 'mihomo anytls listener，支持 padding-scheme。' },
  { type: 'snell', label: 'Snell', subscription: true, tls: 'none', supportsReality: false, description: 'mihomo snell listener，v1–v5，支持 simple-obfs / shadow-tls / restls / jls。' },
  { type: 'mieru', label: 'Mieru', subscription: true, tls: 'none', supportsReality: false, description: 'mihomo mieru listener，TCP/UDP 传输与流量模式。' },
  { type: 'sudoku', label: 'Sudoku', subscription: true, tls: 'none', supportsReality: false, description: 'mihomo sudoku listener，HTTP 伪装与 AEAD 参数。' },
  { type: 'shadowquic', label: 'ShadowQUIC', subscription: true, tls: 'none', supportsReality: false, description: 'mihomo shadowquic listener，内置 JLS 上游。' },
  { type: 'trusttunnel', label: 'TrustTunnel', subscription: true, tls: 'required', supportsReality: false, description: 'mihomo trusttunnel listener（HTTP/2 与可选 HTTP/3）。' },
  { type: 'socks', label: 'SOCKS5', subscription: true, tls: 'optional', supportsReality: false, description: 'mihomo socks listener。' },
  { type: 'mixed', label: 'Mixed (HTTP+SOCKS)', subscription: true, tls: 'optional', supportsReality: false, description: 'mihomo mixed listener，同一端口同时提供 HTTP 与 SOCKS。' },
  { type: 'http', label: 'HTTP', subscription: true, tls: 'optional', supportsReality: false, description: 'mihomo http listener（HTTP CONNECT）。' },
]

export const MIHOMO_INBOUND_MAP: Record<string, MihomoInboundMeta> = Object.fromEntries(
  MIHOMO_INBOUNDS.map((item) => [item.type, item]),
)

/** Types the admin renders with a dedicated form. */
export const MIHOMO_BESPOKE_FORMS = new Set(['vless', 'vmess', 'trojan', 'shadowsocks', 'hysteria2', 'tuic', 'anytls', 'socks', 'http', 'mieru'])

/** Legacy spellings folded into a canonical mihomo type. */
export const MIHOMO_TYPE_ALIASES: Record<string, string> = {
  hysteria: 'hysteria2',
  hy2: 'hysteria2',
  ss: 'shadowsocks',
  socks5: 'socks',
  v2ray: 'vmess',
}

export function normalizeMihomoType(type?: string | null): string {
  const value = String(type || '').trim().toLowerCase()
  return MIHOMO_TYPE_ALIASES[value] || value
}

export function mihomoInboundMeta(type?: string | null): MihomoInboundMeta | undefined {
  return MIHOMO_INBOUND_MAP[normalizeMihomoType(type)]
}

export type MihomoFieldKind = 'text' | 'number' | 'select' | 'switch' | 'list' | 'textarea'

export interface MihomoFieldDef {
  /** Path inside protocol_settings (snake_case, matching the PHP schema). */
  key: string
  label: string
  kind: MihomoFieldKind
  options?: Array<{ value: string; label: string }>
  placeholder?: string
  hint?: string
  default?: unknown
}

/**
 * Generic editor fields for the protocols without a bespoke form. The keys are
 * the panel-side snake_case names the PHP listener builder reads.
 */
export const MIHOMO_GENERIC_FIELDS: Record<string, MihomoFieldDef[]> = {
  mixed: [
    { key: 'udp', label: '监听 UDP', kind: 'switch', default: true },
  ],
  snell: [
    { key: 'psk', label: 'PSK', kind: 'text', placeholder: '预共享密钥', hint: 'snell 的必填认证密钥。' },
    { key: 'version', label: '版本', kind: 'select', options: [1, 2, 3, 4, 5].map((v) => ({ value: String(v), label: `v${v}` })), default: 4, hint: 'v3 及以上支持 UDP over TCP。' },
    { key: 'udp', label: 'UDP over TCP', kind: 'switch', default: true },
    { key: 'obfs_opts.mode', label: '混淆模式', kind: 'select', options: [
      { value: '', label: '无' }, { value: 'http', label: 'simple-obfs http' }, { value: 'tls', label: 'simple-obfs tls' },
      { value: 'shadow-tls', label: 'shadow-tls' }, { value: 'restls', label: 'restls' }, { value: 'jls', label: 'jls' },
    ] },
    { key: 'obfs_opts.host', label: '混淆 Host', kind: 'text', placeholder: 'bing.com' },
  ],
  sudoku: [
    { key: 'key', label: 'Key', kind: 'text', placeholder: 'ED25519 公钥或 UUID', hint: 'sudoku listener 的必填密钥。' },
    { key: 'aead_method', label: 'AEAD', kind: 'select', options: [
      { value: 'chacha20-poly1305', label: 'chacha20-poly1305' }, { value: 'aes-128-gcm', label: 'aes-128-gcm' }, { value: 'none', label: 'none' },
    ], default: 'chacha20-poly1305' },
    { key: 'padding_min', label: 'Padding 最小', kind: 'number', placeholder: '0' },
    { key: 'padding_max', label: 'Padding 最大', kind: 'number', placeholder: '100' },
    { key: 'table_type', label: '码表', kind: 'select', options: [
      { value: 'prefer_ascii', label: 'prefer_ascii' }, { value: 'prefer_entropy', label: 'prefer_entropy' },
      { value: 'up_ascii_down_entropy', label: 'up_ascii_down_entropy' }, { value: 'up_entropy_down_ascii', label: 'up_entropy_down_ascii' },
    ] },
    { key: 'custom_table', label: '自定义码表', kind: 'text', placeholder: 'xpxvvpvv' },
    { key: 'handshake_timeout', label: '握手超时', kind: 'number', placeholder: '秒' },
    { key: 'enable_pure_downlink', label: 'Pure Downlink', kind: 'switch' },
  ],
  shadowquic: [
    { key: 'jls_upstream.addr', label: 'JLS 上游地址', kind: 'text', placeholder: 'www.example.com:443', hint: 'shadowquic 必需，形如 host:port。' },
    { key: 'jls_upstream.sni', label: 'JLS SNI', kind: 'text' },
    { key: 'jls_upstream.proxy', label: 'JLS 出口', kind: 'text' },
    { key: 'alpn', label: 'ALPN', kind: 'list', placeholder: 'h3' },
    { key: 'quic_versions', label: 'QUIC 版本', kind: 'list', placeholder: 'v1, v2' },
    { key: 'zero_rtt', label: '0-RTT', kind: 'switch', default: true },
    { key: 'congestion_controller', label: '拥塞控制', kind: 'select', options: ['cubic', 'new_reno', 'bbr'].map((v) => ({ value: v, label: v })), default: 'bbr' },
    { key: 'up', label: '上行 (Mbps)', kind: 'number' },
    { key: 'down', label: '下行 (Mbps)', kind: 'number' },
    { key: 'cwnd', label: '初始拥塞窗口', kind: 'number', default: 32 },
    { key: 'max_idle_time', label: '最大空闲 (ms)', kind: 'number' },
    { key: 'max_datagram_frame_size', label: '最大数据报 (byte)', kind: 'number' },
    { key: 'disable_mtu_discovery', label: '禁用 MTU 探测', kind: 'switch' },
  ],
  trusttunnel: [
    { key: 'network', label: '网络', kind: 'list', placeholder: 'tcp, udp', hint: '包含 udp 时启用 HTTP/3。' },
    { key: 'congestion_controller', label: '拥塞控制', kind: 'select', options: ['cubic', 'new_reno', 'bbr'].map((v) => ({ value: v, label: v })) },
    { key: 'bbr_profile', label: 'BBR Profile', kind: 'select', options: ['standard', 'conservative', 'aggressive'].map((v) => ({ value: v, label: v })) },
  ],
}

/**
 * Listener-root options every TLS-capable inbound accepts.
 */
export const MIHOMO_TLS_FIELDS: MihomoFieldDef[] = [
  { key: 'client_auth_type', label: 'mTLS 客户端验证', kind: 'select', options: [
    { value: '', label: '关闭' },
    { value: 'request', label: 'request' },
    { value: 'require-any', label: 'require-any' },
    { value: 'verify-if-given', label: 'verify-if-given' },
    { value: 'require-and-verify', label: 'require-and-verify' },
  ] },
  { key: 'client_auth_cert', label: 'mTLS CA 证书', kind: 'textarea', hint: 'PEM 内容或路径；verify-if-given / require-and-verify 时必填。' },
  { key: 'ech_key', label: 'ECH 密钥', kind: 'textarea', hint: 'mihomo generate ech-keypair 生成。' },
  { key: 'sniff', label: '域名嗅探', kind: 'switch', hint: '写入全局 sniffer 配置。' },
  { key: 'sniff_override', label: '嗅探覆盖目标', kind: 'switch' },
]

export function mihomoFieldsFor(type: string): MihomoFieldDef[] {
  const meta = mihomoInboundMeta(type)
  const generic = MIHOMO_GENERIC_FIELDS[normalizeMihomoType(type)] || []
  const tls = meta && meta.tls !== 'none' ? MIHOMO_TLS_FIELDS : []
  return [...generic, ...tls]
}
