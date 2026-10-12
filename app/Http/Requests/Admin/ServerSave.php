<?php


namespace App\Http\Requests\Admin;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;

class ServerSave extends FormRequest
{
    private const UTLS_RULES = [
        'utls.enabled' => 'nullable|boolean',
        'utls.fingerprint' => 'nullable|string',
    ];

    private const MULTIPLEX_RULES = [
        'multiplex.enabled' => 'nullable|boolean',
        'multiplex.protocol' => 'nullable|string',
        'multiplex.max_connections' => 'nullable|integer',
        'multiplex.min_streams' => 'nullable|integer',
        'multiplex.max_streams' => 'nullable|integer',
        'multiplex.padding' => 'nullable|boolean',
        'multiplex.brutal.enabled' => 'nullable|boolean',
        'multiplex.brutal.up_mbps' => 'nullable|integer',
        'multiplex.brutal.down_mbps' => 'nullable|integer',
    ];

    /** mihomo `mux-option` block shared by ss/vmess/vless/trojan/sudoku/tuic/hysteria2. */
    private const MUX_OPTION_RULES = [
        'mux_option.padding' => 'nullable|boolean',
        'mux_option.brutal.enabled' => 'nullable|boolean',
        'mux_option.brutal.up' => 'nullable|integer',
        'mux_option.brutal.down' => 'nullable|integer',
    ];

    private const ECH_RULES = [
        'enabled' => 'nullable|boolean',
        'config' => 'nullable|string',
        'query_server_name' => 'nullable|string',
        'key' => 'nullable|string',
    ];

    private const REALITY_RULES = [
        'reality_settings.allow_insecure' => 'nullable|boolean',
        'reality_settings.server_name' => 'nullable|string',
        'reality_settings.server_port' => 'nullable|integer',
        'reality_settings.public_key' => 'nullable|string',
        'reality_settings.private_key' => 'nullable|string',
        'reality_settings.short_id' => 'nullable|string',
    ];

    /** Optional override of the server-side mihomo `reality-config` block. */
    private const REALITY_SERVER_RULES = [
        'reality_config.enabled' => 'nullable|boolean',
        'reality_config.dest' => 'nullable|string|max:255',
        'reality_config.private_key' => 'nullable|string',
        'reality_config.short_id' => 'nullable|array',
        'reality_config.short_id.*' => 'nullable|string',
        'reality_config.server_names' => 'nullable|array',
        'reality_config.server_names.*' => 'nullable|string',
        'reality_config.max_time_difference' => 'nullable|integer',
        'reality_config.proxy' => 'nullable|string|max:64',
    ];

    /**
     * Listener-root options every TLS-capable inbound can carry, plus the raw
     * mihomo listener override. Unknown `protocol_settings` keys are preserved
     * by the Server model, so this list only types the modelled fields.
     */
    private const LISTENER_TLS_RULES = [
        'client_auth_type' => 'nullable|string|max:32',
        'client_auth_cert' => 'nullable|string',
        'ech_key' => 'nullable|string',
        'sniff' => 'nullable|boolean',
        'sniff_override' => 'nullable|boolean',
        'listener' => 'nullable|array',
    ];

    private const TLS_SETTINGS_RULES = [
        'tls_settings.server_name' => 'nullable|string',
        'tls_settings.allow_insecure' => 'nullable|boolean',
        'tls_settings.ech' => 'nullable|array',
        'tls_settings.ech.enabled' => 'nullable|boolean',
        'tls_settings.ech.config' => 'nullable|string',
        'tls_settings.ech.query_server_name' => 'nullable|string',
        'tls_settings.ech.key' => 'nullable|string',
    ];

    private const TLS_OBJECT_RULES = [
        'tls' => 'nullable|array',
        'tls.server_name' => 'nullable|string',
        'tls.allow_insecure' => 'nullable|boolean',
        'tls.ech' => 'nullable|array',
        'tls.ech.enabled' => 'nullable|boolean',
        'tls.ech.config' => 'nullable|string',
        'tls.ech.query_server_name' => 'nullable|string',
        'tls.ech.key' => 'nullable|string',
    ];

    private const PROTOCOL_RULES = [
        'shadowsocks' => [
            'cipher' => 'required|string',
            'password' => 'nullable|string|max:255',
            'udp' => 'nullable|boolean',
            'obfs' => 'nullable|string',
            'obfs_settings.path' => 'nullable|string',
            'obfs_settings.host' => 'nullable|string',
            'simple_obfs.enable' => 'nullable|boolean',
            'simple_obfs.mode' => 'nullable|string|in:http,tls',
            'plugin' => 'nullable|string',
            'plugin_opts' => 'nullable|string',
        ],
        'vmess' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'rules' => 'nullable|array',
        ],
        'vless' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'flow' => 'nullable|string',
            'encryption' => 'nullable|array',
            'encryption.enabled' => 'nullable|boolean',
            'encryption.encryption' => 'nullable|string',
            'encryption.decryption' => 'nullable|string',
        ],
        'trojan' => [
            'tls' => 'nullable|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'server_name' => 'nullable|string',
            'allow_insecure' => 'nullable|boolean',
            'ss_option.enabled' => 'nullable|boolean',
            'ss_option.method' => 'nullable|string|in:aes-128-gcm,aes-256-gcm,chacha20-ietf-poly1305',
            'ss_option.password' => 'nullable|string',
        ],
        'http' => [
            'tls' => 'nullable|integer',
        ],
        'socks' => [
            'tls' => 'nullable|integer',
            'udp' => 'nullable|boolean',
        ],
        'mixed' => [
            'tls' => 'nullable|integer',
            'udp' => 'nullable|boolean',
        ],
        'snell' => [
            'psk' => 'required|string|max:255',
            'version' => 'nullable|integer|in:1,2,3,4,5',
            'udp' => 'nullable|boolean',
            'obfs_opts.mode' => 'nullable|string|in:http,tls',
            'obfs_opts.host' => 'nullable|string|max:255',
        ],
        'mieru' => [
            'transport' => 'required|string|in:TCP,UDP',
            'traffic_pattern' => 'nullable|string',
            'user_hint_is_mandatory' => 'nullable|boolean',
        ],
        'sudoku' => [
            'key' => 'required|string|max:255',
            'aead_method' => 'nullable|string|in:chacha20-poly1305,aes-128-gcm,none',
            'padding_min' => 'nullable|integer|min:0',
            'padding_max' => 'nullable|integer|min:0',
            'table_type' => 'nullable|string|in:prefer_ascii,prefer_entropy,up_ascii_down_entropy,up_entropy_down_ascii',
            'custom_table' => 'nullable|string',
            'custom_tables' => 'nullable|array',
            'handshake_timeout' => 'nullable|integer|min:0',
            'enable_pure_downlink' => 'nullable|boolean',
            'httpmask.disable' => 'nullable|boolean',
            'httpmask.mode' => 'nullable|string|in:legacy,stream,poll,auto,ws',
            'httpmask.path_root' => 'nullable|string|max:255',
        ],
        'tuic' => [
            'version' => 'nullable|integer|in:4,5',
            'congestion_controller' => 'nullable|string|in:cubic,new_reno,bbr',
            'congestion_control' => 'nullable|string|in:cubic,new_reno,bbr',
            'alpn' => 'nullable|array',
            'alpn.*' => 'nullable|string',
            'udp_relay_mode' => 'nullable|string|in:native,quic',
            'max_idle_time' => 'nullable|integer|min:0',
            'authentication_timeout' => 'nullable|integer|min:0',
            'max_udp_relay_packet_size' => 'nullable|integer|min:0',
            'bbr_profile' => 'nullable|string|in:standard,conservative,aggressive',
        ],
        'hysteria2' => [
            'version' => 'nullable|integer',
            'up' => 'nullable|integer|min:0',
            'down' => 'nullable|integer|min:0',
            'bandwidth.up' => 'nullable|integer|min:0',
            'bandwidth.down' => 'nullable|integer|min:0',
            'ignore_client_bandwidth' => 'nullable|boolean',
            'obfs.open' => 'nullable|boolean',
            'obfs.type' => 'nullable|string|in:salamander',
            'obfs.password' => 'nullable|string',
            'obfs_password' => 'nullable|string',
            'masquerade' => 'nullable|string|max:2048',
            'bbr_profile' => 'nullable|string|in:standard,conservative,aggressive',
            'hop_interval' => 'nullable|integer|min:0',
        ],
        'shadowquic' => [
            'jls_upstream.addr' => 'nullable|string|max:255',
            'jls_upstream.sni' => 'nullable|string|max:255',
            'jls_upstream.proxy' => 'nullable|string|max:64',
            'jls_upstream.rate_limit' => 'nullable|integer|min:0',
            'alpn' => 'nullable|array',
            'alpn.*' => 'nullable|string',
            'quic_versions' => 'nullable|array',
            'quic_versions.*' => 'nullable|string|in:v1,v2',
            'zero_rtt' => 'nullable|boolean',
            'congestion_controller' => 'nullable|string|in:cubic,new_reno,bbr',
            'up' => 'nullable|integer|min:0',
            'down' => 'nullable|integer|min:0',
            'ignore_client_bandwidth' => 'nullable|boolean',
            'cwnd' => 'nullable|integer|min:1',
            'bbr_profile' => 'nullable|string|in:standard,conservative,aggressive',
            'max_idle_time' => 'nullable|integer|min:0',
            'max_datagram_frame_size' => 'nullable|integer|min:0',
            'disable_mtu_discovery' => 'nullable|boolean',
        ],
        'trusttunnel' => [
            'network' => 'nullable|array',
            'network.*' => 'nullable|string|in:tcp,udp',
            'congestion_controller' => 'nullable|string|in:cubic,new_reno,bbr',
            'bbr_profile' => 'nullable|string|in:standard,conservative,aggressive',
        ],
        'anytls' => [
            'tls' => 'nullable|array',
            'alpn' => 'nullable|string',
            'padding_scheme' => 'nullable|array',
        ],
    ];

    private function getBaseRules(): array
    {
        return [
            'type' => 'required|in:' . implode(',', Server::VALID_TYPES),
            'spectific_key' => 'nullable|string',
            'code' => 'nullable|string',
            'show' => '',
            'name' => 'required|string',
            'group_ids' => 'nullable|array',
            'route_ids' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'machine_ids' => 'nullable|array',
            'machine_ids.*' => 'integer|distinct|exists:v2_server_machine,id',
            'machine_bindings' => 'nullable|array',
            'machine_bindings.*.machine_id' => 'required|integer|distinct|exists:v2_server_machine,id',
            'machine_bindings.*.state' => 'nullable|string|in:active,draining,disabled',
            'enabled' => 'nullable|boolean',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'excludes' => 'nullable|array',
            'ips' => 'nullable|array',
            'rate' => 'required|numeric',
            'rate_time_enable' => 'nullable|boolean',
            'rate_time_ranges' => 'nullable|array',
            'custom_outbounds' => 'nullable|array',
            'custom_routes' => 'nullable|array',
            'cert_config' => 'nullable|array',
            'cert_config.cert_mode' => 'nullable|string|in:none,self,content,file,http,dns',
            'cert_config.domain' => 'nullable|string|max:253',
            'cert_config.email' => 'nullable|email|max:254',
            'cert_config.dns_provider' => 'nullable|string|max:64',
            'cert_config.dns_env' => 'nullable|array',
            'cert_config.dns_env.*' => 'nullable|string',
            'cert_config.http_port' => 'nullable|integer|min:1|max:65535',
            'cert_config.cert_file' => 'nullable|string|max:4096',
            'cert_config.key_file' => 'nullable|string|max:4096',
            'cert_config.cert_content' => 'nullable|string',
            'cert_config.key_content' => 'nullable|string',
            'rate_time_ranges.*.start' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.end' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.rate' => 'required_with:rate_time_ranges|numeric|min:0',
            'protocol_settings' => 'array',
            'transfer_enable' => 'nullable|integer|min:0',
        ];
    }

    private function getProtocolRules(string $type): array
    {
        $type = Server::normalizeType($type) ?? $type;
        $rules = self::PROTOCOL_RULES[$type] ?? [];

        return match ($type) {
            'vmess' => array_merge(
                $rules,
                self::TLS_SETTINGS_RULES,
                self::REALITY_RULES,
                self::REALITY_SERVER_RULES,
                self::MULTIPLEX_RULES,
                self::MUX_OPTION_RULES,
                self::UTLS_RULES,
                self::LISTENER_TLS_RULES,
            ),
            'vless' => array_merge(
                $rules,
                self::TLS_SETTINGS_RULES,
                self::REALITY_RULES,
                self::REALITY_SERVER_RULES,
                self::MULTIPLEX_RULES,
                self::MUX_OPTION_RULES,
                self::UTLS_RULES,
                self::LISTENER_TLS_RULES,
            ),
            'trojan' => array_merge(
                $rules,
                self::TLS_SETTINGS_RULES,
                self::REALITY_RULES,
                self::REALITY_SERVER_RULES,
                self::MULTIPLEX_RULES,
                self::MUX_OPTION_RULES,
                self::UTLS_RULES,
                self::LISTENER_TLS_RULES,
            ),
            'shadowsocks' => array_merge(
                $rules,
                self::MUX_OPTION_RULES,
                self::LISTENER_TLS_RULES,
            ),
            'http', 'socks', 'mixed' => array_merge(
                $rules,
                self::TLS_SETTINGS_RULES,
                self::LISTENER_TLS_RULES,
            ),
            'anytls' => array_merge(
                $rules,
                self::TLS_OBJECT_RULES,
                self::LISTENER_TLS_RULES,
            ),
            'tuic' => array_merge(
                $rules,
                self::TLS_OBJECT_RULES,
                self::MUX_OPTION_RULES,
                self::LISTENER_TLS_RULES,
            ),
            'hysteria2' => array_merge(
                $rules,
                self::TLS_OBJECT_RULES,
                self::MUX_OPTION_RULES,
                self::LISTENER_TLS_RULES,
            ),
            'mieru', 'sudoku' => array_merge(
                $rules,
                self::MULTIPLEX_RULES,
                self::MUX_OPTION_RULES,
                self::LISTENER_TLS_RULES,
            ),
            'shadowquic' => array_merge(
                $rules,
                self::MUX_OPTION_RULES,
                self::LISTENER_TLS_RULES,
            ),
            'trusttunnel', 'snell' => array_merge(
                $rules,
                self::LISTENER_TLS_RULES,
            ),
            default => $rules,
        };
    }

    /**
     * Fold legacy kernel-specific type names into the canonical mihomo inbound
     * before validation, so editing an old row saves the new value.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('type')) {
            $normalized = Server::normalizeType((string) $this->input('type'));
            if ($normalized !== null) {
                $this->merge(['type' => $normalized]);
            }
        }
    }

    public function rules(): array
    {
        $type = (string) $this->input('type');
        $rules = $this->getBaseRules();
        $protocolRules = $this->getProtocolRules($type);

        foreach ($protocolRules as $field => $rule) {
            $rules['protocol_settings.' . $field] = $rule;
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'protocol_settings.cipher' => '加密方式',
            'protocol_settings.obfs' => '混淆类型',
            'protocol_settings.network' => '传输协议',
            'protocol_settings.port_range' => '端口范围',
            'protocol_settings.traffic_pattern' => 'Traffic Pattern',
            'protocol_settings.transport' => '传输方式',
            'protocol_settings.version' => '协议版本',
            'protocol_settings.password' => '密码',
            'protocol_settings.psk' => 'Snell PSK',
            'protocol_settings.key' => 'Sudoku 密钥',
            'protocol_settings.token' => 'Realm Token',
            'protocol_settings.up' => '上行带宽',
            'protocol_settings.down' => '下行带宽',
            'protocol_settings.masquerade' => '伪装站点',
            'protocol_settings.target' => '转发目标',
            'protocol_settings.multiplex.enabled' => '多路复用',
            'protocol_settings.multiplex.protocol' => '复用协议',
            'protocol_settings.multiplex.max_connections' => '最大连接数',
            'protocol_settings.multiplex.min_streams' => '最小流数',
            'protocol_settings.multiplex.max_streams' => '最大流数',
            'protocol_settings.multiplex.padding' => '复用填充',
            'protocol_settings.multiplex.brutal.enabled' => 'Brutal加速',
            'protocol_settings.multiplex.brutal.up_mbps' => 'Brutal上行速率',
            'protocol_settings.multiplex.brutal.down_mbps' => 'Brutal下行速率',
            'protocol_settings.mux_option.padding' => 'mihomo 多路复用填充',
            'protocol_settings.utls.enabled' => 'uTLS',
            'protocol_settings.utls.fingerprint' => 'uTLS指纹',
            'protocol_settings.tls_settings.ech.enabled' => 'ECH',
            'protocol_settings.tls_settings.ech.config' => 'ECH配置',
            'protocol_settings.tls_settings.ech.query_server_name' => 'ECH查询域名',
            'protocol_settings.tls_settings.ech.key' => 'ECH密钥',
            'protocol_settings.tls.ech.enabled' => 'ECH',
            'protocol_settings.tls.ech.config' => 'ECH配置',
            'protocol_settings.tls.ech.query_server_name' => 'ECH查询域名',
            'protocol_settings.tls.ech.key' => 'ECH密钥',
            'protocol_settings.client_auth_type' => 'mTLS 客户端验证',
            'protocol_settings.client_auth_cert' => 'mTLS CA 证书',
            'protocol_settings.ech_key' => 'ECH 密钥',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => '节点名称不能为空',
            'group_ids.required' => '权限组不能为空',
            'group_ids.array' => '权限组格式不正确',
            'route_ids.array' => '路由组格式不正确',
            'parent_id.integer' => '父ID格式不正确',
            'host.required' => '节点地址不能为空',
            'port.required' => '连接端口不能为空',
            'server_port.required' => '后端服务端口不能为空',
            'tls.required' => 'TLS不能为空',
            'tags.array' => '标签格式不正确',
            'rate.required' => '倍率不能为空',
            'rate.numeric' => '倍率格式不正确',
            'network.required' => '传输协议不能为空',
            'network.in' => '传输协议格式不正确',
            'networkSettings.array' => '传输协议配置有误',
            'ruleSettings.array' => '规则配置有误',
            'tlsSettings.array' => 'tls配置有误',
            'dnsSettings.array' => 'dns配置有误',
            'protocol_settings.*.required' => ':attribute 不能为空',
            'protocol_settings.*.required_if' => ':attribute 不能为空',
            'protocol_settings.*.string' => ':attribute 必须是字符串',
            'protocol_settings.*.integer' => ':attribute 必须是整数',
            'protocol_settings.*.in' => ':attribute 的值不合法',
            'transfer_enable.integer' => '流量上限必须是整数',
            'transfer_enable.min' => '流量上限不能小于0',
        ];
    }
}
