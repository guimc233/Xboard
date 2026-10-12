export type MachineBindingState = 'active' | 'draining' | 'disabled'

export interface MachineBinding {
  machine_id: number
  state: MachineBindingState
  desired_config_version?: number
  applied_config_version?: number | null
  last_error?: string | null
  last_seen_at?: string | number | null
}

export interface Machine {
  id: number
  name: string
  notes?: string | null
  is_active: boolean
  last_seen_at?: string | number | null
  load_status?: Record<string, unknown> | null
  agent_version?: string | null
  kernel_type?: string | null
  capabilities?: string[] | Record<string, unknown>
  agent_instance_id?: string | null
  servers_count: number
  update_request_id?: string | null
  update_version?: string | null
  update_status?: 'pending' | 'running' | 'succeeded' | 'failed' | null
  update_message?: string | null
  update_requested_at?: number | null
  update_completed_at?: number | null
}

export interface NodeRecord {
  id: number
  code?: string | null
  name: string
  type: string
  host: string
  port: number | string
  server_port: number | string
  rate: number | string
  show: boolean | number
  enabled: boolean | number
  parent_id?: number | null
  parent?: { id: number; name: string; type?: string } | null
  sort?: number | null
  online?: number
  online_conn?: number
  is_online?: boolean | number
  health_status?: string
  available_status?: string
  u?: number
  d?: number
  transfer_enable?: number
  group_ids?: number[]
  route_ids?: number[]
  tags?: string[]
  protocol_settings?: Record<string, unknown>
  cert_config?: {
    cert_mode?: 'none' | 'self' | 'content' | 'file' | 'http' | 'dns' | string
    mode?: string
    domain?: string
    email?: string
    dns_provider?: string
    dns_env?: Record<string, string>
    http_port?: number
    cert_file?: string
    key_file?: string
    cert_content?: string
    key_content?: string
  } | null
  custom_outbounds?: unknown[]
  custom_routes?: unknown[]
  machine_ids: number[]
  machine_bindings: MachineBinding[]
  connection_methods: Array<'node_id' | 'machine' | 'parent'>
  connection_node_id: number | string
  outbound_count?: number
  outbound_names?: string[]
  route_profile_count?: number
  route_profile_names?: string[]
}

export interface UserRecord {
  id: number
  email: string
  group_id?: number | null
  group?: { id: number; name: string } | null
  access_enabled: boolean | number
  access_note?: string | null
  expired_at?: number | null
  banned: boolean | number
  is_admin?: boolean | number
  online_count?: number
  transfer_enable?: number
  u?: number
  d?: number
  created_at?: number
  subscribe_url?: string
}

export interface Group {
  id: number
  name: string
  users_count?: number
  server_count?: number
}

export interface OutboundTemplate {
  id: number
  uuid: string
  name: string
  protocol: 'shadowsocks' | 'socks5' | 'vless'
  settings: {
    server?: string
    server_port?: number
    method?: string
    password?: string
    username?: string
    uuid?: string
    network?: 'tcp' | 'ws' | 'grpc'
    flow?: string | null
    tls?: boolean
    server_name?: string | null
    allow_insecure?: boolean
    path?: string | null
    service_name?: string | null
  }
  enabled: boolean
  updated_at?: string
}

export interface RouteRule {
  name?: string
  disabled?: boolean
  match?: {
    rule_sets?: string[]
    domains?: string[]
    domain_suffixes?: string[]
    ip_cidrs?: string[]
    ports?: string[]
    networks?: string[]
    source_cidrs?: string[]
    source_ports?: string[]
  }
  action: { type: 'direct' | 'block' | 'route'; target?: string }
}

export interface RuleSetTemplate {
  id: number
  uuid: string
  name: string
  tag: string
  settings: {
    mihomo: {
      type: 'http' | 'file' | 'inline'
      behavior: 'domain' | 'ipcidr' | 'classical'
      format?: 'yaml' | 'text' | 'mrs' | null
      url?: string | null
      path?: string | null
      interval?: string | null
      proxy?: string | null
      size_limit?: number | null
      header?: Record<string, string[]> | null
      payload?: string[] | null
    }
    /** Original sing-box / Xray source kept for reference after conversion. */
    legacy?: Record<string, unknown> | null
  }
  enabled: boolean
  updated_at?: string
}

export interface RouteTemplate {
  id: number
  uuid: string
  name: string
  rules: RouteRule[]
  enabled: boolean
  updated_at?: string
}

export interface RealityTemplate {
  id: number
  uuid: string
  name: string
  settings: {
    server_name: string
    server_port: number
    private_key: string
    public_key: string
    short_id: string
    flow?: string | null
    network?: string | null
    fingerprint?: string | null
    allow_insecure?: boolean
  }
  enabled: boolean
  updated_at?: string
}

export interface RouteProfile {
  id?: number
  uuid?: string
  name: string
  outbound_template_id: number | null
  entry_server_ids?: number[] | null
  enabled: boolean
}

export interface RoutingData {
  outbounds: OutboundTemplate[]
  rule_sets: RuleSetTemplate[]
  routes: RouteTemplate[]
  reality: RealityTemplate[]
}

export interface Paginated<T> {
  total: number
  current_page: number
  per_page: number
  last_page: number
  data: T[]
}
