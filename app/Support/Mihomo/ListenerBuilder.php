<?php

namespace App\Support\Mihomo;

use App\Support\Mihomo;

/**
 * Turns a panel node row into a mihomo `listeners[]` entry.
 *
 * The builder is intentionally pure: it takes a plain array (a Server model
 * cast with toArray()) and returns the exact kebab-case keys mihomo expects.
 * Every option the panel does not model explicitly can still be supplied
 * through `protocol_settings.listener`, which is merged last.
 *
 * @see docs/en/kernel/mihomo-inbound-listeners.md
 */
class ListenerBuilder
{
    /**
     * @param array<string, mixed> $node Server row as an array.
     * @return array<string, mixed>
     */
    public static function build(array $node): array
    {
        $type = Mihomo::normalizeType($node['type'] ?? null);
        $listenerType = Mihomo::listenerType($type);

        if ($listenerType === null) {
            return [];
        }

        $settings = is_array($node['protocol_settings'] ?? null) ? $node['protocol_settings'] : [];

        $listener = [
            'name' => self::listenerName($node),
            'type' => $listenerType,
            'listen' => (string) ($settings['listen'] ?? '0.0.0.0'),
            'port' => (string) ($node['server_port'] ?? $node['port'] ?? ''),
        ];

        $listener = array_merge($listener, self::protocolOptions($type, $settings));

        // TLS material comes from the node certificate config, never from the
        // protocol settings, so a rotated certificate only touches one place.
        $listener = array_merge($listener, self::tlsOptions($type, $settings, $node));
        $listener = array_merge($listener, self::realityOptions($type, $settings));

        // mihomo's Shadowsocks inbound authenticates with a single password
        // (there is no per-user list), so fall back to the shared server PSK
        // when the operator has not stored one explicitly.
        if ($type === 'shadowsocks' && (string) ($listener['password'] ?? '') === '') {
            $listener['password'] = self::shadowsocksPassword($node, (string) ($settings['cipher'] ?? ''));
        }

        // Escape hatch: raw mihomo listener keys win over everything derived.
        if (!empty($settings['listener']) && is_array($settings['listener'])) {
            $listener = array_merge($listener, self::filterRawOverrides($settings['listener']));
        }

        return array_filter($listener, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The shared Shadowsocks secret for a node. 2022 ciphers use a key sized to
     * the cipher; everything else uses a 16-byte key.
     *
     * @param array<string, mixed> $node
     */
    public static function shadowsocksPassword(array $node, string $cipher): string
    {
        $size = Mihomo::SHADOWSOCKS_CIPHERS[$cipher] ?? 16;

        return \App\Utils\Helper::getServerKey($node['created_at'] ?? time(), $size ?: 16);
    }

    /**
     * Compile a full mihomo configuration fragment for a node set.
     *
     * @param list<array<string, mixed>> $listeners
     * @param array<string, mixed> $routeProviders  tag => rule-provider config
     * @param list<string> $rules
     * @param array<string, list<string>> $subRules
     * @return array<string, mixed>
     */
    public static function compile(array $listeners, array $routeProviders = [], array $rules = [], array $subRules = []): array
    {
        $config = ['listeners' => array_values(array_filter($listeners))];

        if ($routeProviders !== []) {
            $config['rule-providers'] = $routeProviders;
        }
        if ($subRules !== []) {
            $config['sub-rules'] = $subRules;
        }
        if ($rules !== []) {
            $config['rules'] = $rules;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $node
     */
    public static function listenerName(array $node): string
    {
        $code = trim((string) ($node['code'] ?? ''));

        return $code !== '' ? $code : 'node-' . (string) ($node['id'] ?? '0');
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function protocolOptions(string $type, array $settings): array
    {
        $s = $settings;

        return match ($type) {
            'http', 'socks', 'mixed' => array_filter([
                'udp' => in_array($type, ['socks', 'mixed'], true) ? (bool) ($s['udp'] ?? true) : null,
            ], fn ($v) => $v !== null),

            'shadowsocks' => self::shadowsocksOptions($s),

            'vmess' => array_filter([
                'users' => null, // injected by the panel user pipeline
                'ws-path' => data_get($s, 'network') === 'ws' ? data_get($s, 'network_settings.path') : null,
                'grpc-service-name' => data_get($s, 'network') === 'grpc' ? data_get($s, 'network_settings.serviceName') : null,
                'mux-option' => self::muxOption($s),
            ], fn ($v) => $v !== null),

            'vless' => array_filter([
                'decryption' => data_get($s, 'encryption.enabled') ? data_get($s, 'encryption.decryption') : null,
                'ws-path' => data_get($s, 'network') === 'ws' ? data_get($s, 'network_settings.path') : null,
                'grpc-service-name' => data_get($s, 'network') === 'grpc' ? data_get($s, 'network_settings.serviceName') : null,
                'allow-insecure' => (int) data_get($s, 'tls', 0) === 0 ? true : null,
                'xhttp-config' => self::xhttpConfig($s),
                'mux-option' => self::muxOption($s),
            ], fn ($v) => $v !== null),

            'trojan' => array_filter([
                'ws-path' => data_get($s, 'network') === 'ws' ? data_get($s, 'network_settings.path') : null,
                'grpc-service-name' => data_get($s, 'network') === 'grpc' ? data_get($s, 'network_settings.serviceName') : null,
                'allow-insecure' => (int) data_get($s, 'tls', 1) === 0 ? true : null,
                'ss-option' => self::trojanSsOption($s),
                'mux-option' => self::muxOption($s),
            ], fn ($v) => $v !== null),

            'anytls' => array_filter([
                'allow-insecure' => (bool) data_get($s, 'tls.allow_insecure', false) ? true : null,
                'padding-scheme' => data_get($s, 'padding_scheme') ?: null,
            ], fn ($v) => $v !== null),

            'snell' => array_filter([
                'psk' => data_get($s, 'psk') ?: null,
                'version' => (int) data_get($s, 'version', 4) ?: null,
                'udp' => (bool) ($s['udp'] ?? true),
                'obfs-opts' => self::snellObfsOpts($s),
            ], fn ($v) => $v !== null),

            'mieru' => array_filter([
                'transport' => strtoupper((string) data_get($s, 'transport', 'TCP')),
                'traffic-pattern' => data_get($s, 'traffic_pattern') ?: null,
                'user-hint-is-mandatory' => (bool) data_get($s, 'user_hint_is_mandatory', false) ? true : null,
            ], fn ($v) => $v !== null),

            'sudoku' => array_filter([
                'key' => data_get($s, 'key') ?: null,
                'aead-method' => data_get($s, 'aead_method') ?: null,
                'padding-min' => data_get($s, 'padding_min'),
                'padding-max' => data_get($s, 'padding_max'),
                'table-type' => data_get($s, 'table_type') ?: null,
                'custom-table' => data_get($s, 'custom_table') ?: null,
                'custom-tables' => data_get($s, 'custom_tables') ?: null,
                'handshake-timeout' => data_get($s, 'handshake_timeout'),
                'enable-pure-downlink' => (bool) data_get($s, 'enable_pure_downlink', false) ? true : null,
                'httpmask' => self::httpMask($s),
                'mux-option' => self::muxOption($s),
            ], fn ($v) => $v !== null),

            'tuic' => array_filter([
                'congestion-controller' => data_get($s, 'congestion_controller')
                    ?: data_get($s, 'congestion_control')
                    ?: 'bbr',
                'max-idle-time' => data_get($s, 'max_idle_time'),
                'authentication-timeout' => data_get($s, 'authentication_timeout'),
                'alpn' => data_get($s, 'alpn') ?: null,
                'max-udp-relay-packet-size' => data_get($s, 'max_udp_relay_packet_size'),
                'bbr-profile' => data_get($s, 'bbr_profile') ?: null,
                'mux-option' => self::muxOption($s),
            ], fn ($v) => $v !== null),

            'hysteria2' => array_filter([
                'up' => data_get($s, 'up') ?? data_get($s, 'bandwidth.up'),
                'down' => data_get($s, 'down') ?? data_get($s, 'bandwidth.down'),
                'ignore-client-bandwidth' => (bool) data_get($s, 'ignore_client_bandwidth', false) ? true : null,
                'obfs' => data_get($s, 'obfs.open') ? 'salamander' : null,
                'obfs-password' => data_get($s, 'obfs.password') ?: data_get($s, 'obfs_password'),
                'masquerade' => data_get($s, 'masquerade') ?: null,
                'bbr-profile' => data_get($s, 'bbr_profile') ?: null,
                'mux-option' => self::muxOption($s),
            ], fn ($v) => $v !== null),

            'shadowquic' => array_filter([
                'jls-upstream' => self::jlsUpstream($s),
                'alpn' => data_get($s, 'alpn') ?: null,
                'quic-versions' => data_get($s, 'quic_versions') ?: null,
                'zero-rtt' => array_key_exists('zero_rtt', $s) ? (bool) $s['zero_rtt'] : null,
                'congestion-controller' => data_get($s, 'congestion_controller') ?: 'bbr',
                'up' => data_get($s, 'up'),
                'down' => data_get($s, 'down'),
                'ignore-client-bandwidth' => (bool) data_get($s, 'ignore_client_bandwidth', false) ? true : null,
                'cwnd' => data_get($s, 'cwnd'),
                'bbr-profile' => data_get($s, 'bbr_profile') ?: null,
                'max-idle-time' => data_get($s, 'max_idle_time'),
                'max-datagram-frame-size' => data_get($s, 'max_datagram_frame_size'),
                'disable-mtu-discovery' => (bool) data_get($s, 'disable_mtu_discovery', false) ? true : null,
                'mux-option' => self::muxOption($s),
            ], fn ($v) => $v !== null),

            'trusttunnel' => array_filter([
                'network' => data_get($s, 'network') ?: null,
                'congestion-controller' => data_get($s, 'congestion_controller') ?: null,
                'bbr-profile' => data_get($s, 'bbr_profile') ?: null,
            ], fn ($v) => $v !== null),

            default => [],
        };
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function shadowsocksOptions(array $s): array
    {
        $options = array_filter([
            'cipher' => data_get($s, 'cipher') ?: null,
            'password' => data_get($s, 'password') ?: null,
            'udp' => (bool) ($s['udp'] ?? true),
            'mux-option' => self::muxOption($s),
        ], fn ($v) => $v !== null && $v !== '');

        if (!empty($s['simple_obfs']['enable'])) {
            $options['simple-obfs'] = array_filter([
                'enable' => true,
                'mode' => data_get($s, 'simple_obfs.mode', 'http'),
            ], fn ($v) => $v !== null);
        } elseif (data_get($s, 'obfs') === 'http') {
            // Legacy Xray-style simple-obfs settings.
            $options['simple-obfs'] = [
                'enable' => true,
                'mode' => 'http',
            ];
        }

        $legacy = self::legacyPlugin($s);
        if ($legacy !== null) {
            $options = array_merge($options, $legacy);
        }

        return $options;
    }

    /**
     * Translate the legacy shadowsocks plugin/plugin_opts pair into the native
     * mihomo obfuscation blocks mihomo actually understands.
     *
     * @param array<string, mixed> $s
     * @return array<string, mixed>|null
     */
    private static function legacyPlugin(array $s): ?array
    {
        $plugin = (string) data_get($s, 'plugin', '');
        $opts = self::parsePluginOpts((string) data_get($s, 'plugin_opts', ''));

        return match ($plugin) {
            'obfs', 'obfs-local' => ['simple-obfs' => [
                'enable' => true,
                'mode' => in_array($opts['obfs'] ?? $opts['mode'] ?? 'http', ['tls', 'http'], true)
                    ? ($opts['obfs'] ?? $opts['mode'] ?? 'http')
                    : 'http',
            ]],
            'shadow-tls' => isset($opts['password']) ? ['shadow-tls' => array_filter([
                'enable' => true,
                'version' => isset($opts['version']) ? (int) $opts['version'] : 2,
                'password' => $opts['password'],
                'handshake' => isset($opts['host'])
                    ? ['dest' => $opts['host'] . ':' . ($opts['port'] ?? 443)]
                    : null,
            ], fn ($v) => $v !== null)] : null,
            // res-tls requires enable + dest + password; skip rather than emit
            // a block mihomo would reject as a fatal parse error.
            'restls' => isset($opts['host'], $opts['password']) ? ['res-tls' => array_filter([
                'enable' => true,
                'dest' => $opts['host'],
                'password' => $opts['password'],
                'restls-script' => $opts['restls-script'] ?? null,
            ], fn ($v) => $v !== null)] : null,
            // jls-config requires enable + dest + a non-empty users list.
            'jls' => isset($opts['host'], $opts['username'], $opts['password']) ? ['jls-config' => array_filter([
                'enable' => true,
                'dest' => $opts['host'],
                'users' => [['username' => $opts['username'], 'password' => $opts['password']]],
                'sni' => $opts['sni'] ?? null,
                'alpn' => isset($opts['alpn']) ? array_map('trim', explode(',', (string) $opts['alpn'])) : null,
                'rate-limit' => isset($opts['rate-limit']) ? (int) $opts['rate-limit'] : null,
            ], fn ($v) => $v !== null && $v !== '')] : null,
            'kcptun' => ['kcp-tun' => array_filter([
                'enable' => true,
                'key' => $opts['key'] ?? null,
                'crypt' => $opts['crypt'] ?? null,
                'mode' => $opts['mode'] ?? null,
                'mtu' => isset($opts['mtu']) ? (int) $opts['mtu'] : null,
                'sndwnd' => isset($opts['sndwnd']) ? (int) $opts['sndwnd'] : null,
                'rcvwnd' => isset($opts['rcvwnd']) ? (int) $opts['rcvwnd'] : null,
                'datashard' => isset($opts['datashard']) ? (int) $opts['datashard'] : null,
                'parityshard' => isset($opts['parityshard']) ? (int) $opts['parityshard'] : null,
                'nocomp' => isset($opts['nocomp']) ? (bool) $opts['nocomp'] : null,
            ], fn ($v) => $v !== null && $v !== '')],
            default => null,
        };
    }

    /**
     * @return array<string, string|bool>
     */
    public static function parsePluginOpts(string $raw): array
    {
        $result = [];
        foreach (explode(';', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            if (!str_contains($pair, '=')) {
                $result[$pair] = true;
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $result[trim($key)] = trim($value);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>|null
     */
    private static function muxOption(array $s): ?array
    {
        $mux = $s['mux_option'] ?? null;
        if (!is_array($mux)) {
            return null;
        }

        $option = array_filter([
            'padding' => !empty($mux['padding']) ? true : null,
        ], fn ($v) => $v !== null);

        if (!empty($mux['brutal']['enabled'])) {
            $option['brutal'] = array_filter([
                'enabled' => true,
                'up' => data_get($mux, 'brutal.up'),
                'down' => data_get($mux, 'brutal.down'),
            ], fn ($v) => $v !== null);
        }

        return $option === [] ? null : $option;
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>|null
     */
    private static function xhttpConfig(array $s): ?array
    {
        if (data_get($s, 'network') !== 'xhttp') {
            return null;
        }

        $settings = data_get($s, 'network_settings', []);

        return array_filter([
            'path' => $settings['path'] ?? null,
            'host' => $settings['host'] ?? null,
            'mode' => $settings['mode'] ?? null,
            'headers' => $settings['headers'] ?? null,
            'no-sse-header' => $settings['no_sse_header'] ?? null,
            'x-padding-bytes' => $settings['x_padding_bytes'] ?? null,
            'x-padding-obfs-mode' => $settings['x_padding_obfs_mode'] ?? null,
            'x-padding-key' => $settings['x_padding_key'] ?? null,
            'x-padding-header' => $settings['x_padding_header'] ?? null,
            'x-padding-placement' => $settings['x_padding_placement'] ?? null,
            'x-padding-method' => $settings['x_padding_method'] ?? null,
            'uplink-http-method' => $settings['uplink_http_method'] ?? null,
            'session-placement' => $settings['session_placement'] ?? null,
            'session-key' => $settings['session_key'] ?? null,
            'session-table' => $settings['session_table'] ?? null,
            'session-length' => $settings['session_length'] ?? null,
            'seq-placement' => $settings['seq_placement'] ?? null,
            'seq-key' => $settings['seq_key'] ?? null,
            'uplink-data-placement' => $settings['uplink_data_placement'] ?? null,
            'uplink-data-key' => $settings['uplink_data_key'] ?? null,
            'uplink-chunk-size' => $settings['uplink_chunk_size'] ?? null,
            'sc-max-buffered-posts' => $settings['sc_max_buffered_posts'] ?? null,
            'sc-stream-up-server-secs' => $settings['sc_stream_up_server_secs'] ?? null,
            'sc-max-each-post-bytes' => $settings['sc_max_each_post_bytes'] ?? null,
        ], fn ($v) => $v !== null && $v !== '') ?: null;
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>|null
     */
    private static function trojanSsOption(array $s): ?array
    {
        if (empty($s['ss_option']['enabled'])) {
            return null;
        }

        return array_filter([
            'enabled' => true,
            'method' => data_get($s, 'ss_option.method', 'aes-128-gcm'),
            'password' => data_get($s, 'ss_option.password'),
        ], fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>|null
     */
    private static function snellObfsOpts(array $s): ?array
    {
        $opts = $s['obfs_opts'] ?? null;
        if (!is_array($opts) || empty($opts['mode'])) {
            return null;
        }

        return array_filter([
            'mode' => $opts['mode'],
            'host' => $opts['host'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>|null
     */
    private static function httpMask(array $s): ?array
    {
        $mask = $s['httpmask'] ?? null;
        if (!is_array($mask)) {
            return null;
        }

        return array_filter([
            'disable' => array_key_exists('disable', $mask) ? (bool) $mask['disable'] : null,
            'mode' => $mask['mode'] ?? null,
            'path-root' => $mask['path_root'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>|null
     */
    private static function jlsUpstream(array $s): ?array
    {
        $upstream = $s['jls_upstream'] ?? null;
        if (!is_array($upstream) || empty($upstream['addr'])) {
            return null;
        }

        return array_filter([
            'addr' => $upstream['addr'],
            'sni' => $upstream['sni'] ?? null,
            'proxy' => $upstream['proxy'] ?? null,
            'rate-limit' => $upstream['rate_limit'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param array<string, mixed> $s
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function tlsOptions(string $type, array $s, array $node): array
    {
        $options = [];
        $needsTls = Mihomo::requiresTls($type) || Mihomo::supportsTls($type);

        if ($needsTls) {
            $material = self::certificateMaterial($node);
            if ($material !== null) {
                $options['certificate'] = $material['certificate'];
                $options['private-key'] = $material['private_key'];
            }
        }

        // Listener-root mTLS / ECH options.
        foreach ([
            'client-auth-type' => $s['client_auth_type'] ?? null,
            'client-auth-cert' => $s['client_auth_cert'] ?? null,
            'ech-key' => $s['ech_key'] ?? null,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * Resolve the node certificate into inline PEM or file paths. ACME-managed
     * certificates (self/http/dns) are provisioned on the node itself, so the
     * panel leaves them to the agent and only forwards cert_config.
     *
     * @param array<string, mixed> $node
     * @return array{certificate: string, private_key: string}|null
     */
    public static function certificateMaterial(array $node): ?array
    {
        $certConfig = $node['cert_config'] ?? null;
        if (!is_array($certConfig) || $certConfig === []) {
            return null;
        }

        $mode = $certConfig['cert_mode'] ?? $certConfig['mode'] ?? 'none';

        if ($mode === 'content'
            && !empty($certConfig['cert_content'])
            && !empty($certConfig['key_content'])) {
            return [
                'certificate' => (string) $certConfig['cert_content'],
                'private_key' => (string) $certConfig['key_content'],
            ];
        }

        if ($mode === 'file'
            && !empty($certConfig['cert_file'])
            && !empty($certConfig['key_file'])) {
            return [
                'certificate' => (string) $certConfig['cert_file'],
                'private_key' => (string) $certConfig['key_file'],
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function realityOptions(string $type, array $s): array
    {
        if (!Mihomo::supportsReality($type)) {
            return [];
        }

        // Explicit override block wins when enabled.
        if (!empty($s['reality_config']['enabled'])) {
            $explicit = array_filter([
                'dest' => data_get($s, 'reality_config.dest'),
                'private-key' => data_get($s, 'reality_config.private_key'),
                'short-id' => data_get($s, 'reality_config.short_id') ?: null,
                'server-names' => data_get($s, 'reality_config.server_names') ?: null,
                'max-time-difference' => data_get($s, 'reality_config.max_time_difference'),
                'proxy' => data_get($s, 'reality_config.proxy'),
            ], fn ($v) => $v !== null && $v !== '');

            return $explicit === [] ? [] : ['reality-config' => $explicit];
        }

        // REALITY is only active when the node TLS mode is 2 (vmess/vless/trojan)
        // or when reality_settings carry a private key.
        $privateKey = data_get($s, 'reality_settings.private_key');
        if (empty($privateKey)) {
            return [];
        }
        if (in_array($type, ['vmess', 'vless', 'trojan'], true) && (int) data_get($s, 'tls', 0) !== 2) {
            return [];
        }

        $serverName = (string) data_get($s, 'reality_settings.server_name', '');
        $serverPort = (int) data_get($s, 'reality_settings.server_port', 443);
        $shortId = (string) data_get($s, 'reality_settings.short_id', '');

        $config = array_filter([
            'dest' => $serverName !== '' ? $serverName . ':' . $serverPort : null,
            'private-key' => $privateKey,
            'short-id' => $shortId !== '' ? [$shortId] : null,
            'server-names' => $serverName !== '' ? [$serverName] : null,
        ], fn ($v) => $v !== null);

        return $config === [] ? [] : ['reality-config' => $config];
    }

    /**
     * Drop reserved keys an operator must not smuggle through the override.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function filterRawOverrides(array $raw): array
    {
        unset($raw['type'], $raw['name']);
        // `port` stays overridable so port ranges keep working, but `listen`
        // and `type` are structural.
        return $raw;
    }

    /**
     * Shape panel credentials into the `users` container mihomo expects for a
     * given inbound type.
     *
     * mihomo uses four different shapes and getting this wrong is the classic
     * generator bug, so the mapping lives next to the listener builder:
     *  - map                 => { username: password }        (anytls, hysteria2, tuic, mieru)
     *  - array_user_password => [{ username, password }]      (http, socks, mixed, trusttunnel, shadowquic)
     *  - array_uuid          => [{ username, uuid, ... }]     (vmess, vless)
     *  - array_password      => [{ username, password }]      (trojan)
     *
     * @param list<array{username?: string, credential?: string, user_id?: int|string, flow?: string, alter_id?: int}> $rows
     * @return array<int|string, mixed>|null
     */
    public static function shapeUsers(string $type, array $rows): ?array
    {
        $type = Mihomo::normalizeType($type) ?? $type;
        $shape = Mihomo::userShape($type);

        if ($shape === null || $rows === []) {
            return null;
        }

        $users = [];
        foreach ($rows as $index => $row) {
            $credential = (string) ($row['credential'] ?? '');
            if ($credential === '') {
                continue;
            }
            $username = (string) ($row['username'] ?? '') ?: 'user-' . ($row['user_id'] ?? $index);

            switch ($shape) {
                case 'map':
                    if ($type === 'tuic') {
                        // TUIC v5 map keys are UUIDs and must match the client
                        // uuid, which is the credential itself.
                        $users[$credential] = $credential;
                    } else {
                        // hysteria2 usernames never authenticate; they only feed
                        // in-user rules, but they must still be unique.
                        $users[$username] = $credential;
                    }
                    break;
                case 'array_user_password':
                    $users[] = ['username' => $username, 'password' => $credential];
                    break;
                case 'array_uuid':
                    $entry = ['username' => $username, 'uuid' => $credential];
                    if ($type === 'vmess') {
                        $entry['alterId'] = (int) ($row['alter_id'] ?? 0);
                    }
                    if ($type === 'vless' && !empty($row['flow'])) {
                        $entry['flow'] = (string) $row['flow'];
                    }
                    $users[] = $entry;
                    break;
                case 'array_password':
                    $users[] = ['username' => $username, 'password' => $credential];
                    break;
                case 'array_name_password':
                    $users[] = ['name' => $username, 'password' => $credential];
                    break;
            }
        }

        return $users === [] ? null : $users;
    }

    /**
     * Attach shaped users to a listener, unless the inbound authenticates with
     * a single credential (shadowsocks, snell, tunnel, capture inbounds).
     *
     * @param array<string, mixed> $listener
     * @param list<array{username?: string, credential?: string, user_id?: int|string}> $rows
     * @return array<string, mixed>
     */
    public static function withUsers(array $listener, string $type, array $rows): array
    {
        $users = self::shapeUsers($type, $rows);
        if ($users !== null) {
            $listener['users'] = $users;
        }

        return $listener;
    }
}
