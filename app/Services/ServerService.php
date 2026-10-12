<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineBinding;
use App\Models\ServerRoute;
use App\Models\RuleSet;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Support\Mihomo;
use App\Support\Mihomo\ListenerBuilder;
use App\Support\Mihomo\RuleBuilder;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class ServerService
{

    /**
     * 获取所有服务器列表
     * @return Collection
     */
    public static function getAllServers(): Collection
    {
        $query = Server::with(['machines' => fn ($query) => $query->orderBy('v2_server_machine.id')])
            ->orderBy('sort', 'ASC');

        return $query->get()->append([
            'last_check_at',
            'last_push_at',
            'online',
            'is_online',
            'available_status',
            'cache_key',
            'load_status',
            'metrics',
			'online_conn',
			'source_states',
			'health_status',
        ]);
    }

    /**
     * 获取机器下所有已启用节点
     */
    public static function getMachineNodes(ServerMachine $machine): Collection
    {
        return Server::whereHas('machineBindings', function ($query) use ($machine) {
                $query->where('machine_id', $machine->id)
                    ->whereIn('state', [
                        ServerMachineBinding::STATE_ACTIVE,
                        ServerMachineBinding::STATE_DRAINING,
                    ]);
            })
            ->where(function ($query) {
                $query->whereNull('parent_id')->orWhere('parent_id', 0);
            })
            ->where('enabled', true)
            ->orderBy('sort', 'ASC')
            ->get();
    }

    /**
     * 获取指定用户可用的服务器列表
     * @param User $user
     * @return array
     */
    public static function getAvailableServers(User $user): array
    {
        $servers = Server::whereJsonContains('group_ids', (string) $user->group_id)
            ->with([
                'routeProfiles.outboundTemplate',
                'outboundTemplates',
                'parent.routeProfiles.outboundTemplate',
                'parent.outboundTemplates',
            ])
            ->where('show', true)
            ->where(function ($query) {
                $query->whereNull('transfer_enable')
                    ->orWhere('transfer_enable', 0)
                    ->orWhereRaw('u + d < transfer_enable');
            })
            ->orderBy('sort', 'ASC')
            ->get()
            ->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'cache_key', 'server_key']);

        $servers = collect($servers)->flatMap(function ($server) use ($user) {
            // 判断动态端口
            if (str_contains($server->port, '-')) {
                $port = $server->port;
                $server->port = (int) Helper::randomPort($port);
                $server->ports = $port;
            } else {
                $server->port = (int) $server->port;
            }
            $server->password = $server->generateServerPassword($user);
            $server->rate = $server->getCurrentRate();
            $entries = [$server];

            $identityServer = $server->parent_id ? $server->parent : $server;
            foreach (app(RouteIdentityService::class)->forUser($identityServer, $user) as $identity) {
                if (!RouteIdentityService::allowsEntry($identity, (int) $server->id)) {
                    continue;
                }
                $profiled = clone $server;
                // A route profile is a complete subscription entry. Its name is
                // deliberately user-facing, so do not prefix it with the source
                // node name again ("node · exit").
                $profiled->name = $identity['profile_name'];
                $profiled->password = $server->generateServerPasswordForCredential(
                    ChildIdentityService::credentialFor($server, $identity['uuid'])
                );
                $profiled->route_profile_id = $identity['profile_id'];
                $entries[] = $profiled;
            }
            return $entries;
        })->toArray();

        return $servers;
    }

    /**
     * 根据权限组获取可用的用户列表
     * @param array $groupIds
     * @return Collection
     */
    public static function getAvailableUsers(Server $node)
    {
        $groupIds = $node->group_ids ?? [];
        if (empty($groupIds)) {
            return collect();
        }
        $users = User::toBase()
			->whereIn('group_id', $groupIds)
			->where('access_enabled', true)
			->where('banned', false)
			->where(function ($query) {
				$query->whereNull('expired_at')->orWhere('expired_at', '>', time());
			})
            ->select([
                'id',
                'uuid',
            ])
            ->get()
            ->map(function ($user) use ($node): array {
                return [
                    'id' => (int) $user->id,
                    'uuid' => $user->uuid,
                    // Compatibility fields for pre-refactor agents. Enforcement
                    // is intentionally disabled in the self-hosted distribution.
                    'speed_limit' => 0,
                    'device_limit' => 0,
                    'identities' => array_values(array_merge(
                        app(RouteIdentityService::class)->forUser($node, $user),
                        app(ChildIdentityService::class)->forUser($node, $user),
                    )),
                ];
            });
        return HookManager::filter('server.users.get', $users, $node);
    }

    // 获取路由规则
    public static function getRoutes(array $routeIds)
    {
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])->whereIn('id', $routeIds)->get();
        return $routes;
    }

    /**
     * 处理节点流量数据汇报
     */
    public static function processTraffic(Server $node, array $traffic, string $sourceId = 'standalone:legacy'): void
    {
        $data = array_filter($traffic, fn($item) =>
            is_array($item) && count($item) === 2
            && is_numeric($item[0]) && is_numeric($item[1])
        );

        if (empty($data)) {
            return;
        }

        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        Cache::put(CacheKey::get("SERVER_{$nodeType}_LAST_PUSH_AT", $nodeId), time(), 3600);

        app(NodeSourceStateService::class)->record($node, $sourceId, [
            'traffic_users' => count($data),
            'last_push_at' => time(),
        ]);

        app(TrafficAttributionService::class)->recordNodeTraffic($node, $data);

        (new UserService())->trafficFetch($node, $node->type, $data);
    }

    /**
     * 处理节点在线设备汇报
     */
    public static function processAlive(int $nodeId, array $alive, string $sourceId = 'standalone:legacy'): void
    {
        app(DeviceStateService::class)->replaceSourceSnapshot($nodeId, $sourceId, $alive);
    }

    /**
     * 处理节点连接数汇报
     */
    public static function processOnline(Server $node, array $online, string $sourceId = 'standalone:legacy'): void
    {
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        $nodeType = $node->type;
        $nodeId = $node->id;

        foreach ($online as $uid => $conn) {
            $cacheKey = CacheKey::get("USER_ONLINE_CONN_{$nodeType}_{$nodeId}_" . substr(hash('sha256', $sourceId), 0, 12), $uid);
            Cache::put($cacheKey, (int) $conn, $cacheTime);
        }

        app(NodeSourceStateService::class)->record($node, $sourceId, [
            'online' => collect($online)->map(fn ($count) => max(0, (int) $count))->all(),
        ]);
    }

    /**
     * 处理节点负载状态汇报
     */
    public static function processStatus(Server $node, array $status, string $sourceId = 'standalone:legacy'): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        $statusData = [
            'cpu' => (float) ($status['cpu'] ?? 0),
            'mem' => [
                'total' => (int) ($status['mem']['total'] ?? 0),
                'used' => (int) ($status['mem']['used'] ?? 0),
            ],
            'swap' => [
                'total' => (int) ($status['swap']['total'] ?? 0),
                'used' => (int) ($status['swap']['used'] ?? 0),
            ],
            'disk' => [
                'total' => (int) ($status['disk']['total'] ?? 0),
                'used' => (int) ($status['disk']['used'] ?? 0),
            ],
            'updated_at' => now()->timestamp,
            'kernel_status' => $status['kernel_status'] ?? null,
        ];

        app(NodeSourceStateService::class)->record($node, $sourceId, ['status' => $statusData]);
    }

    /**
     * 标记节点心跳
     */
    public static function touchNode(Server $node, string $sourceId = 'standalone:legacy'): void
    {
        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id),
            time(),
            3600
        );
        app(NodeSourceStateService::class)->record($node, $sourceId, ['heartbeat_at' => time()]);
    }

    /**
     * Update node metrics and load status
     */
    public static function updateMetrics(Server $node, array $metrics, string $sourceId = 'standalone:legacy'): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);

        $metricsData = [
            'uptime' => (int) ($metrics['uptime'] ?? 0),
            'goroutines' => (int) ($metrics['goroutines'] ?? 0),
            'active_connections' => (int) ($metrics['active_connections'] ?? 0),
            'total_connections' => (int) ($metrics['total_connections'] ?? 0),
            'total_users' => (int) ($metrics['total_users'] ?? 0),
            'active_users' => (int) ($metrics['active_users'] ?? 0),
            'inbound_speed' => (int) ($metrics['inbound_speed'] ?? 0),
            'outbound_speed' => (int) ($metrics['outbound_speed'] ?? 0),
            'cpu_per_core' => $metrics['cpu_per_core'] ?? [],
            'load' => $metrics['load'] ?? [],
            'speed_limiter' => $metrics['speed_limiter'] ?? [],
            'gc' => $metrics['gc'] ?? [],
            'api' => $metrics['api'] ?? [],
            'ws' => $metrics['ws'] ?? [],
            'limits' => $metrics['limits'] ?? [],
            'updated_at' => now()->timestamp,
            'kernel_status' => (bool) ($metrics['kernel_status'] ?? false),
        ];

        app(NodeSourceStateService::class)->record($node, $sourceId, ['metrics' => $metricsData]);
    }

    public static function buildNodeConfig(Server $node): array
    {
        $node->loadMissing(['outboundTemplates', 'routeTemplates', 'routeProfiles.outboundTemplate']);
        $nodeType = Server::normalizeType($node->type) ?? $node->type;
        $protocolSettings = $node->protocol_settings;
        $serverPort = $node->server_port;
        $host = $node->host;

        $baseConfig = [
            'protocol' => $nodeType,
            'listen_ip' => '0.0.0.0',
            'server_port' => (int) $serverPort,
            'network' => data_get($protocolSettings, 'network'),
            'networkSettings' => data_get($protocolSettings, 'network_settings') ?: null,
        ];

        $response = match ($nodeType) {
            'shadowsocks' => [
                ...$baseConfig,
                'cipher' => $protocolSettings['cipher'],
                'plugin' => $protocolSettings['plugin'],
                'plugin_opts' => $protocolSettings['plugin_opts'],
                'server_key' => match ($protocolSettings['cipher']) {
                        '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
                        '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
                        default => null,
                    },
            ],
            'vmess' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'trojan' => [
                ...$baseConfig,
                'host' => $host,
                'server_name' => data_get($protocolSettings, 'tls_settings.server_name'),
                'multiplex' => data_get($protocolSettings, 'multiplex'),
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
            ],
            'vless' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'flow' => $protocolSettings['flow'],
                'decryption' => match (data_get($protocolSettings, 'encryption.enabled')) {
                    true => data_get($protocolSettings, 'encryption.decryption'),
                    default => null,
                },
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'hysteria2' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'version' => (int) $protocolSettings['version'],
                'host' => $host,
                'server_name' => data_get($protocolSettings, 'tls.server_name'),
                'tls_settings' => $protocolSettings['tls'],
                'up_mbps' => (int) (data_get($protocolSettings, 'up') ?? data_get($protocolSettings, 'bandwidth.up')),
                'down_mbps' => (int) (data_get($protocolSettings, 'down') ?? data_get($protocolSettings, 'bandwidth.down')),
                'obfs' => data_get($protocolSettings, 'obfs.open') ? data_get($protocolSettings, 'obfs.type') : null,
                'obfs-password' => data_get($protocolSettings, 'obfs.password') ?? data_get($protocolSettings, 'obfs_password'),
                'masquerade' => data_get($protocolSettings, 'masquerade'),
                'bbr_profile' => data_get($protocolSettings, 'bbr_profile'),
            ],
            'tuic' => [
                ...$baseConfig,
                'version' => (int) $protocolSettings['version'],
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'congestion_control' => $protocolSettings['congestion_control'],
                'tls_settings' => $protocolSettings['tls'],
                'auth_timeout' => '3s',
                'zero_rtt_handshake' => false,
                'heartbeat' => '3s',
            ],
            'anytls' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'tls_settings' => $protocolSettings['tls'],
                'padding_scheme' => $protocolSettings['padding_scheme'],
            ],
            'socks', 'mixed' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) data_get($protocolSettings, 'tls', 0),
                'tls_settings' => data_get($protocolSettings, 'tls_settings'),
                'udp' => (bool) data_get($protocolSettings, 'udp', true),
            ],
            'http' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) data_get($protocolSettings, 'tls', 0),
                'tls_settings' => data_get($protocolSettings, 'tls_settings'),
            ],
            'mieru' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'transport' => data_get($protocolSettings, 'transport', 'TCP'),
                'traffic_pattern' => data_get($protocolSettings, 'traffic_pattern'),
            ],
            'snell' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'psk' => data_get($protocolSettings, 'psk'),
                'version' => (int) data_get($protocolSettings, 'version', 4),
                'udp' => (bool) data_get($protocolSettings, 'udp', true),
                'obfs_opts' => data_get($protocolSettings, 'obfs_opts'),
            ],
            'sudoku' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'key' => data_get($protocolSettings, 'key'),
                'aead_method' => data_get($protocolSettings, 'aead_method'),
                'table_type' => data_get($protocolSettings, 'table_type'),
                'httpmask' => data_get($protocolSettings, 'httpmask'),
            ],
            'shadowquic' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'jls_upstream' => data_get($protocolSettings, 'jls_upstream'),
                'alpn' => data_get($protocolSettings, 'alpn'),
                'congestion_controller' => data_get($protocolSettings, 'congestion_controller'),
            ],
            'trusttunnel' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'server_name' => data_get($protocolSettings, 'tls.server_name'),
                'network' => data_get($protocolSettings, 'network'),
                'congestion_controller' => data_get($protocolSettings, 'congestion_controller'),
            ],
            default => [],
        };

        if (!empty($node['route_ids'])) {
            $response['routes'] = self::getRoutes($node['route_ids']);
        }

        if (!empty($node['custom_routes'])) {
            $response['custom_routes'] = $node['custom_routes'];
        }

        $normalizedOutbounds = $node->outboundTemplates
            ->filter(fn ($template) => $template->enabled && (bool) $template->pivot->enabled)
            ->map(fn ($template) => [
                'id' => $template->uuid,
                'tag' => $template->pivot->tag,
                'protocol' => $template->protocol,
                'settings' => $template->settings,
            ])->values()->all();

        // Older Xboard entries used Xray-shaped address/port settings. Promote
        // the common Shadowsocks/SOCKS forms into the normalized channel so the
        // mihomo listener builder receives server/server_port directly.
        $normalizedTags = collect($normalizedOutbounds)->pluck('tag')->filter()->all();
        $remainingLegacyOutbounds = [];
        foreach (($node['custom_outbounds'] ?? []) as $legacyOutbound) {
            if (!is_array($legacyOutbound)) {
                continue;
            }
            $protocol = strtolower((string) ($legacyOutbound['protocol'] ?? ''));
            $normalizedProtocol = $protocol === 'socks' ? 'socks5' : $protocol;
            $settings = is_array($legacyOutbound['settings'] ?? null) ? $legacyOutbound['settings'] : [];
            $server = $settings['server'] ?? $settings['address'] ?? null;
            $serverPort = $settings['server_port'] ?? $settings['port'] ?? null;
            $tag = trim((string) ($legacyOutbound['tag'] ?? ''));

            if (in_array($normalizedProtocol, ['shadowsocks', 'socks5'], true)
                && filled($server) && is_numeric($serverPort) && $tag !== ''
            ) {
                if (!in_array($tag, $normalizedTags, true)) {
                    $normalizedOutbounds[] = [
                        'id' => 'legacy-'.$tag,
                        'tag' => $tag,
                        'protocol' => $normalizedProtocol,
                        'settings' => [
                            'server' => (string) $server,
                            'server_port' => (int) $serverPort,
                            'method' => $settings['method'] ?? null,
                            'username' => $settings['username'] ?? $settings['user'] ?? null,
                            'password' => $settings['password'] ?? $settings['pass'] ?? null,
                        ],
                    ];
                    $normalizedTags[] = $tag;
                }
                continue;
            }

            $remainingLegacyOutbounds[] = $legacyOutbound;
        }
        if ($remainingLegacyOutbounds !== []) {
            $response['custom_outbounds'] = $remainingLegacyOutbounds;
        }
        if ($normalizedOutbounds !== []) {
            $response['outbound_templates'] = $normalizedOutbounds;
        }

        $outboundTagsByUUID = $node->outboundTemplates->mapWithKeys(
            fn ($template) => [$template->uuid => $template->pivot->tag]
        );
        $outboundTags = $node->outboundTemplates->pluck('pivot.tag')->all();
        $ruleSetsByUUID = RuleSet::query()->where('enabled', true)->get()->keyBy('uuid');
        $usedRuleSets = collect();
        $normalizedRoutes = [];
        foreach ($node->routeTemplates->filter(fn ($template) => $template->enabled && (bool) $template->pivot->enabled) as $template) {
            foreach ($template->rules ?? [] as $rule) {
                $match = is_array($rule['match'] ?? null) ? $rule['match'] : [];
                $resolvedRuleSets = collect($match['rule_sets'] ?? [])->map(function ($uuid) use ($ruleSetsByUUID, $usedRuleSets) {
                    $definition = $ruleSetsByUUID->get($uuid);
                    if (!$definition) {
                        return null;
                    }
                    $usedRuleSets->put($definition->uuid, $definition);
                    return $definition->tag;
                })->filter()->values()->all();
                if ($resolvedRuleSets !== []) {
                    $match['rule_sets'] = $resolvedRuleSets;
                } else {
                    unset($match['rule_sets']);
                }
                $action = $rule['action'] ?? [];
                if (($action['type'] ?? '') === 'route') {
                    $target = (string) ($action['target'] ?? '');
                    $action['target'] = $outboundTagsByUUID->get($target, in_array($target, $outboundTags, true) ? $target : '');
                    if ($action['target'] === '') {
                        continue;
                    }
                }
                $normalizedRoutes[] = [
                    'name' => $rule['name'] ?? $template->name,
                    'disabled' => (bool) ($rule['disabled'] ?? false),
                    'match' => $match,
                    'action' => $action,
                ];
            }
        }
        if ($normalizedRoutes !== []) {
            $response['route_rules_v2'] = $normalizedRoutes;
        }
        if ($usedRuleSets->isNotEmpty()) {
            $response['rule_sets'] = $usedRuleSets->values()->map(fn (RuleSet $ruleSet) => [
                'tag' => $ruleSet->tag,
                'mihomo' => data_get($ruleSet->settings, 'mihomo'),
                'legacy' => data_get($ruleSet->settings, 'legacy'),
            ])->all();
        }

        $routeProfiles = $node->routeProfiles
            ->where('enabled', true)
            ->map(function ($profile) use ($node) {
                $outboundTag = 'direct';
                if ($profile->outbound_template_id !== null) {
                    $outboundTag = $node->outboundTemplates
                        ->firstWhere('id', $profile->outbound_template_id)?->pivot?->tag;
                }
                return $outboundTag ? [
                    'id' => $profile->uuid,
                    'name' => $profile->name,
                    'outbound_tag' => $outboundTag,
                ] : null;
            })->filter()->values()->all();
        if ($routeProfiles !== []) {
            $response['route_profiles'] = $routeProfiles;
        }

        $response['config_version'] = max(1, (int) $node->config_version);

        if (!empty($node['cert_config'])) {
            $certConfig = $node['cert_config'];
            // Normalize: accept both "mode" and "cert_mode" from the database
            if (isset($certConfig['mode']) && !isset($certConfig['cert_mode'])) {
                $certConfig['cert_mode'] = $certConfig['mode'];
                unset($certConfig['mode']);
            }
            if (data_get($certConfig, 'cert_mode') !== 'none') {
                $response['cert_config'] = $certConfig;
            }
        }

        // ------------------------------------------------------------------
        // mihomo kernel payload: a ready-to-use listener entry plus the route
        // providers/rules/sub-rules it references. The flat legacy keys above
        // are kept so older agents keep working during the migration window.
        // ------------------------------------------------------------------
        $response['kernel'] = Mihomo::KERNEL;
        $response['mihomo'] = self::buildMihomoConfig($node, $protocolSettings, $serverPort, $normalizedRoutes, $response['rule_sets'] ?? []);

        return $response;
    }

    /**
     * Assemble the mihomo configuration fragment for a node.
     *
     * @param list<array<string, mixed>> $normalizedRoutes
     * @param list<array<string, mixed>> $ruleSets
     * @return array<string, mixed>
     */
    public static function buildMihomoConfig(Server $node, array $protocolSettings, int|string $serverPort, array $normalizedRoutes = [], array $ruleSets = []): array
    {
        $listener = ListenerBuilder::build([
            'id' => $node->id,
            'code' => $node->code,
            'type' => $node->type,
            'port' => $node->port,
            'server_port' => $serverPort,
            'created_at' => $node->created_at,
            'protocol_settings' => $protocolSettings,
            'cert_config' => $node->cert_config,
        ]);

        $providers = RuleBuilder::ruleProviders($ruleSets);
        $rules = RuleBuilder::rules($normalizedRoutes, $providers);
        $subRules = RuleBuilder::subRules($normalizedRoutes, $providers);

        $config = ListenerBuilder::compile(
            $listener === [] ? [] : [$listener],
            $providers,
            $rules,
            $subRules
        );

        if ($sniffer = self::mihomoSniffer($protocolSettings)) {
            $config['sniffer'] = $sniffer;
        }

        // Per-user credentials keep flowing through the dedicated user channel
        // (`/server/user`, `sync.users`). This tells the agent which mihomo
        // `users` container the inbound expects so it can shape them without
        // hard-coding the twelve protocol variants:
        //   map | array_user_password | array_uuid | array_password
        $config['users_shape'] = Mihomo::userShape($node->type);

        return $config;
    }

    /**
     * Per-node sniffing. mihomo only supports global sniffing, so a node that
     * asks for it contributes the overrides it needs.
     *
     * @param array<string, mixed> $protocolSettings
     * @return array<string, mixed>|null
     */
    private static function mihomoSniffer(array $protocolSettings): ?array
    {
        $enabled = filter_var($protocolSettings['sniff'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($protocolSettings['sniff_override'] ?? false, FILTER_VALIDATE_BOOL);

        if (!$enabled) {
            return null;
        }

        return [
            'enable' => true,
            'override-destination' => (bool) filter_var($protocolSettings['sniff_override'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * 根据协议类型和标识获取服务器
     * @param int $serverId
     * @param string $serverType
     * @return Server|null
     */
    public static function getServer($serverId, ?string $serverType = null): Server | null
    {
        return Server::query()
            ->when($serverType, function ($query) use ($serverType) {
                $query->where('type', Server::normalizeType($serverType));
            })
            ->where(function ($query) use ($serverId) {
                $query->where('code', $serverId)
                    ->orWhere('id', $serverId);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$serverId])
            ->first();
    }
}
