<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use App\Utils\CacheKey;
use App\Utils\Helper;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Services\NodeSourceStateService;
use App\Services\ChildIdentityService;

/**
 * App\Models\Server
 *
 * @property int $id
 * @property string $name 节点名称
 * @property string $type 服务类型
 * @property string $host 主机地址
 * @property string|int $port 端口
 * @property int|null $server_port 服务器端口
 * @property array|null $group_ids 分组IDs
 * @property array|null $route_ids 路由IDs
 * @property array|null $tags 标签
 * @property boolean $show 是否显示
 * @property string|null $allow_insecure 是否允许不安全
 * @property string|null $network 网络类型
 * @property int|null $parent_id 父节点ID
 * @property float|null $rate 倍率
 * @property boolean $rate_time_enable 是否启用时间范围功能
 * @property array|null $rate_time_ranges 倍率时间范围
 * @property int|null $sort 排序
 * @property array|null $protocol_settings 协议设置
 * @property int $created_at
 * @property int $updated_at
 * 
 * @property-read Server|null $parent 父节点
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StatServer> $stats 节点统计
 * 
 * @property-read int|null $last_check_at 最后检查时间（Unix时间戳）
 * @property-read int|null $last_push_at 最后推送时间（Unix时间戳）
 * @property-read int $online 在线用户数
 * @property-read int $online_conn 在线连接数
 * @property-read array|null $metrics 节点指标指标
 * @property-read int $is_online 是否在线（1在线 0离线）
 * @property-read string $available_status 可用状态描述
 * @property-read string $cache_key 缓存键
 * @property string|null $ports 端口范围
 * @property string|null $password 密码
 * @property int|null $u 上行流量
 * @property int|null $d 下行流量
 * @property int|null $total 总流量
 * @property-read array|null $load_status 负载状态（包含CPU、内存、交换区、磁盘信息）
 * 
 * @property int $transfer_enable 流量上限，0或者null表示不限制
 * @property int $u 当前上传流量
 * @property int $d 当前下载流量
 */
class Server extends Model
{
    // mihomo listener (inbound) types. The legacy Xray/sing-box-only names are
    // kept as constants so older code and migrations still resolve, but only
    // the values listed in VALID_TYPES are accepted for new nodes.
    public const TYPE_HTTP = 'http';
    public const TYPE_SOCKS = 'socks';
    public const TYPE_MIXED = 'mixed';
    public const TYPE_SHADOWSOCKS = 'shadowsocks';
    public const TYPE_VMESS = 'vmess';
    public const TYPE_VLESS = 'vless';
    public const TYPE_TROJAN = 'trojan';
    public const TYPE_ANYTLS = 'anytls';
    public const TYPE_SNELL = 'snell';
    public const TYPE_MIERU = 'mieru';
    public const TYPE_SUDOKU = 'sudoku';
    public const TYPE_TUIC = 'tuic';
    public const TYPE_HYSTERIA2 = 'hysteria2';
    public const TYPE_SHADOWQUIC = 'shadowquic';
    public const TYPE_TRUSTTUNNEL = 'trusttunnel';

    /** @deprecated Retired: mihomo serves Hysteria 2 only. */
    public const TYPE_HYSTERIA = 'hysteria';
    /** @deprecated Retired: NaiveProxy is not a mihomo inbound. */
    public const TYPE_NAIVE = 'naive';

    public const STATUS_OFFLINE = 0;
    public const STATUS_ONLINE_NO_PUSH = 1;
    public const STATUS_ONLINE = 2;

    public const CHECK_INTERVAL = 300; // 5 minutes in seconds

    private const CIPHER_CONFIGURATIONS = [
        '2022-blake3-aes-128-gcm' => [
            'serverKeySize' => 16,
            'userKeySize' => 16,
        ],
        '2022-blake3-aes-256-gcm' => [
            'serverKeySize' => 32,
            'userKeySize' => 32,
        ],
        '2022-blake3-chacha20-poly1305' => [
            'serverKeySize' => 32,
            'userKeySize' => 32,
        ]
    ];

    /**
     * Legacy spellings folded into the canonical mihomo type.
     * @see \App\Support\Mihomo::ALIASES
     */
    public const TYPE_ALIASES = \App\Support\Mihomo::ALIASES;

    /**
     * Every server type the panel accepts: the mihomo listener set.
     */
    public const VALID_TYPES = [
        self::TYPE_HTTP,
        self::TYPE_SOCKS,
        self::TYPE_MIXED,
        self::TYPE_SHADOWSOCKS,
        self::TYPE_VMESS,
        self::TYPE_VLESS,
        self::TYPE_TROJAN,
        self::TYPE_ANYTLS,
        self::TYPE_SNELL,
        self::TYPE_MIERU,
        self::TYPE_SUDOKU,
        self::TYPE_TUIC,
        self::TYPE_HYSTERIA2,
        self::TYPE_SHADOWQUIC,
        self::TYPE_TRUSTTUNNEL,
    ];

    /**
     * Server types that can be rendered as a client proxy in a subscription.
     * Every accepted type is subscription-capable, so this mirrors VALID_TYPES.
     */
    public const SUBSCRIPTION_TYPES = [
        self::TYPE_HTTP,
        self::TYPE_SOCKS,
        self::TYPE_MIXED,
        self::TYPE_SHADOWSOCKS,
        self::TYPE_VMESS,
        self::TYPE_VLESS,
        self::TYPE_TROJAN,
        self::TYPE_ANYTLS,
        self::TYPE_SNELL,
        self::TYPE_MIERU,
        self::TYPE_SUDOKU,
        self::TYPE_TUIC,
        self::TYPE_HYSTERIA2,
        self::TYPE_SHADOWQUIC,
        self::TYPE_TRUSTTUNNEL,
    ];

    protected $table = 'v2_server';

    protected $guarded = ['id'];
    protected $casts = [
        'group_ids' => 'array',
        'route_ids' => 'array',
        'tags' => 'array',
        'protocol_settings' => 'array',
        'custom_outbounds' => 'array',
        'custom_routes' => 'array',
        'cert_config' => 'array',
        'last_check_at' => 'integer',
        'last_push_at' => 'integer',
        'show' => 'boolean',
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'rate_time_ranges' => 'array',
        'rate_time_enable' => 'boolean',
        'transfer_enable' => 'integer',
        'u' => 'integer',
        'd' => 'integer',
        'machine_id' => 'integer',
        'config_version' => 'integer',
    ];

    private const MULTIPLEX_CONFIGURATION = [
        'multiplex' => [
            'type' => 'object',
            'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false],
                'protocol' => ['type' => 'string', 'default' => 'yamux'],
                'max_connections' => ['type' => 'integer', 'default' => null],
                // 'min_streams' => ['type' => 'integer', 'default' => null],
                // 'max_streams' => ['type' => 'integer', 'default' => null],
                'padding' => ['type' => 'boolean', 'default' => false],
                'brutal' => [
                    'type' => 'object',
                    'fields' => [
                        'enabled' => ['type' => 'boolean', 'default' => false],
                        'up_mbps' => ['type' => 'integer', 'default' => null],
                        'down_mbps' => ['type' => 'integer', 'default' => null],
                    ]
                ]
            ]
        ]
    ];

    private const REALITY_CONFIGURATION = [
        'reality_settings' => [
            'type' => 'object',
            'fields' => [
                'server_name' => ['type' => 'string', 'default' => null],
                'server_port' => ['type' => 'string', 'default' => null],
                'public_key' => ['type' => 'string', 'default' => null],
                'private_key' => ['type' => 'string', 'default' => null],
                'short_id' => ['type' => 'string', 'default' => null],
                'allow_insecure' => ['type' => 'boolean', 'default' => false],
            ]
        ]
    ];

    private const UTLS_CONFIGURATION = [
        'utls' => [
            'type' => 'object',
            'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false],
                'fingerprint' => ['type' => 'string', 'default' => 'chrome'],
            ]
        ]
    ];

    private const ECH_CONFIGURATION = [
        'ech' => [
            'type' => 'object',
            'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false],
                'config' => ['type' => 'string', 'default' => null],
                'query_server_name' => ['type' => 'string', 'default' => null],
                'key' => ['type' => 'string', 'default' => null],
                'key_path' => ['type' => 'string', 'default' => null],
                'config_path' => ['type' => 'string', 'default' => null],
            ]
        ]
    ];

    private const TLS_SETTINGS_CONFIGURATION = [
        'type' => 'object',
        'fields' => [
            'server_name' => ['type' => 'string', 'default' => null],
            'allow_insecure' => ['type' => 'boolean', 'default' => false],
            ...self::ECH_CONFIGURATION,
        ]
    ];

    private const TLS_CONFIGURATION = [
        'type' => 'object',
        'fields' => [
            'server_name' => ['type' => 'string', 'default' => null],
            'allow_insecure' => ['type' => 'boolean', 'default' => false],
            ...self::ECH_CONFIGURATION,
        ]
    ];

    /**
     * Listener-root TLS options shared by every TLS-capable inbound. The
     * certificate and key themselves arrive through the node cert_config.
     */
    private const LISTENER_TLS_OPTIONS = [
        'client_auth_type' => ['type' => 'string', 'default' => null],
        'client_auth_cert' => ['type' => 'string', 'default' => null],
        'ech_key' => ['type' => 'string', 'default' => null],
    ];

    /**
     * Optional override for the mihomo `reality-config` block. When empty the
     * builder derives dest/private-key/short-id/server-names from
     * `reality_settings`, which is what the admin UI edits.
     */
    private const REALITY_SERVER_CONFIGURATION = [
        'reality_config' => [
            'type' => 'object',
            'default' => null,
            'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false],
                'dest' => ['type' => 'string', 'default' => null],
                'private_key' => ['type' => 'string', 'default' => null],
                'short_id' => ['type' => 'array', 'default' => []],
                'server_names' => ['type' => 'array', 'default' => []],
                'max_time_difference' => ['type' => 'integer', 'default' => null],
                'proxy' => ['type' => 'string', 'default' => null],
            ],
        ],
    ];

    /** mihomo `mux-option` block. */
    private const MUX_OPTION_CONFIGURATION = [
        'mux_option' => [
            'type' => 'object',
            'default' => null,
            'fields' => [
                'padding' => ['type' => 'boolean', 'default' => false],
                'brutal' => [
                    'type' => 'object',
                    'default' => null,
                    'fields' => [
                        'enabled' => ['type' => 'boolean', 'default' => false],
                        'up' => ['type' => 'integer', 'default' => null],
                        'down' => ['type' => 'integer', 'default' => null],
                    ],
                ],
            ],
        ],
    ];

    /** Per-listener sniffing (rendered into a listener `rule`, see docs). */
    private const SNIFF_CONFIGURATION = [
        'sniff' => ['type' => 'boolean', 'default' => false],
        'sniff_override' => ['type' => 'boolean', 'default' => false],
    ];

    /**
     * Escape hatch: raw mihomo listener keys that are merged last, verbatim.
     * This is how every remaining documented (or newly added) mihomo inbound
     * option stays reachable without a panel release.
     */
    private const LISTENER_OVERRIDE_CONFIGURATION = [
        'listener' => ['type' => 'array', 'default' => null],
    ];

    private const PROTOCOL_CONFIGURATIONS = [
        self::TYPE_HTTP => [
            'tls' => ['type' => 'integer', 'default' => 0],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION,
            ...self::LISTENER_TLS_OPTIONS,
            ...self::SNIFF_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_SOCKS => [
            'tls' => ['type' => 'integer', 'default' => 0],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION,
            'udp' => ['type' => 'boolean', 'default' => true],
            ...self::LISTENER_TLS_OPTIONS,
            ...self::SNIFF_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_MIXED => [
            'tls' => ['type' => 'integer', 'default' => 0],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION,
            'udp' => ['type' => 'boolean', 'default' => true],
            ...self::LISTENER_TLS_OPTIONS,
            ...self::SNIFF_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_SHADOWSOCKS => [
            'cipher' => ['type' => 'string', 'default' => null],
            // mihomo authenticates the inbound with a single shared secret.
            'password' => ['type' => 'string', 'default' => null],
            'udp' => ['type' => 'boolean', 'default' => true],
            'obfs' => ['type' => 'string', 'default' => null],
            'obfs_settings' => ['type' => 'array', 'default' => null],
            'simple_obfs' => [
                'type' => 'object',
                'default' => null,
                'fields' => [
                    'enable' => ['type' => 'boolean', 'default' => false],
                    'mode' => ['type' => 'string', 'default' => 'http'],
                ],
            ],
            'plugin' => ['type' => 'string', 'default' => null],
            'plugin_opts' => ['type' => 'string', 'default' => null],
            ...self::MUX_OPTION_CONFIGURATION,
            ...self::SNIFF_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_VMESS => [
            'tls' => ['type' => 'integer', 'default' => 0],
            'network' => ['type' => 'string', 'default' => null],
            'network_settings' => ['type' => 'array', 'default' => null],
            'rules' => ['type' => 'array', 'default' => null],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION,
            ...self::REALITY_CONFIGURATION,
            ...self::REALITY_SERVER_CONFIGURATION,
            ...self::MULTIPLEX_CONFIGURATION,
            ...self::MUX_OPTION_CONFIGURATION,
            ...self::UTLS_CONFIGURATION,
            ...self::LISTENER_TLS_OPTIONS,
            ...self::SNIFF_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_VLESS => [
            'tls' => ['type' => 'integer', 'default' => 0],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION,
            'flow' => ['type' => 'string', 'default' => null],
            'encryption' => [
                'type' => 'object',
                'default' => null,
                'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false],
                    'encryption' => ['type' => 'string', 'default' => null],  // 客户端公钥
                    'decryption' => ['type' => 'string', 'default' => null],   // 服务端私钥
                ]
            ],
            'network' => ['type' => 'string', 'default' => null],
            'network_settings' => ['type' => 'array', 'default' => null],
            ...self::REALITY_CONFIGURATION,
            ...self::REALITY_SERVER_CONFIGURATION,
            ...self::MULTIPLEX_CONFIGURATION,
            ...self::MUX_OPTION_CONFIGURATION,
            ...self::UTLS_CONFIGURATION,
            ...self::LISTENER_TLS_OPTIONS,
            ...self::SNIFF_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_TROJAN => [
            'tls' => ['type' => 'integer', 'default' => 1],
            'network' => ['type' => 'string', 'default' => null],
            'network_settings' => ['type' => 'array', 'default' => null],
            'server_name' => ['type' => 'string', 'default' => null],
            'allow_insecure' => ['type' => 'boolean', 'default' => false],
            'ss_option' => [
                'type' => 'object',
                'default' => null,
                'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false],
                    'method' => ['type' => 'string', 'default' => 'aes-128-gcm'],
                    'password' => ['type' => 'string', 'default' => null],
                ],
            ],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION,
            ...self::REALITY_CONFIGURATION,
            ...self::REALITY_SERVER_CONFIGURATION,
            ...self::MULTIPLEX_CONFIGURATION,
            ...self::MUX_OPTION_CONFIGURATION,
            ...self::UTLS_CONFIGURATION,
            ...self::LISTENER_TLS_OPTIONS,
            ...self::SNIFF_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_ANYTLS => [
            'padding_scheme' => [
                'type' => 'array',
                'default' => [
                    "stop=8",
                    "0=30-30",
                    "1=100-400",
                    "2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000",
                    "3=9-9,500-1000",
                    "4=500-1000",
                    "5=500-1000",
                    "6=500-1000",
                    "7=500-1000"
                ]
            ],
            'tls' => self::TLS_CONFIGURATION,
            ...self::LISTENER_TLS_OPTIONS,
            ...self::SNIFF_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_SNELL => [
            'psk' => ['type' => 'string', 'default' => null],
            'version' => ['type' => 'integer', 'default' => 4],
            'udp' => ['type' => 'boolean', 'default' => true],
            'obfs_opts' => [
                'type' => 'object',
                'default' => null,
                'fields' => [
                    'mode' => ['type' => 'string', 'default' => 'http'],
                    'host' => ['type' => 'string', 'default' => null],
                ],
            ],
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_MIERU => [
            'transport' => ['type' => 'string', 'default' => 'TCP'],
            'traffic_pattern' => ['type' => 'string', 'default' => ''],
            'user_hint_is_mandatory' => ['type' => 'boolean', 'default' => false],
            ...self::MULTIPLEX_CONFIGURATION,
            ...self::MUX_OPTION_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_SUDOKU => [
            'key' => ['type' => 'string', 'default' => null],
            'aead_method' => ['type' => 'string', 'default' => 'chacha20-poly1305'],
            'padding_min' => ['type' => 'integer', 'default' => null],
            'padding_max' => ['type' => 'integer', 'default' => null],
            'table_type' => ['type' => 'string', 'default' => 'prefer_ascii'],
            'custom_table' => ['type' => 'string', 'default' => null],
            'custom_tables' => ['type' => 'array', 'default' => null],
            'handshake_timeout' => ['type' => 'integer', 'default' => null],
            'enable_pure_downlink' => ['type' => 'boolean', 'default' => false],
            'httpmask' => [
                'type' => 'object',
                'default' => null,
                'fields' => [
                    'disable' => ['type' => 'boolean', 'default' => false],
                    'mode' => ['type' => 'string', 'default' => 'legacy'],
                    'path_root' => ['type' => 'string', 'default' => null],
                ],
            ],
            ...self::MUX_OPTION_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_TUIC => [
            'version' => ['type' => 'integer', 'default' => 5],
            'congestion_controller' => ['type' => 'string', 'default' => 'bbr'],
            'congestion_control' => ['type' => 'string', 'default' => null],
            'alpn' => ['type' => 'array', 'default' => ['h3']],
            'udp_relay_mode' => ['type' => 'string', 'default' => 'native'],
            'max_idle_time' => ['type' => 'integer', 'default' => 15000],
            'authentication_timeout' => ['type' => 'integer', 'default' => 1000],
            'max_udp_relay_packet_size' => ['type' => 'integer', 'default' => 1500],
            'bbr_profile' => ['type' => 'string', 'default' => null],
            'tls' => self::TLS_CONFIGURATION,
            ...self::MUX_OPTION_CONFIGURATION,
            ...self::LISTENER_TLS_OPTIONS,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_HYSTERIA2 => [
            'version' => ['type' => 'integer', 'default' => 2],
            'up' => ['type' => 'integer', 'default' => null],
            'down' => ['type' => 'integer', 'default' => null],
            'bandwidth' => [
                'type' => 'object',
                'default' => null,
                'fields' => [
                    'up' => ['type' => 'integer', 'default' => null],
                    'down' => ['type' => 'integer', 'default' => null]
                ]
            ],
            'ignore_client_bandwidth' => ['type' => 'boolean', 'default' => false],
            'obfs' => [
                'type' => 'object',
                'default' => null,
                'fields' => [
                    'open' => ['type' => 'boolean', 'default' => false],
                    'type' => ['type' => 'string', 'default' => 'salamander'],
                    'password' => ['type' => 'string', 'default' => null]
                ]
            ],
            'obfs_password' => ['type' => 'string', 'default' => null],
            'masquerade' => ['type' => 'string', 'default' => null],
            'bbr_profile' => ['type' => 'string', 'default' => null],
            'hop_interval' => ['type' => 'integer', 'default' => null],
            'tls' => self::TLS_CONFIGURATION,
            ...self::MUX_OPTION_CONFIGURATION,
            ...self::LISTENER_TLS_OPTIONS,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_SHADOWQUIC => [
            'jls_upstream' => [
                'type' => 'object',
                'default' => null,
                'fields' => [
                    'addr' => ['type' => 'string', 'default' => null],
                    'sni' => ['type' => 'string', 'default' => null],
                    'proxy' => ['type' => 'string', 'default' => null],
                    'rate_limit' => ['type' => 'integer', 'default' => null],
                ],
            ],
            'alpn' => ['type' => 'array', 'default' => ['h3']],
            'quic_versions' => ['type' => 'array', 'default' => null],
            'zero_rtt' => ['type' => 'boolean', 'default' => true],
            'congestion_controller' => ['type' => 'string', 'default' => 'bbr'],
            'up' => ['type' => 'integer', 'default' => null],
            'down' => ['type' => 'integer', 'default' => null],
            'ignore_client_bandwidth' => ['type' => 'boolean', 'default' => false],
            'cwnd' => ['type' => 'integer', 'default' => 32],
            'bbr_profile' => ['type' => 'string', 'default' => null],
            'max_idle_time' => ['type' => 'integer', 'default' => 30000],
            'max_datagram_frame_size' => ['type' => 'integer', 'default' => 1400],
            'disable_mtu_discovery' => ['type' => 'boolean', 'default' => false],
            ...self::MUX_OPTION_CONFIGURATION,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
        self::TYPE_TRUSTTUNNEL => [
            'network' => ['type' => 'array', 'default' => ['tcp']],
            'congestion_controller' => ['type' => 'string', 'default' => null],
            'bbr_profile' => ['type' => 'string', 'default' => null],
            ...self::LISTENER_TLS_OPTIONS,
            ...self::LISTENER_OVERRIDE_CONFIGURATION,
        ],
    ];

    private function castValueWithConfig($value, array $config)
    {
        if ($value === null && $config['type'] !== 'object') {
            return $config['default'] ?? null;
        }

        return match ($config['type']) {
            'integer' => (int) $value,
            'boolean' => (bool) $value,
            'string' => (string) $value,
            'array' => (array) $value,
            'object' => is_array($value) ?
            $this->castSettingsWithConfig($value, $config['fields']) :
            $config['default'] ?? null,
            default => $value
        };
    }

    private function castSettingsWithConfig(array $settings, array $configs): array
    {
        // Start from the raw payload so that mihomo listener keys the panel does
        // not model yet (and forward-compatible future options) survive a
        // round-trip instead of being silently dropped by the caster.
        $result = $settings;
        foreach ($configs as $key => $config) {
            $value = $settings[$key] ?? null;
            $result[$key] = $this->castValueWithConfig($value, $config);
        }
        return $result;
    }

    public function getProtocolSettingsAttribute($value)
    {
        $settings = json_decode($value, true) ?? [];
        $configs = self::PROTOCOL_CONFIGURATIONS[self::normalizeType($this->type) ?? ''] ?? [];
        return $this->castSettingsWithConfig($settings, $configs);
    }

    public function setProtocolSettingsAttribute($value)
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $configs = self::PROTOCOL_CONFIGURATIONS[self::normalizeType($this->type) ?? ''] ?? [];
        $castedSettings = $this->castSettingsWithConfig($value ?? [], $configs);

        $this->attributes['protocol_settings'] = json_encode($castedSettings);
    }

    public function generateServerPassword(User $user): string
    {
        return $this->generateServerPasswordForCredential(
            ChildIdentityService::credentialFor($this, (string) $user->uuid)
        );
    }

    public function generateServerPasswordForCredential(string $credential): string
    {
        if ($this->type !== self::TYPE_SHADOWSOCKS) {
            return $credential;
        }


        $cipher = data_get($this, 'protocol_settings.cipher');
        if (!$cipher || !isset(self::CIPHER_CONFIGURATIONS[$cipher])) {
            return $credential;
        }

        $config = self::CIPHER_CONFIGURATIONS[$cipher];
        // Child nodes share the inbound server PSK. Traffic is distinguished
        // by a derived user credential, not a second server key.
        $serverCreatedAt = ChildIdentityService::isChildNode($this)
            ? ($this->parent->created_at ?? $this->created_at)
            : $this->created_at;
        $serverKey = Helper::getServerKey($serverCreatedAt, $config['serverKeySize']);
        $userKey = Helper::uuidToBase64($credential, $config['userKeySize']);
        return "{$serverKey}:{$userKey}";
    }

    public static function normalizeType(?string $type): string | null
    {
        return \App\Support\Mihomo::normalizeType($type);
    }

    public static function isValidType(?string $type): bool
    {
        return $type === null || $type === '' ? true : \App\Support\Mihomo::isValidType($type);
    }

    /**
     * The `type:` string mihomo expects for this node's inbound.
     */
    public function getListenerTypeAttribute(): ?string
    {
        return \App\Support\Mihomo::listenerType($this->type);
    }

    public function isSubscriptionType(): bool
    {
        return \App\Support\Mihomo::isSubscriptionType($this->type);
    }

    public function requiresTls(): bool
    {
        return \App\Support\Mihomo::requiresTls($this->type);
    }

    public function getAvailableStatusAttribute(): int
    {
        $now = time();
        if (!$this->last_check_at || ($now - self::CHECK_INTERVAL) >= $this->last_check_at) {
            return self::STATUS_OFFLINE;
        }
        if (!$this->last_push_at || ($now - self::CHECK_INTERVAL) >= $this->last_push_at) {
            return self::STATUS_ONLINE_NO_PUSH;
        }
        return self::STATUS_ONLINE;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(StatServer::class, 'server_id', 'id');
    }

    public function machine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function machines(): BelongsToMany
    {
        return $this->belongsToMany(
            ServerMachine::class,
            'v2_server_machine_binding',
            'server_id',
            'machine_id'
        )->withPivot([
            'state',
            'desired_config_version',
            'applied_config_version',
            'last_error',
            'last_seen_at',
        ])->withTimestamps();
    }

    public function machineBindings(): HasMany
    {
        return $this->hasMany(ServerMachineBinding::class, 'server_id');
    }

    public function outboundTemplates(): BelongsToMany
    {
        return $this->belongsToMany(
            OutboundTemplate::class,
            'v2_server_outbound_template',
            'server_id',
            'outbound_template_id'
        )->withPivot(['tag', 'sort', 'enabled'])->withTimestamps()->orderByPivot('sort');
    }

    public function routeTemplates(): BelongsToMany
    {
        return $this->belongsToMany(
            RouteTemplate::class,
            'v2_server_route_template',
            'server_id',
            'route_template_id'
        )->withPivot(['sort', 'enabled'])->withTimestamps()->orderByPivot('sort');
    }

    public function routeProfiles(): HasMany
    {
        return $this->hasMany(ServerRouteProfile::class, 'server_id')->orderBy('sort');
    }

    public function groups()
    {
        return ServerGroup::whereIn('id', $this->group_ids ?? [])->get();
    }

    public function routes()
    {
        return ServerRoute::whereIn('id', $this->route_ids)->get();
    }

    /**
     * 最后检查时间访问器
     */
    protected function lastCheckAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(CacheKey::get("SERVER_{$type}_LAST_CHECK_AT", $serverId));
            }
        );
    }

    /**
     * 最后推送时间访问器
     */
    protected function lastPushAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(CacheKey::get("SERVER_{$type}_LAST_PUSH_AT", $serverId));
            }
        );
    }

    /**
     * 在线用户数访问器
     */
    protected function online(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(CacheKey::get("SERVER_{$type}_ONLINE_USER", $serverId)) ?? 0;
            }
        );
    }

    /**
     * 是否在线访问器
     */
    protected function isOnline(): Attribute
    {
        return Attribute::make(
            get: function () {
                return (time() - 300 > $this->last_check_at) ? 0 : 1;
            }
        );
    }

    /**
     * 缓存键访问器
     */
    protected function cacheKey(): Attribute
    {
        return Attribute::make(
            get: function () {
                return "{$this->type}-{$this->id}-{$this->updated_at}-{$this->is_online}";
            }
        );
    }

    /**
     * 服务器密钥访问器
     */
    protected function serverKey(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->type === self::TYPE_SHADOWSOCKS) {
                    return Helper::getServerKey($this->created_at, 16);
                }
                return null;
            }
        );
    }

    /**
     * 指标指标访问器
     */
    protected function metrics(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(CacheKey::get("SERVER_{$type}_METRICS", $serverId));
            }
        );
    }

    /**
     * 在线连接数访问器
     */
    protected function onlineConn(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->metrics['active_connections'] ?? 0;
            }
        );
    }

    /**
     * 负载状态访问器
     */
    protected function loadStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(CacheKey::get("SERVER_{$type}_LOAD_STATUS", $serverId));
            }
        );
    }

    protected function sourceStates(): Attribute
    {
        return Attribute::make(
            get: fn () => app(NodeSourceStateService::class)->states($this)
        );
    }

    protected function healthStatus(): Attribute
    {
        return Attribute::make(get: function (): string {
            $servingMachineIds = $this->machineBindings()
                ->whereIn('state', ['active', 'draining'])
                ->pluck('machine_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $states = app(NodeSourceStateService::class)->states($this);

            if ($servingMachineIds === []) {
                return $states === [] ? 'offline' : 'healthy';
            }

            $onlineMachineIds = [];
            foreach ($states as $state) {
                if (preg_match('/^machine:(\d+):/', (string) ($state['source_id'] ?? ''), $match)) {
                    $onlineMachineIds[(int) $match[1]] = true;
                }
            }
            $online = count(array_intersect($servingMachineIds, array_keys($onlineMachineIds)));
            return match (true) {
                $online === 0 => 'offline',
                $online === count($servingMachineIds) => 'healthy',
                default => 'degraded',
            };
        });
    }

    public function getCurrentRate(): float
    {
        if (!$this->rate_time_enable) {
            return (float) $this->rate;
        }

        $now = now()->format('H:i');
        $ranges = $this->rate_time_ranges ?? [];
        $matchedRange = collect($ranges)
            ->first(fn($range) => $now >= $range['start'] && $now <= $range['end']);
        
        return $matchedRange ? (float) $matchedRange['rate'] : (float) $this->rate;
    }
}
