import type { Group, Machine, NodeRecord, Paginated, RoutingData, UserRecord } from '../types'

const machines: Machine[] = [
  { id: 1, name: 'DE-Hetzner-01', notes: 'Falkenstein · 主力', is_active: true, last_seen_at: Date.now() - 24_000, load_status: { cpu: 18, memory: 42 }, agent_version: '0.9.4', kernel_type: 'mihomo', agent_instance_id: 'f3b9a210-demo', servers_count: 2 },
  { id: 2, name: 'DE-Hetzner-02', notes: 'Nuremberg · DNS 均衡', is_active: true, last_seen_at: Date.now() - 55_000, load_status: { cpu: 26, memory: 37 }, agent_version: '0.9.4', kernel_type: 'mihomo', agent_instance_id: 'a82cd411-demo', servers_count: 2 },
  { id: 3, name: 'HK-HKT-01', notes: '香港出口', is_active: true, last_seen_at: Date.now() - 780_000, load_status: { cpu: 71, memory: 63 }, agent_version: '0.9.3', kernel_type: 'mihomo', agent_instance_id: 'e109bd72-demo', servers_count: 1 },
]

const nodes: NodeRecord[] = [
  { id: 11, code: 'de-main', name: 'DE · Reality', type: 'vless', host: 'de.example.com', port: 443, server_port: 8443, rate: 1, show: true, enabled: true, online: 18, online_conn: 31, is_online: true, u: 82_000_000_000, d: 136_000_000_000, machine_ids: [1, 2], machine_bindings: [{ machine_id: 1, state: 'active' }, { machine_id: 2, state: 'active' }], connection_methods: ['node_id', 'machine'], connection_node_id: 'de-main', protocol_settings: { tls: 2, network: 'tcp', reality_settings: { server_name: 'www.microsoft.com' } } },
  { id: 12, name: 'DE · SS 2022', type: 'shadowsocks', host: 'de.example.com', port: 8444, server_port: 8444, rate: 1, show: true, enabled: true, online: 9, online_conn: 12, is_online: true, u: 24_000_000_000, d: 48_000_000_000, machine_ids: [1, 2], machine_bindings: [{ machine_id: 1, state: 'active' }, { machine_id: 2, state: 'draining' }], connection_methods: ['node_id', 'machine'], connection_node_id: 12, protocol_settings: { cipher: '2022-blake3-aes-128-gcm' } },
  { id: 21, name: 'HK · Hysteria2', type: 'hysteria', host: 'hk.example.com', port: 443, server_port: 443, rate: 1.5, show: true, enabled: true, online: 4, online_conn: 5, is_online: false, u: 8_000_000_000, d: 18_000_000_000, machine_ids: [3], machine_bindings: [{ machine_id: 3, state: 'active' }], connection_methods: ['node_id', 'machine'], connection_node_id: 21, protocol_settings: { version: 2 } },
  { id: 30, name: 'Lab · 独立节点', type: 'vmess', host: 'lab.example.com', port: 2053, server_port: 2053, rate: .5, show: false, enabled: true, online: 1, online_conn: 1, is_online: true, u: 1_000_000_000, d: 2_000_000_000, machine_ids: [], machine_bindings: [], connection_methods: ['node_id', 'machine'], connection_node_id: 30, protocol_settings: { tls: 1, network: 'ws' } },
]

const routing: RoutingData = {
  outbounds: [
    { id: 1, uuid: '0a6ff4c5-1220-4a74-a014-9f0dd77ef241', name: 'HK Shadowsocks Exit', protocol: 'shadowsocks', settings: { server: 'ss-hk.example.com', server_port: 8388, method: 'aes-256-gcm', password: '********' }, enabled: true },
    { id: 2, uuid: 'f2de3713-8474-4e25-a01b-3bb12579d189', name: 'Internal SOCKS5', protocol: 'socks5', settings: { server: '10.10.0.8', server_port: 1080, username: 'xboard', password: '********' }, enabled: true },
  ],
  rule_sets: [
    { id: 1, uuid: 'd7ba9307-2775-441f-8fe3-5f60d9d7c5c8', name: 'AI 非中国区', tag: 'ai-non-cn', enabled: true, settings: { mihomo: { type: 'http', behavior: 'classical', format: 'yaml', url: 'https://example.com/rules/ai-non-cn.yaml', interval: '1d' } } },
  ],
  routes: [
    { id: 1, uuid: '9dd1110b-f63c-4b28-a8d4-6571c9003991', name: 'Streaming via HK', enabled: true, rules: routeRules() },
    { id: 2, uuid: '5395299e-8483-4658-9be0-d3886a298407', name: 'Block Ads', enabled: true, rules: [{ name: 'ads', match: { domain_suffixes: ['doubleclick.net'] }, action: { type: 'block' } }] },
  ],
  reality: [
    { id: 1, uuid: '1f097db8-e7ec-45e5-9f7f-a3c543cb6daa', name: 'Vision · HuggingFace', enabled: true, settings: { server_name: 'cas-bridge.xethub.hf.co', server_port: 443, private_key: '********', public_key: 'demo-public-key', short_id: '1a2b3c4d5e6f7788', flow: 'xtls-rprx-vision', network: 'tcp', fingerprint: 'chrome', allow_insecure: false } },
  ],
}

function routeRules() {
  return [{ name: 'streaming', match: { domains: ['netflix.com'] }, action: { type: 'route' as const, target: 'proxy-main' } }]
}

const groups: Group[] = [{ id: 1, name: '默认授权组', users_count: 46, server_count: 4 }, { id: 2, name: '管理员', users_count: 4, server_count: 4 }]
const users: UserRecord[] = Array.from({ length: 12 }, (_, index) => ({
  id: 50 - index,
  email: index === 0 ? 'admin@example.com' : `user${50 - index}@example.com`,
  group_id: index < 10 ? 1 : null,
  group: index < 10 ? { id: 1, name: '默认授权组' } : null,
  access_enabled: index < 10,
  access_note: index === 1 ? '家庭设备' : null,
  expired_at: null,
  banned: false,
  is_admin: index === 0,
  online_count: index % 4,
  transfer_enable: 0,
  u: index * 1_100_000_000,
  d: index * 2_300_000_000,
}))

export function demoAdminGet(path: string): unknown {
  if (path === 'stat/getOverride') return { data: { online_nodes: 3, online_devices: 24, online_users: 17, today_traffic: { total: 43_000_000_000 }, month_traffic: { total: 524_000_000_000 }, total_traffic: { total: 4_820_000_000_000 } } }
  if (path === 'server/manage/getNodes') return nodes
  if (path === 'server/machine/fetch') return machines
  if (path === 'server/group/fetch') return groups
  if (path === 'server/routing-template/fetch') return routing
  if (path.startsWith('server/routing-template/server')) return { outbounds: [{ template_id: 1, tag: 'proxy-main', enabled: true }], routes: [{ template_id: 1, enabled: true }], profiles: [{ id: 1, uuid: 'a43d581e-483a-49b7-a364-1af95851ed2b', name: '香港出口', outbound_template_id: 1, enabled: true }], config_version: 18 }
  if (path === 'system/getSystemStatus') return { schedule: true, horizon: true, schedule_last_runtime: Math.floor(Date.now() / 1000) - 38 }
  if (path === 'system/getQueueStats') return { failedJobs: 0, jobsPerMinute: 3, pausedMasters: 0, processes: 4, recentJobs: 28, status: true }
  if (path.startsWith('server/machine/getToken')) return { token: 'xbm_demo_token_hidden' }
  if (path.startsWith('server/machine/installCommand')) return { command: 'curl -fsSL https://example.com/install.sh | sudo bash -s -- --mode machine' }
  if (path.startsWith('user/subscription')) return { ...users[0], subscribe_url: 'https://panel.example.com/s/demo-subscription-token' }
  return null
}

export function demoAdminPost(path: string): unknown {
  if (path === 'user/fetch') return { total: 50, current_page: 1, per_page: 20, last_page: 3, data: users } satisfies Paginated<UserRecord>
  if (path === 'user/create-access') return { ...users[1], id: 51, email: 'new-user@example.com', subscribe_url: 'https://panel.example.com/s/demo-new-user-token' }
  if (path === 'server/machine/save') return { id: 4, token: 'xbm_demo_new_token', install_command: 'sudo xboard-node install' }
  return true
}
