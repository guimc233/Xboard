<?php

namespace App\Protocols;

use App\Models\Server;
use App\Support\AbstractProtocol;
use App\Support\Mihomo;
use App\Utils\Helper;
use Symfony\Component\Yaml\Yaml;

/**
 * The single subscription handler for this build.
 *
 * mihomo is the only kernel, so every subscription — regardless of the client
 * that requested it — is rendered as a Clash/mihomo YAML profile. The builders
 * below emit exactly the keys mihomo accepts, including the per-protocol SNI
 * naming difference (`servername` for VMess/VLESS, `sni` everywhere else).
 *
 * @see docs/en/kernel/mihomo-proxies.md
 */
class ClashMeta extends AbstractProtocol
{
    public $flags = [
        'clash',
        'clashmeta',
        'clash-verge',
        'clashverge',
        'clash.meta',
        'meta',
        'verge',
        'flclash',
        'nekobox',
        'nekoray',
        'clashmetaforandroid',
        'stash',
        'surge',
        'loon',
        'quantumult%20x',
        'quantumult-x',
        'shadowrocket',
        'surfboard',
        'sing-box',
        'singbox',
        'hiddify',
        'sfm',
        'general',
        'v2rayn',
        'v2rayng',
        'passwall',
        'ssrplus',
        'sagernet',
        'shadowsocks',
    ];

    const CUSTOM_TEMPLATE_FILE = 'resources/rules/custom.clashmeta.yaml';
    const CUSTOM_CLASH_TEMPLATE_FILE = 'resources/rules/custom.clash.yaml';
    const DEFAULT_TEMPLATE_FILE = 'resources/rules/default.clash.yaml';

    public $allowedProtocols = Server::SUBSCRIPTION_TYPES;

    protected $protocolRequirements = [
        '*.vless.protocol_settings.network' => [
            'whitelist' => [
                'tcp' => '0.0.0',
                'ws' => '0.0.0',
                'grpc' => '0.0.0',
                'http' => '0.0.0',
                'h2' => '0.0.0',
                'httpupgrade' => '0.0.0',
                'xhttp' => '0.0.0',
            ],
            'strict' => true,
        ],
        '*.vmess.protocol_settings.network' => [
            'whitelist' => [
                'tcp' => '0.0.0',
                'ws' => '0.0.0',
                'grpc' => '0.0.0',
                'http' => '0.0.0',
                'h2' => '0.0.0',
                'httpupgrade' => '0.0.0',
            ],
            'strict' => true,
        ],
        '*.trojan.protocol_settings.network' => [
            'whitelist' => [
                'tcp' => '0.0.0',
                'ws' => '0.0.0',
                'grpc' => '0.0.0',
                'httpupgrade' => '0.0.0',
            ],
            'strict' => true,
        ],
        '*.hysteria2.protocol_settings.version' => [
            2 => '1.2.7',
        ],
        'meta.vmess.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'meta.vless.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'meta.trojan.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'meta.anytls.protocol_settings.tls.ech.enabled' => [1 => '1.19.9'],
        'verge.vmess.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'verge.vless.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'verge.trojan.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'verge.anytls.protocol_settings.tls.ech.enabled' => [1 => '1.19.9'],
        'flclash.vmess.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'flclash.vless.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'flclash.trojan.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'flclash.anytls.protocol_settings.tls.ech.enabled' => [1 => '1.19.9'],
        'nekobox.vmess.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'nekobox.vless.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'nekobox.trojan.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'nekobox.anytls.protocol_settings.tls.ech.enabled' => [1 => '1.19.9'],
        'clashmetaforandroid.vmess.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'clashmetaforandroid.vless.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'clashmetaforandroid.trojan.protocol_settings.tls_settings.ech.enabled' => [1 => '1.19.9'],
        'clashmetaforandroid.anytls.protocol_settings.tls.ech.enabled' => [1 => '1.19.9'],
    ];

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $appName = admin_setting('app_name', 'XBoard');

        $template = subscribe_template('clashmeta')
            ?: subscribe_template('clash')
            ?: file_get_contents(base_path(self::DEFAULT_TEMPLATE_FILE));

        $config = Yaml::parse($template);
        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            $entry = self::buildProxy((string) $item['password'], $item);
            if ($entry === null) {
                continue;
            }
            array_push($proxy, $entry);
            array_push($proxies, $item['name']);
        }

        $config['proxies'] = array_merge($config['proxies'] ?: [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies']))
                $config['proxy-groups'][$k]['proxies'] = [];
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src))
                        continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(array_diff($config['proxy-groups'][$k]['proxies'], [$src]));
                    if ($this->isMatch($src, $dst)) {
                        array_push($config['proxy-groups'][$k]['proxies'], $dst);
                    }
                }
                if ($isFilter)
                    continue;
            }
            if ($isFilter)
                continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $config['proxy-groups'] = array_filter($config['proxy-groups'], function ($group) {
            return $group['proxies'];
        });
        $config['proxy-groups'] = array_values($config['proxy-groups']);
        $config = $this->buildRules($config);

        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = str_replace('$app_name', admin_setting('app_name', 'XBoard'), $yaml);
        return response($yaml)
            ->header('content-type', 'text/yaml')
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}")
            ->header('profile-update-interval', '24')
            ->header('content-disposition', 'attachment;filename*=UTF-8\'\'' . rawurlencode($appName));
    }

    /**
     * Build the rules for Clash.
     */
    public function buildRules($config)
    {
        // Force the current subscription domain to be a direct rule
        $subsDomain = request()->header('Host');
        if ($subsDomain) {
            array_unshift($config['rules'], "DOMAIN,{$subsDomain},DIRECT");
        }

        return $config;
    }

    /**
     * Dispatch a panel node to the matching mihomo proxy builder.
     *
     * @param string $credential Per-user credential produced by UserService.
     * @param array<string, mixed> $server
     * @return array<string, mixed>|null
     */
    public static function buildProxy(string $credential, array $server): ?array
    {
        $type = Mihomo::normalizeType($server['type'] ?? null);

        return match ($type) {
            'shadowsocks' => self::buildShadowsocks($credential, $server),
            'vmess' => self::buildVmess($credential, $server),
            'vless' => self::buildVless($credential, $server),
            'trojan' => self::buildTrojan($credential, $server),
            'anytls' => self::buildAnyTLS($credential, $server),
            'snell' => self::buildSnell($credential, $server),
            'mieru' => self::buildMieru($credential, $server),
            'sudoku' => self::buildSudoku($credential, $server),
            'tuic' => self::buildTuic($credential, $server),
            'hysteria' => self::buildHysteria($credential, $server, []),
            'hysteria2' => self::buildHysteria($credential, $server, []),
            'shadowquic' => self::buildShadowQuic($credential, $server),
            'trusttunnel' => self::buildTrustTunnel($credential, $server),
            'http' => self::buildHttp($credential, $server),
            'socks', 'mixed' => self::buildSocks5($credential, $server),
            default => null,
        };
    }

    // -----------------------------------------------------------------
    // Shadowsocks
    // -----------------------------------------------------------------

    public static function buildShadowsocks($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'ss';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['cipher'] = data_get($protocol_settings, 'cipher');
        // mihomo's Shadowsocks inbound authenticates with one shared secret, so
        // the subscription must present the server PSK, not a per-user key.
        $array['password'] = self::shadowsocksSecret($server, $password);
        $array['udp'] = true;

        if (data_get($protocol_settings, 'plugin') && data_get($protocol_settings, 'plugin_opts')) {
            $plugin = data_get($protocol_settings, 'plugin');
            $pluginOpts = data_get($protocol_settings, 'plugin_opts', '');
            $array['plugin'] = $plugin;

            $parsedOpts = self::parsePluginOpts((string) $pluginOpts);

            switch ($plugin) {
                case 'obfs':
                case 'obfs-local':
                    $array['plugin'] = 'obfs';
                    $array['plugin-opts'] = array_filter([
                        'mode' => $parsedOpts['obfs'] ?? ($parsedOpts['mode'] ?? 'http'),
                        'host' => $parsedOpts['obfs-host'] ?? ($parsedOpts['host'] ?? 'www.bing.com'),
                    ]);
                    break;

                case 'v2ray-plugin':
                    $array['plugin-opts'] = array_filter([
                        'mode' => $parsedOpts['mode'] ?? 'websocket',
                        'tls' => isset($parsedOpts['tls']) || isset($parsedOpts['server']),
                        'host' => $parsedOpts['host'] ?? null,
                        'path' => $parsedOpts['path'] ?? '/',
                        'mux' => isset($parsedOpts['mux']) ? true : null,
                        'headers' => isset($parsedOpts['host']) ? ['Host' => $parsedOpts['host']] : null
                    ], fn($v) => $v !== null);
                    break;

                case 'shadow-tls':
                    $array['plugin-opts'] = array_filter([
                        'host' => $parsedOpts['host'] ?? null,
                        'password' => $parsedOpts['password'] ?? null,
                        'version' => isset($parsedOpts['version']) ? (int) $parsedOpts['version'] : 2
                    ], fn($v) => $v !== null);
                    break;

                case 'restls':
                    $array['plugin-opts'] = array_filter([
                        'host' => $parsedOpts['host'] ?? null,
                        'password' => $parsedOpts['password'] ?? null,
                        'restls-script' => $parsedOpts['restls-script'] ?? '123'
                    ], fn($v) => $v !== null);
                    break;

                case 'jls':
                    $array['plugin'] = 'jls';
                    $array['plugin-opts'] = array_filter([
                        'host' => $parsedOpts['host'] ?? null,
                        'username' => $parsedOpts['username'] ?? null,
                        'password' => $parsedOpts['password'] ?? null,
                        'alpn' => isset($parsedOpts['alpn'])
                            ? array_map('trim', explode(',', (string) $parsedOpts['alpn']))
                            : null,
                    ], fn($v) => $v !== null);
                    break;

                case 'kcptun':
                    $array['plugin'] = 'kcptun';
                    $array['plugin-opts'] = array_filter([
                        'key' => $parsedOpts['key'] ?? null,
                        'crypt' => $parsedOpts['crypt'] ?? null,
                        'mode' => $parsedOpts['mode'] ?? null,
                        'mtu' => isset($parsedOpts['mtu']) ? (int) $parsedOpts['mtu'] : null,
                        'sndwnd' => isset($parsedOpts['sndwnd']) ? (int) $parsedOpts['sndwnd'] : null,
                        'rcvwnd' => isset($parsedOpts['rcvwnd']) ? (int) $parsedOpts['rcvwnd'] : null,
                        'datashard' => isset($parsedOpts['datashard']) ? (int) $parsedOpts['datashard'] : null,
                        'parityshard' => isset($parsedOpts['parityshard']) ? (int) $parsedOpts['parityshard'] : null,
                        'nocomp' => isset($parsedOpts['nocomp']) ? (bool) $parsedOpts['nocomp'] : null,
                    ], fn($v) => $v !== null);
                    break;

                default:
                    $array['plugin-opts'] = $parsedOpts;
            }
        }

        return $array;
    }

    /**
     * The shared Shadowsocks secret mihomo's listener is configured with.
     *
     * @param array<string, mixed> $server
     */
    public static function shadowsocksSecret(array $server, string $fallback): string
    {
        $explicit = data_get($server, 'protocol_settings.password');
        if (filled($explicit)) {
            return (string) $explicit;
        }

        $cipher = (string) data_get($server, 'protocol_settings.cipher', '');
        $size = Mihomo::SHADOWSOCKS_CIPHERS[$cipher] ?? null;

        if ($size !== null && isset($server['created_at'])) {
            return Helper::getServerKey($server['created_at'], $size);
        }

        return $fallback;
    }

    // -----------------------------------------------------------------
    // VMess / VLESS
    // -----------------------------------------------------------------

    public static function buildVmess($uuid, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'vmess',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $uuid,
            'alterId' => 0,
            'cipher' => 'auto',
            'udp' => true,
        ];

        if (data_get($protocol_settings, 'tls')) {
            $array['tls'] = true;
            $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
            // VMess/VLESS use `servername`; `sni` is silently ignored there.
            $array['servername'] = data_get($protocol_settings, 'tls_settings.server_name');
            if ($alpn = data_get($protocol_settings, 'tls_settings.alpn')) {
                $array['alpn'] = $alpn;
            }
            self::appendEch($array, data_get($protocol_settings, 'tls_settings.ech'));
        }

        self::appendUtls($array, $protocol_settings);
        self::appendMultiplex($array, $protocol_settings);
        self::appendTransport($array, $protocol_settings);

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function buildVless($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'vless',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $password,
            'udp' => true,
            'flow' => data_get($protocol_settings, 'flow'),
            'tls' => false,
        ];

        switch ((int) data_get($protocol_settings, 'tls', 0)) {
            case 1:
                $array['tls'] = true;
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $array['servername'] = $serverName;
                }
                if ($alpn = data_get($protocol_settings, 'tls_settings.alpn')) {
                    $array['alpn'] = $alpn;
                }
                self::appendEch($array, data_get($protocol_settings, 'tls_settings.ech'));
                self::appendUtls($array, $protocol_settings);
                break;
            case 2:
                $array['tls'] = true;
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'reality_settings.allow_insecure', false);
                $array['servername'] = data_get($protocol_settings, 'reality_settings.server_name');
                $array['reality-opts'] = array_filter([
                    'public-key' => data_get($protocol_settings, 'reality_settings.public_key'),
                    'short-id' => data_get($protocol_settings, 'reality_settings.short_id'),
                ], fn ($v) => $v !== null && $v !== '');
                self::appendUtls($array, $protocol_settings);
                break;
            default:
                break;
        }

        self::appendTransport($array, $protocol_settings);
        self::appendMultiplex($array, $protocol_settings);

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function buildTrojan($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'trojan',
            'server' => $server['host'],
            'port' => $server['port'],
            'password' => $password,
            'udp' => true,
        ];

        // Trojan is always TLS and uses `sni` (never `servername`).
        switch ((int) data_get($protocol_settings, 'tls', 1)) {
            case 2:
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'reality_settings.allow_insecure', false);
                if ($serverName = data_get($protocol_settings, 'reality_settings.server_name')) {
                    $array['sni'] = $serverName;
                }
                $array['reality-opts'] = array_filter([
                    'public-key' => data_get($protocol_settings, 'reality_settings.public_key'),
                    'short-id' => data_get($protocol_settings, 'reality_settings.short_id'),
                ], fn ($v) => $v !== null && $v !== '');
                break;
            default:
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $array['sni'] = $serverName;
                }
                if ($alpn = data_get($protocol_settings, 'tls_settings.alpn')) {
                    $array['alpn'] = $alpn;
                }
                self::appendEch($array, data_get($protocol_settings, 'tls_settings.ech'));
                break;
        }

        if (!empty($protocol_settings['ss_option']['enabled'])) {
            $array['ss-opts'] = array_filter([
                'enabled' => true,
                'method' => data_get($protocol_settings, 'ss_option.method'),
                'password' => data_get($protocol_settings, 'ss_option.password'),
            ], fn ($v) => $v !== null);
        }

        self::appendUtls($array, $protocol_settings);
        self::appendMultiplex($array, $protocol_settings);
        self::appendTransport($array, $protocol_settings);

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    // -----------------------------------------------------------------
    // AnyTLS / Snell / Mieru / Sudoku
    // -----------------------------------------------------------------

    public static function buildAnyTLS($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'anytls',
            'server' => $server['host'],
            'port' => $server['port'],
            'password' => $password,
            'udp' => true,
        ];

        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $array['sni'] = $serverName;
        }
        $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls.allow_insecure', false);
        if ($alpn = data_get($protocol_settings, 'tls.alpn')) {
            $array['alpn'] = $alpn;
        }
        if ($padding = data_get($protocol_settings, 'padding_scheme')) {
            $array['padding-scheme'] = $padding;
        }
        self::appendEch($array, data_get($protocol_settings, 'tls.ech'));
        self::appendUtls($array, $protocol_settings);

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function buildSnell($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'snell',
            'server' => $server['host'],
            'port' => $server['port'],
            'psk' => data_get($protocol_settings, 'psk') ?: $password,
            'version' => (int) data_get($protocol_settings, 'version', 4),
            'udp' => (bool) data_get($protocol_settings, 'udp', true),
        ];

        if ($obfs = data_get($protocol_settings, 'obfs_opts.mode')) {
            $array['obfs-opts'] = array_filter([
                'mode' => $obfs,
                'host' => data_get($protocol_settings, 'obfs_opts.host'),
            ], fn ($v) => $v !== null && $v !== '');
        }

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function buildMieru($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'mieru',
            'server' => $server['host'],
            'username' => $password,
            'password' => $password,
            'transport' => strtoupper((string) data_get($protocol_settings, 'transport', 'TCP')),
            'multiplexing' => (string) data_get($protocol_settings, 'multiplexing', 'MULTIPLEXING_LOW'),
            'handshake-mode' => (string) data_get($protocol_settings, 'handshake_mode', 'HANDSHAKE_STANDARD'),
        ];

        if ($trafficPattern = data_get($protocol_settings, 'traffic_pattern')) {
            $array['traffic-pattern'] = $trafficPattern;
        }

        // mihomo rejects `port` and `port-range` together.
        if (!empty($server['ports'])) {
            $array['port-range'] = $server['ports'];
            unset($array['port']);
        } else {
            $array['port'] = $server['port'];
        }

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function buildSudoku($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'sudoku',
            'server' => $server['host'],
            'port' => $server['port'],
            'key' => data_get($protocol_settings, 'key') ?: $password,
        ];

        foreach ([
            'aead_method' => 'aead-method',
            'padding_min' => 'padding-min',
            'padding_max' => 'padding-max',
            'table_type' => 'table-type',
            'custom_table' => 'custom-table',
            'custom_tables' => 'custom-tables',
            'enable_pure_downlink' => 'enable-pure-downlink',
        ] as $source => $target) {
            $value = data_get($protocol_settings, $source);
            if ($value !== null && $value !== '') {
                $array[$target] = $value;
            }
        }

        if (isset($protocol_settings['httpmask']) && is_array($protocol_settings['httpmask'])) {
            $array['httpmask'] = array_filter([
                'disable' => array_key_exists('disable', $protocol_settings['httpmask'])
                    ? (bool) $protocol_settings['httpmask']['disable']
                    : null,
                'mode' => data_get($protocol_settings, 'httpmask.mode'),
                'path-root' => data_get($protocol_settings, 'httpmask.path_root'),
            ], fn ($v) => $v !== null && $v !== '');
        }

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    // -----------------------------------------------------------------
    // QUIC based protocols
    // -----------------------------------------------------------------

    public static function buildTuic($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'tuic',
            'server' => $server['host'],
            'port' => $server['port'],
            'udp' => true,
        ];

        if ((int) data_get($protocol_settings, 'version', 5) === 4) {
            // TUIC v4 authenticates with a shared token; uuid/password must not
            // be present or mihomo treats it as v5.
            $array['token'] = $password;
            $array['disable-sni'] = true;
        } else {
            $array['uuid'] = $password;
            $array['password'] = $password;
        }

        $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls.allow_insecure', false);
        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $array['sni'] = $serverName;
        }
        if ($alpn = data_get($protocol_settings, 'alpn')) {
            $array['alpn'] = $alpn;
        }

        $array['congestion-controller'] = data_get($protocol_settings, 'congestion_controller')
            ?: data_get($protocol_settings, 'congestion_control', 'bbr');
        $array['udp-relay-mode'] = data_get($protocol_settings, 'udp_relay_mode', 'native');

        foreach ([
            'max_idle_time' => 'heartbeat-interval',
            'max_udp_relay_packet_size' => 'max-udp-relay-packet-size',
            'bbr_profile' => 'bbr-profile',
        ] as $source => $target) {
            $value = data_get($protocol_settings, $source);
            if ($value !== null && $value !== '') {
                $array[$target] = $value;
            }
        }

        self::appendEch($array, data_get($protocol_settings, 'tls.ech'));

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function buildHysteria($password, $server, $user)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $version = (int) data_get($protocol_settings, 'version', 2);

        $up = data_get($protocol_settings, 'up') ?? data_get($protocol_settings, 'bandwidth.up');
        $down = data_get($protocol_settings, 'down') ?? data_get($protocol_settings, 'bandwidth.down');

        if ($version === 1) {
            $array = [
                'name' => $server['name'],
                'type' => 'hysteria',
                'server' => $server['host'],
                'port' => $server['port'],
                'auth-str' => $password,
                'up' => self::bandwidth($up),
                'down' => self::bandwidth($down),
                'protocol' => 'udp',
                'fast-open' => true,
            ];
            if (data_get($protocol_settings, 'obfs.open')) {
                $array['obfs'] = data_get($protocol_settings, 'obfs.password') ?: data_get($protocol_settings, 'obfs_password');
            }
        } else {
            $array = [
                'name' => $server['name'],
                'type' => 'hysteria2',
                'server' => $server['host'],
                'port' => $server['port'],
                'password' => $password,
            ];
            if (data_get($protocol_settings, 'obfs.open')) {
                $array['obfs'] = 'salamander';
                $array['obfs-password'] = data_get($protocol_settings, 'obfs.password')
                    ?: data_get($protocol_settings, 'obfs_password');
            }
            if ($up !== null) {
                $array['up'] = self::bandwidth($up);
            }
            if ($down !== null) {
                $array['down'] = self::bandwidth($down);
            }
        }

        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $array['sni'] = $serverName;
        }
        $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls.allow_insecure', false);
        if ($alpn = data_get($protocol_settings, 'tls.alpn')) {
            $array['alpn'] = $alpn;
        }
        if ($bbrProfile = data_get($protocol_settings, 'bbr_profile')) {
            $array['bbr-profile'] = $bbrProfile;
        }

        // Port hopping: mihomo reads the shared port range from `ports`.
        if (!empty($server['ports'])) {
            $array['ports'] = $server['ports'];
            $array['hop-interval'] = data_get($protocol_settings, 'hop_interval') ?: 30;
        }

        self::appendUtls($array, $protocol_settings);
        self::appendEch($array, data_get($protocol_settings, 'tls.ech'));

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function buildShadowQuic($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'shadowquic',
            'server' => $server['host'],
            'port' => $server['port'],
            'username' => $password,
            'password' => $password,
        ];

        foreach ([
            'sni' => 'sni',
            'alpn' => 'alpn',
            'quic_versions' => 'quic-versions',
            'zero_rtt' => 'zero-rtt',
            'congestion_controller' => 'congestion-controller',
            'cwnd' => 'cwnd',
            'bbr_profile' => 'bbr-profile',
            'max_datagram_frame_size' => 'max-datagram-frame-size',
            'disable_mtu_discovery' => 'disable-mtu-discovery',
        ] as $source => $target) {
            $value = data_get($protocol_settings, $source);
            if ($value !== null && $value !== '') {
                $array[$target] = $value;
            }
        }

        if ($up = data_get($protocol_settings, 'up')) {
            $array['up'] = self::bandwidth($up);
        }
        if ($down = data_get($protocol_settings, 'down')) {
            $array['down'] = self::bandwidth($down);
        }

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function buildTrustTunnel($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'trusttunnel',
            'server' => $server['host'],
            'port' => $server['port'],
            'username' => $password,
            'password' => $password,
        ];

        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $array['sni'] = $serverName;
        }
        $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls.allow_insecure', false);
        if ($network = data_get($protocol_settings, 'network')) {
            $array['network'] = $network;
        }
        if ($congestion = data_get($protocol_settings, 'congestion_controller')) {
            $array['congestion-controller'] = $congestion;
        }

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    // -----------------------------------------------------------------
    // Plaintext proxies
    // -----------------------------------------------------------------

    public static function buildSocks5($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'socks5',
            'server' => $server['host'],
            'port' => $server['port'],
            'udp' => true,
            'username' => $password,
            'password' => $password,
        ];

        if (data_get($protocol_settings, 'tls')) {
            $array['tls'] = true;
            $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
        }

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function buildHttp($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'http',
            'server' => $server['host'],
            'port' => $server['port'],
            'username' => $password,
            'password' => $password,
        ];

        if (data_get($protocol_settings, 'tls')) {
            $array['tls'] = true;
            $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
            if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                $array['sni'] = $serverName;
            }
        }

        return array_filter($array, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /**
     * @return array<string, string|bool>
     */
    private static function parsePluginOpts(string $raw): array
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

    private static function bandwidth(mixed $value): ?string
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }

        return (int) $value . ' Mbps';
    }

    /**
     * Apply the VMess/VLESS/Trojan transport block.
     *
     * @param array<string, mixed> $array
     * @param array<string, mixed> $protocol_settings
     */
    protected static function appendTransport(&$array, $protocol_settings): void
    {
        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                $headerType = data_get($protocol_settings, 'network_settings.header.type', 'none');
                if ($headerType === 'http') {
                    $array['network'] = 'http';
                    if ($httpOpts = array_filter([
                        'method' => data_get($protocol_settings, 'network_settings.header.request.method'),
                        'path' => (array) data_get($protocol_settings, 'network_settings.header.request.path', ['/']),
                        'headers' => data_get($protocol_settings, 'network_settings.header.request.headers'),
                    ], fn ($value) => $value !== null && $value !== '')) {
                        $array['http-opts'] = $httpOpts;
                    }
                } else {
                    $array['network'] = 'tcp';
                }
                break;
            case 'ws':
                $array['network'] = 'ws';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['ws-opts']['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.headers.Host'))
                    $array['ws-opts']['headers'] = ['Host' => $host];
                break;
            case 'grpc':
                $array['network'] = 'grpc';
                if ($serviceName = data_get($protocol_settings, 'network_settings.serviceName'))
                    $array['grpc-opts']['grpc-service-name'] = $serviceName;
                break;
            case 'h2':
                $array['network'] = 'h2';
                $array['h2-opts'] = array_filter([
                    'path' => data_get($protocol_settings, 'network_settings.path'),
                    'host' => ($host = data_get($protocol_settings, 'network_settings.host'))
                        ? (is_array($host) ? $host : [$host])
                        : null,
                ], fn ($value) => $value !== null && $value !== '');
                break;
            case 'httpupgrade':
                // mihomo models HTTP Upgrade as WS with a flag, not a network.
                $array['network'] = 'ws';
                $array['ws-opts'] = ['v2ray-http-upgrade' => true];
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['ws-opts']['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host'))
                    $array['ws-opts']['headers'] = ['Host' => $host];
                break;
            case 'xhttp':
                $array['network'] = 'xhttp';
                $array['xhttp-opts'] = array_filter([
                    'path' => data_get($protocol_settings, 'network_settings.path'),
                    'host' => data_get($protocol_settings, 'network_settings.host'),
                    'mode' => data_get($protocol_settings, 'network_settings.mode'),
                ], fn ($value) => $value !== null && $value !== '');
                break;
            default:
                break;
        }
    }

    private function isMatch($exp, $str)
    {
        try {
            return preg_match($exp, $str) === 1;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isRegex($exp)
    {
        if (empty($exp)) {
            return false;
        }
        try {
            return preg_match($exp, '') !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected static function appendMultiplex(&$array, $protocol_settings)
    {
        if ($multiplex = data_get($protocol_settings, 'multiplex')) {
            if (data_get($multiplex, 'enabled')) {
                $array['smux'] = array_filter([
                    'enabled' => true,
                    'protocol' => data_get($multiplex, 'protocol', 'yamux'),
                    'max-connections' => data_get($multiplex, 'max_connections'),
                    'padding' => data_get($multiplex, 'padding') ? true : null,
                ]);

                if (data_get($multiplex, 'brutal.enabled')) {
                    $array['smux']['brutal-opts'] = [
                        'enabled' => true,
                        'up' => data_get($multiplex, 'brutal.up_mbps'),
                        'down' => data_get($multiplex, 'brutal.down_mbps'),
                    ];
                }
            }
        }
    }

    protected static function appendUtls(&$array, $protocol_settings)
    {
        if ($utls = data_get($protocol_settings, 'utls')) {
            if (data_get($utls, 'enabled')) {
                $array['client-fingerprint'] = Helper::getTlsFingerprint($utls);
            }
        }
    }

    protected static function appendEch(&$array, $ech): void
    {
        if ($normalized = Helper::normalizeEchSettings($ech)) {
            $array['ech-opts'] = array_filter([
                'enable' => true,
                'config' => Helper::toMihomoEchConfig(data_get($normalized, 'config')),
                'query-server-name' => data_get($normalized, 'query_server_name'),
            ], fn($value) => $value !== null);
        }
    }
}
