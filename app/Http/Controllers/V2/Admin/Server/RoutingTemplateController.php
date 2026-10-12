<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\OutboundTemplate;
use App\Models\RealityTemplate;
use App\Models\RuleSet;
use App\Models\RouteTemplate;
use App\Models\Server;
use App\Models\ServerRouteProfile;
use App\Services\NodeConfigVersionService;
use App\Services\RouteIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoutingTemplateController extends Controller
{
    public function fetch()
    {
        $outbounds = OutboundTemplate::query()->orderBy('name')->get()->map(function (OutboundTemplate $template) {
            $settings = $template->settings;
            foreach (['password'] as $secret) {
                if (!empty($settings[$secret])) {
                    $settings[$secret] = '********';
                }
            }
            return [
                'id' => $template->id,
                'uuid' => $template->uuid,
                'name' => $template->name,
                'protocol' => $template->protocol,
                'settings' => $settings,
                'enabled' => $template->enabled,
                'updated_at' => $template->updated_at,
            ];
        });

        $reality = RealityTemplate::query()->orderBy('name')->get()->map(function (RealityTemplate $template) {
            $settings = $template->settings;
            if (!empty($settings['private_key'])) {
                $settings['private_key'] = '********';
            }
            return [
                'id' => $template->id,
                'uuid' => $template->uuid,
                'name' => $template->name,
                'settings' => $settings,
                'enabled' => $template->enabled,
                'updated_at' => $template->updated_at,
            ];
        });

        return $this->success([
            'outbounds' => $outbounds,
            'rule_sets' => RuleSet::query()->orderBy('name')->get(),
            'routes' => RouteTemplate::query()->orderBy('name')->get(),
            'reality' => $reality,
        ]);
    }

    public function revealOutbound(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|exists:v2_outbound_template,id']);
        $template = OutboundTemplate::findOrFail($params['id']);
        return $this->success(['settings' => $template->settings]);
    }

    public function revealReality(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|exists:v2_reality_template,id']);
        $template = RealityTemplate::findOrFail($params['id']);
        return $this->success(['settings' => $template->settings]);
    }

    public function generateReality()
    {
        if (!function_exists('sodium_crypto_box_keypair')) {
            throw new ApiException('服务器未启用 Sodium，无法生成 REALITY X25519 密钥');
        }

        $keypair = sodium_crypto_box_keypair();
        return $this->success([
            'private_key' => $this->base64Url(sodium_crypto_box_secretkey($keypair)),
            'public_key' => $this->base64Url(sodium_crypto_box_publickey($keypair)),
            'short_id' => bin2hex(random_bytes(8)),
        ]);
    }

    public function saveReality(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_reality_template,id',
            'name' => 'required|string|max:255',
            'enabled' => 'nullable|boolean',
            'settings' => 'required|array',
            'settings.server_name' => 'required|string|max:253',
            'settings.server_port' => 'required|integer|min:1|max:65535',
            'settings.private_key' => 'required|string|max:128',
            'settings.public_key' => 'required|string|max:128',
            'settings.short_id' => ['required', 'string', 'max:16', 'regex:/^(?:[0-9a-fA-F]{2}){1,8}$/'],
            'settings.flow' => 'nullable|string|in:xtls-rprx-vision',
            'settings.network' => 'nullable|string|in:tcp,xhttp,ws,grpc',
            'settings.fingerprint' => 'nullable|string|max:64',
            'settings.allow_insecure' => 'nullable|boolean',
        ]);

        $template = !empty($params['id']) ? RealityTemplate::findOrFail($params['id']) : new RealityTemplate();
        $settings = $params['settings'];
        if (($settings['private_key'] ?? null) === '********') {
            $settings['private_key'] = $template->settings['private_key'] ?? '';
        }

        $template->fill([
            'name' => $params['name'],
            'settings' => $settings,
            'enabled' => $params['enabled'] ?? true,
        ])->save();

        return $this->success(['id' => $template->id, 'uuid' => $template->uuid]);
    }

    public function saveOutbound(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_outbound_template,id',
            'name' => 'required|string|max:255',
            'protocol' => 'required|string|in:shadowsocks,socks5,vless',
            'enabled' => 'nullable|boolean',
            'settings' => 'required|array',
            'settings.server' => 'required|string|max:253',
            'settings.server_port' => 'required|integer|min:1|max:65535',
            'settings.method' => 'required_if:protocol,shadowsocks|nullable|string|max:64',
            'settings.password' => 'nullable|string|max:1024',
            'settings.username' => 'nullable|string|max:255',
            'settings.uuid' => 'nullable|required_if:protocol,vless|uuid',
            'settings.network' => 'nullable|required_if:protocol,vless|string|in:tcp,ws,grpc',
            'settings.flow' => 'nullable|string|in:xtls-rprx-vision',
            'settings.tls' => 'nullable|boolean',
            'settings.server_name' => 'nullable|string|max:253',
            'settings.allow_insecure' => 'nullable|boolean',
            'settings.path' => 'nullable|string|max:2048',
            'settings.service_name' => 'nullable|string|max:255',
        ]);

        $template = !empty($params['id']) ? OutboundTemplate::findOrFail($params['id']) : new OutboundTemplate();
        $serverIds = $template->exists
            ? $template->servers()->pluck('v2_server.id')->all()
            : [];
        $settings = $params['settings'];
        if (($settings['password'] ?? null) === '********') {
            $settings['password'] = $template->settings['password'] ?? '';
        }
        if ($params['protocol'] === OutboundTemplate::PROTOCOL_SHADOWSOCKS && empty($settings['password'])) {
            throw new ApiException('Shadowsocks 密码不能为空');
        }

        $template->fill([
            'name' => $params['name'],
            'protocol' => $params['protocol'],
            'settings' => $settings,
            'enabled' => $params['enabled'] ?? true,
        ])->save();

        NodeConfigVersionService::bumpMany($serverIds);

        return $this->success(['id' => $template->id, 'uuid' => $template->uuid]);
    }

    public function saveRoute(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_route_template,id',
            'name' => 'required|string|max:255',
            'enabled' => 'nullable|boolean',
            'rules' => 'required|array',
            'rules.*.name' => 'nullable|string|max:255',
            'rules.*.disabled' => 'nullable|boolean',
            'rules.*.match' => 'nullable|array',
            'rules.*.match.rule_sets' => 'nullable|array',
            'rules.*.match.rule_sets.*' => 'string|exists:v2_rule_set,uuid',
            'rules.*.match.domains' => 'nullable|array',
            'rules.*.match.domain_suffixes' => 'nullable|array',
            'rules.*.match.ip_cidrs' => 'nullable|array',
            'rules.*.match.ports' => 'nullable|array',
            'rules.*.match.networks' => 'nullable|array',
            'rules.*.match.source_cidrs' => 'nullable|array',
            'rules.*.match.source_ports' => 'nullable|array',
            'rules.*.action.type' => 'required|string|in:direct,block,route',
            'rules.*.action.target' => 'nullable|string|max:64',
        ]);

        foreach ($params['rules'] as $rule) {
            $hasMatch = collect($rule['match'] ?? [])->contains(
                fn ($values) => is_array($values) && collect($values)->contains(fn ($value) => trim((string) $value) !== '')
            );
            if (!$hasMatch) {
                throw new ApiException('每条 Route 规则至少需要一个匹配条件，禁止无条件全局路由');
            }
            if (($rule['action']['type'] ?? '') === 'route' && empty($rule['action']['target'])) {
                throw new ApiException('route 动作必须选择 outbound 模板');
            }
        }

        $template = !empty($params['id']) ? RouteTemplate::findOrFail($params['id']) : new RouteTemplate();
        $serverIds = $template->exists
            ? $template->servers()->pluck('v2_server.id')->all()
            : [];
        $template->fill(
            [
                'name' => $params['name'],
                'rules' => $params['rules'],
                'enabled' => $params['enabled'] ?? true,
            ]
        )->save();
        NodeConfigVersionService::bumpMany($serverIds);
        return $this->success(['id' => $template->id, 'uuid' => $template->uuid]);
    }

    public function saveRuleSet(Request $request)
    {
        $id = $request->input('id');
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_rule_set,id',
            'name' => 'required|string|max:255',
            'tag' => [
                'required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_.-]+$/',
                Rule::unique('v2_rule_set', 'tag')->ignore($id),
            ],
            'enabled' => 'nullable|boolean',
            'settings' => 'required|array',
            // mihomo rule-provider: type/behavior/format are the enums the
            // kernel actually accepts; everything else maps onto its schema.
            'settings.mihomo' => 'required|array',
            'settings.mihomo.type' => 'required|string|in:http,file,inline',
            'settings.mihomo.behavior' => 'required|string|in:domain,ipcidr,classical',
            'settings.mihomo.format' => 'nullable|string|in:yaml,text,mrs',
            'settings.mihomo.url' => ['nullable', 'required_if:settings.mihomo.type,http', 'url:http,https', 'max:2048'],
            'settings.mihomo.path' => ['nullable', 'required_if:settings.mihomo.type,file', 'string', 'max:1024'],
            'settings.mihomo.interval' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:s|m|h|d)?$/'],
            'settings.mihomo.proxy' => 'nullable|string|max:64',
            'settings.mihomo.size_limit' => 'nullable|integer|min:0',
            'settings.mihomo.header' => 'nullable|array',
            'settings.mihomo.payload' => ['nullable', 'required_if:settings.mihomo.type,inline', 'array'],
            'settings.mihomo.payload.*' => 'nullable|string',
            'settings.legacy' => 'nullable|array',
        ]);

        $this->assertValidRuleProvider($params['settings']['mihomo']);

        $ruleSet = !empty($params['id']) ? RuleSet::findOrFail($params['id']) : new RuleSet();
        $ruleSet->fill([
            'name' => $params['name'],
            'tag' => $params['tag'],
            'settings' => $params['settings'],
            'enabled' => $params['enabled'] ?? true,
        ])->save();

        $affectedServerIds = $this->serversUsingRuleSet($ruleSet->uuid);
        NodeConfigVersionService::bumpMany($affectedServerIds);

        return $this->success(['id' => $ruleSet->id, 'uuid' => $ruleSet->uuid]);
    }

    /**
     * Reject rule-provider combinations mihomo refuses to load, so the panel
     * fails fast instead of pushing a config that aborts the whole kernel.
     *
     * @param array<string, mixed> $provider
     */
    private function assertValidRuleProvider(array $provider): void
    {
        $format = $provider['format'] ?? 'yaml';
        $behavior = $provider['behavior'] ?? 'domain';

        // MRS is a binary format whose header encodes domain/ipcidr only.
        if ($format === 'mrs' && $behavior === 'classical') {
            throw new ApiException('MRS 规则集只支持 domain 或 ipcidr 行为，classical 请使用 yaml/text。');
        }

        if ($behavior === 'classical' && !empty($provider['payload'])) {
            foreach ($provider['payload'] as $line) {
                if (preg_match('/^\s*(MATCH|RULE-SET|SUB-RULE)\b/i', (string) $line)) {
                    throw new ApiException('classical 规则集内不允许 MATCH、RULE-SET 或 SUB-RULE 规则。');
                }
            }
        }
    }

    public function configureServer(Request $request, RouteIdentityService $identityService)
    {
        $params = $request->validate([
            'server_id' => 'required|integer|exists:v2_server,id',
            'outbounds' => 'nullable|array',
            'outbounds.*.template_id' => 'required|integer|distinct|exists:v2_outbound_template,id',
            'outbounds.*.tag' => ['required', 'string', 'distinct', 'max:64', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'outbounds.*.enabled' => 'nullable|boolean',
            'routes' => 'nullable|array',
            'routes.*.template_id' => 'required|integer|distinct|exists:v2_route_template,id',
            'routes.*.enabled' => 'nullable|boolean',
            'profiles' => 'nullable|array',
            'profiles.*.id' => 'nullable|integer',
            'profiles.*.name' => 'required|string|max:255',
            'profiles.*.outbound_template_id' => 'nullable|integer|exists:v2_outbound_template,id',
            'profiles.*.enabled' => 'nullable|boolean',
            'profiles.*.entry_server_ids' => 'nullable|array',
            'profiles.*.entry_server_ids.*' => 'integer|exists:v2_server,id',
        ]);
        $server = Server::findOrFail($params['server_id']);
        $selectedRouteIds = collect($params['routes'] ?? [])->pluck('template_id')->map(fn ($id) => (int) $id)->all();
        $usesRuleSets = RouteTemplate::query()->whereIn('id', $selectedRouteIds)->get()->contains(
            fn (RouteTemplate $template) => collect($template->rules ?? [])->contains(
                fn (array $rule) => !empty(data_get($rule, 'match.rule_sets', []))
            )
        );
        if ($usesRuleSets) {
            $unsupportedMachines = $server->machines->filter(
                fn ($machine) => !in_array('rule-sets-v1', $machine->capabilities ?? [], true)
            );
            if ($unsupportedMachines->isNotEmpty()) {
                throw new ApiException('绑定的后端尚未支持规则集，请先更新：'.$unsupportedMachines->pluck('name')->join('、'));
            }
        }
        if (!empty($params['profiles']) && !$identityService->supportsProfiles($server)) {
            throw new ApiException('路由档案目前只支持 VLESS + REALITY、Trojan 或 Shadowsocks 节点');
        }

        $outboundRecords = [];
        foreach ($params['outbounds'] ?? [] as $sort => $outbound) {
            $outboundRecords[(int) $outbound['template_id']] = [
                'tag' => $outbound['tag'],
                'sort' => $sort,
                'enabled' => $outbound['enabled'] ?? true,
            ];
        }
        $attachedOutboundIds = array_map('intval', array_keys($outboundRecords));
        foreach ($params['profiles'] ?? [] as $profile) {
            if (!empty($profile['outbound_template_id'])
                && !in_array((int) $profile['outbound_template_id'], $attachedOutboundIds, true)) {
                throw new ApiException('路由档案引用的 outbound 必须先绑定到节点');
            }
        }

        DB::transaction(function () use ($server, $params, $outboundRecords): void {
            $server->outboundTemplates()->sync($outboundRecords);

            $routeRecords = [];
            foreach ($params['routes'] ?? [] as $sort => $route) {
                $routeRecords[(int) $route['template_id']] = [
                    'sort' => $sort,
                    'enabled' => $route['enabled'] ?? true,
                ];
            }
            $server->routeTemplates()->sync($routeRecords);

            $keptIds = [];
            foreach ($params['profiles'] ?? [] as $sort => $profile) {
                $model = !empty($profile['id'])
                    ? $server->routeProfiles()->whereKey($profile['id'])->firstOrFail()
                    : new ServerRouteProfile(['server_id' => $server->id]);
                $profileData = [
                    'name' => $profile['name'],
                    'outbound_template_id' => $profile['outbound_template_id'] ?? null,
                    'sort' => $sort,
                    'enabled' => $profile['enabled'] ?? true,
                ];
                if (array_key_exists('entry_server_ids', $profile)) {
                    $entryServerIds = collect($profile['entry_server_ids'] ?? [])
                        ->map(fn ($id) => (int) $id)
                        ->filter(fn ($id) => $id > 0)
                        ->unique()
                        ->values()
                        ->all();
                    $profileData['entry_server_ids'] = $entryServerIds ?: null;
                }
                $model->fill($profileData)->save();
                $keptIds[] = $model->id;
            }
            $server->routeProfiles()->whereNotIn('id', $keptIds ?: [0])->delete();
        });

        NodeConfigVersionService::bump($server);
        return $this->success(true);
    }

    public function serverConfiguration(Request $request)
    {
        $params = $request->validate(['server_id' => 'required|integer|exists:v2_server,id']);
        $server = Server::with(['outboundTemplates', 'routeTemplates', 'routeProfiles'])->findOrFail($params['server_id']);
        return $this->success([
            'outbounds' => $server->outboundTemplates->map(fn ($item) => [
                'template_id' => $item->id,
                'tag' => $item->pivot->tag,
                'enabled' => (bool) $item->pivot->enabled,
            ])->values(),
            'routes' => $server->routeTemplates->map(fn ($item) => [
                'template_id' => $item->id,
                'enabled' => (bool) $item->pivot->enabled,
            ])->values(),
            'profiles' => $server->routeProfiles,
            'config_version' => (int) $server->config_version,
        ]);
    }

    public function drop(Request $request)
    {
        $params = $request->validate([
            'kind' => 'required|string|in:outbound,route,reality,rule_set',
            'id' => 'required|integer',
        ]);
        $model = match ($params['kind']) {
            'outbound' => OutboundTemplate::findOrFail($params['id']),
            'route' => RouteTemplate::findOrFail($params['id']),
            'reality' => RealityTemplate::findOrFail($params['id']),
            'rule_set' => RuleSet::findOrFail($params['id']),
        };
        if ($model instanceof RuleSet) {
            $serverIds = $this->serversUsingRuleSet($model->uuid);
            if ($serverIds !== []) {
                throw new ApiException('该规则集仍被 Route 模板引用，请先移除引用');
            }
        }
        $serverIds = method_exists($model, 'servers')
            ? $model->servers()->pluck('v2_server.id')->all()
            : [];
        $model->delete();
        NodeConfigVersionService::bumpMany($serverIds);
        return $this->success(true);
    }

    private function serversUsingRuleSet(string $uuid): array
    {
        return RouteTemplate::query()->get()
            ->filter(fn (RouteTemplate $template) => collect($template->rules ?? [])->contains(
                fn (array $rule) => in_array($uuid, data_get($rule, 'match.rule_sets', []), true)
            ))
            ->flatMap(fn (RouteTemplate $template) => $template->servers()->pluck('v2_server.id'))
            ->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
