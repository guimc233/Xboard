<?php
require __DIR__ . '/../../vendor/autoload.php';

use App\Support\Mihomo;
use App\Support\Mihomo\ListenerBuilder;
use App\Support\Mihomo\RuleBuilder;
use App\Protocols\ClashMeta;

$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fail;
    if (!$ok) { $fail++; echo "FAIL: $label $detail\n"; }
    else { echo "ok:   $label\n"; }
}

echo "== type registry ==\n";
check('hysteria normalizes to hysteria2', Mihomo::normalizeType('hysteria') === 'hysteria2');
check('socks5 normalizes to socks', Mihomo::normalizeType('socks5') === 'socks');
check('naive maps to http', Mihomo::normalizeType('naive') === 'http');
check('mixed is a subscription type', Mihomo::isSubscriptionType('mixed'));
check('tuic requires tls', Mihomo::requiresTls('tuic'));
foreach (['tunnel', 'redir', 'tproxy', 'tun', 'hysteria2-realm', 'naive' /* normalizes to http */] as $retired) {
    if ($retired === 'naive') { continue; }
    check("{$retired} is not an accepted type", !Mihomo::isValidType($retired));
}
check('retired types yield no proxy', ClashMeta::buildProxy('c', ['name' => 'T', 'host' => 'h', 'port' => 1, 'password' => 'c', 'type' => 'tun', 'protocol_settings' => []]) === null);

echo "\n== listener builder ==\n";
$node = fn(array $type, array $settings = [], array $extra = []) => array_merge([
    'id' => 7, 'code' => 'hk-1', 'type' => $type[0], 'port' => 443, 'server_port' => 8443,
    'created_at' => 1700000000, 'protocol_settings' => $settings, 'cert_config' => null,
], $extra);

$l = ListenerBuilder::build($node(['vless'], ['tls' => 2, 'network' => 'tcp', 'reality_settings' => [
    'server_name' => 'www.microsoft.com', 'server_port' => 443, 'private_key' => 'pk', 'short_id' => 'abcd',
]]));
check('vless listener type', ($l['type'] ?? '') === 'vless');
check('vless listener name from code', ($l['name'] ?? '') === 'hk-1');
check('vless listener port is ports-format string', ($l['port'] ?? '') === '8443');
check('vless reality-config derived', ($l['reality-config']['dest'] ?? '') === 'www.microsoft.com:443');
check('vless reality short-id array', ($l['reality-config']['short-id'] ?? []) === ['abcd']);
check('vless has no certificate without cert_config', !isset($l['certificate']));

$l = ListenerBuilder::build($node(['tuic'], ['version' => 5, 'alpn' => ['h3'], 'congestion_controller' => 'bbr'], [
    'cert_config' => ['cert_mode' => 'content', 'cert_content' => 'CERT', 'key_content' => 'KEY'],
]));
check('tuic certificate from content push', ($l['certificate'] ?? '') === 'CERT');
check('tuic private-key from content push', ($l['private-key'] ?? '') === 'KEY');
check('tuic type string', ($l['type'] ?? '') === 'tuic');
check('tuic congestion key', ($l['congestion-controller'] ?? '') === 'bbr');

$l = ListenerBuilder::build($node(['hysteria2'], [
    'up' => 100, 'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'obfs-pass'],
    'tls' => ['server_name' => 'hy.example.com'],
], ['cert_config' => ['cert_mode' => 'file', 'cert_file' => '/etc/fullchain.pem', 'key_file' => '/etc/privkey.pem']]));
check('hysteria2 obfs type', ($l['obfs'] ?? '') === 'salamander');
check('hysteria2 obfs-password', ($l['obfs-password'] ?? '') === 'obfs-pass');
check('hysteria2 file cert path', ($l['certificate'] ?? '') === '/etc/fullchain.pem');

$l = ListenerBuilder::build($node(['shadowsocks'], ['cipher' => '2022-blake3-aes-256-gcm']));
check('shadowsocks derives shared psk', ($l['password'] ?? '') === App\Utils\Helper::getServerKey(1700000000, 32));
check('shadowsocks type string', ($l['type'] ?? '') === 'shadowsocks');
check('shadowsocks udp default true', ($l['udp'] ?? null) === true);

$l = ListenerBuilder::build($node(['snell'], ['psk' => 'psk123', 'version' => 4, 'obfs_opts' => ['mode' => 'http', 'host' => 'bing.com']]));
check('snell psk', ($l['psk'] ?? '') === 'psk123');
check('snell obfs-opts', ($l['obfs-opts']['mode'] ?? '') === 'http');



$l = ListenerBuilder::build($node(['vless'], ['listener' => ['type' => 'hijack', 'name' => 'nope', 'custom-flag' => 1]]));
check('raw override cannot change type', ($l['type'] ?? '') === 'vless');
check('raw override cannot change name', ($l['name'] ?? '') === 'hk-1');
check('raw override passes unknown keys', ($l['custom-flag'] ?? null) === 1);

echo "\n== user shapes ==\n";
$rows = [['username' => 'xb:u:1', 'credential' => 'cred', 'user_id' => 1]];
check('vmess users array with uuid', (ListenerBuilder::shapeUsers('vmess', $rows)[0]['uuid'] ?? '') === 'cred');
check('vmess users array with alterId', (ListenerBuilder::shapeUsers('vmess', $rows)[0]['alterId'] ?? null) === 0);
check('trojan users array with password', (ListenerBuilder::shapeUsers('trojan', $rows)[0]['password'] ?? '') === 'cred');
check('hysteria2 users map', (ListenerBuilder::shapeUsers('hysteria2', $rows)['xb:u:1'] ?? '') === 'cred');
check('shadowsocks has no users shape', ListenerBuilder::shapeUsers('shadowsocks', $rows) === null);

echo "\n== routing ==\n";
$providers = RuleBuilder::ruleProviders([
    ['tag' => 'ai-non-cn', 'mihomo' => ['type' => 'http', 'behavior' => 'classical', 'format' => 'yaml', 'url' => 'https://x/y.yaml', 'interval' => '1d', 'header' => ['User-Agent' => 'mihomo']]],
    ['tag' => 'cn-ip', 'mihomo' => ['type' => 'file', 'behavior' => 'ipcidr', 'format' => 'mrs', 'path' => './cn.mrs']],
]);
check('provider interval converted to seconds', ($providers['ai-non-cn']['interval'] ?? 0) === 86400);
check('provider header normalized to list', ($providers['ai-non-cn']['header']['User-Agent'] ?? []) === ['mihomo']);
check('provider file type has no url', !isset($providers['cn-ip']['url']));

$routes = [[
    'name' => 'Route 1',
    'match' => ['rule_sets' => ['cn-ip'], 'domains' => ['a.com'], 'domain_suffixes' => ['b.com'], 'ip_cidrs' => ['10.0.0.0/8'], 'networks' => ['udp'], 'source_cidrs' => ['192.168.0.0/16']],
    'action' => ['type' => 'direct'],
]];
$rules = RuleBuilder::rules($routes, $providers);
check('RULE-SET gets no-resolve for ipcidr', in_array('RULE-SET,cn-ip,DIRECT,no-resolve', $rules, true));
check('DOMAIN rule', in_array('DOMAIN,a.com,DIRECT', $rules, true));
check('DOMAIN-SUFFIX rule', in_array('DOMAIN-SUFFIX,b.com,DIRECT', $rules, true));
check('IP-CIDR rule with no-resolve', in_array('IP-CIDR,10.0.0.0/8,DIRECT,no-resolve', $rules, true));
check('NETWORK rule', in_array('NETWORK,udp,DIRECT', $rules, true));
check('SRC-IP-CIDR has no trailing flag', in_array('SRC-IP-CIDR,192.168.0.0/16,DIRECT', $rules, true));
$blocked = RuleBuilder::rules([['match' => ['domains' => ['x.com']], 'action' => ['type' => 'block']]], $providers);
check('block maps to REJECT', in_array('DOMAIN,x.com,REJECT', $blocked, true));
$disabled = RuleBuilder::rules([['disabled' => true, 'match' => ['domains' => ['x.com']], 'action' => ['type' => 'direct']]], $providers);
check('disabled rules skipped', $disabled === []);
check('mrs + classical downgraded', (RuleBuilder::ruleProviders([['tag' => 'z', 'mihomo' => ['type' => 'file', 'behavior' => 'classical', 'format' => 'mrs', 'path' => './z.mrs']]])['z']['format'] ?? '') === 'yaml');

echo "\n== subscription proxies ==\n";
$base = ['name' => 'HK', 'host' => 'hk.example.com', 'port' => 443, 'password' => 'cred-1', 'type' => 'vless',
    'protocol_settings' => ['tls' => 2, 'network' => 'tcp', 'reality_settings' => ['server_name' => 'sni.example.com', 'public_key' => 'pub', 'short_id' => 'abcd', 'allow_insecure' => false]]];
$p = ClashMeta::buildProxy('cred-1', $base);
check('vless proxy type', ($p['type'] ?? '') === 'vless');
check('vless uses servername not sni', isset($p['servername']) && !isset($p['sni']));
check('vless reality-opts', ($p['reality-opts']['public-key'] ?? '') === 'pub');
check('vless uuid is credential', ($p['uuid'] ?? '') === 'cred-1');

$p = ClashMeta::buildProxy('cred-1', ['name' => 'T', 'host' => 'h', 'port' => 443, 'password' => 'cred-1', 'created_at' => 1700000000,
    'type' => 'shadowsocks', 'protocol_settings' => ['cipher' => '2022-blake3-aes-256-gcm']]);
check('ss proxy uses shared psk', ($p['password'] ?? '') === App\Utils\Helper::getServerKey(1700000000, 32));
check('ss proxy type', ($p['type'] ?? '') === 'ss');

$p = ClashMeta::buildProxy('cred-1', ['name' => 'T', 'host' => 'h', 'port' => 443, 'password' => 'cred-1', 'type' => 'trojan',
    'protocol_settings' => ['tls' => 1, 'tls_settings' => ['server_name' => 's.example.com', 'allow_insecure' => true]]]);
check('trojan uses sni', ($p['sni'] ?? '') === 's.example.com');
check('trojan no servername', !isset($p['servername']));

$p = ClashMeta::buildProxy('cred-1', ['name' => 'T', 'host' => 'h', 'port' => 443, 'password' => 'cred-1', 'type' => 'tuic',
    'protocol_settings' => ['version' => 5, 'alpn' => ['h3'], 'tls' => ['server_name' => 's']]]);
check('tuic v5 has uuid+password', ($p['uuid'] ?? '') === 'cred-1' && ($p['password'] ?? '') === 'cred-1');
$p = ClashMeta::buildProxy('cred-1', ['name' => 'T', 'host' => 'h', 'port' => 443, 'password' => 'cred-1', 'type' => 'tuic',
    'protocol_settings' => ['version' => 4, 'alpn' => ['h3'], 'tls' => []]]);
check('tuic v4 uses token', ($p['token'] ?? '') === 'cred-1' && !isset($p['uuid']));

$p = ClashMeta::buildProxy('cred-1', ['name' => 'T', 'host' => 'h', 'port' => 443, 'password' => 'cred-1', 'type' => 'hysteria2',
    'protocol_settings' => ['version' => 2, 'bandwidth' => ['up' => 30, 'down' => 200], 'obfs' => ['open' => true, 'password' => 'op'], 'tls' => ['server_name' => 's']]]);
check('hysteria2 type', ($p['type'] ?? '') === 'hysteria2');
check('hysteria2 up unit', ($p['up'] ?? '') === '30 Mbps');
check('hysteria2 obfs', ($p['obfs'] ?? '') === 'salamander');

$p = ClashMeta::buildProxy('cred-1', ['name' => 'T', 'host' => 'h', 'port' => 443, 'password' => 'cred-1', 'type' => 'hysteria',
    'protocol_settings' => ['version' => 1, 'bandwidth' => ['up' => 10, 'down' => 20]]]);
check('hysteria1 type', ($p['type'] ?? '') === 'hysteria');
check('hysteria1 auth-str', ($p['auth-str'] ?? '') === 'cred-1');

$p = ClashMeta::buildProxy('cred-1', ['name' => 'T', 'host' => 'h', 'port' => 443, 'password' => 'cred-1', 'type' => 'mixed', 'protocol_settings' => []]);
check('mixed maps to socks5', ($p['type'] ?? '') === 'socks5');
check('socks5 udp on', ($p['udp'] ?? false) === true);

$p = ClashMeta::buildProxy('cred-1', ['name' => 'T', 'host' => 'h', 'port' => 443, 'password' => 'cred-1', 'type' => 'snell', 'protocol_settings' => ['psk' => 'psk', 'version' => 4]]);
check('snell proxy psk', ($p['psk'] ?? '') === 'psk');

$p = ClashMeta::buildProxy('cred-1', ['name' => 'T', 'host' => 'h', 'port' => 443, 'password' => 'cred-1', 'type' => 'mieru', 'protocol_settings' => ['transport' => 'UDP'], 'ports' => '2000-3000']);
check('mieru port-range replaces port', ($p['port-range'] ?? '') === '2000-3000' && !isset($p['port']));
check('mieru transport', ($p['transport'] ?? '') === 'UDP');

$p = ClashMeta::buildProxy('cred-1', ['name' => 'T', 'host' => 'h', 'port' => 443, 'password' => 'cred-1', 'type' => 'sudoku', 'protocol_settings' => ['key' => 'ed25519']]);
check('sudoku key', ($p['key'] ?? '') === 'ed25519');
check('sudoku type', ($p['type'] ?? '') === 'sudoku');



echo "\n== ServerService::buildMihomoConfig ==\n";
App\Models\Server::unguard();
$server = new App\Models\Server();
$server->fill([
    'code' => 'de-1',
    'type' => 'vless',
    'port' => 443,
    'cert_config' => ['cert_mode' => 'content', 'cert_content' => 'C', 'key_content' => 'K'],
    'protocol_settings' => [
        'tls' => 2,
        'network' => 'tcp',
        'reality_settings' => ['server_name' => 'sni.example.com', 'server_port' => 443, 'private_key' => 'pk', 'short_id' => 'abcd'],
    ],
]);
App\Models\Server::reguard();
$server->id = 5;
$config = App\Services\ServerService::buildMihomoConfig(
    $server,
    $server->protocol_settings,
    8443,
    [[
        'name' => 'AI',
        'match' => ['rule_sets' => ['ai'], 'domains' => ['x.com']],
        'action' => ['type' => 'route', 'target' => 'warp'],
    ]],
    [['tag' => 'ai', 'mihomo' => ['type' => 'http', 'behavior' => 'classical', 'format' => 'yaml', 'url' => 'https://e/x.yaml', 'interval' => '1d']]],
);
check('mihomo block has listeners', isset($config['listeners'][0]));
check('mihomo listener type', ($config['listeners'][0]['type'] ?? '') === 'vless');
check('mihomo listener name', ($config['listeners'][0]['name'] ?? '') === 'de-1');
check('mihomo rule-providers present', isset($config['rule-providers']['ai']));
check('mihomo rules use outbound target', in_array('DOMAIN,x.com,warp', $config['rules'] ?? [], true));
check('mihomo rule-set rule', in_array('RULE-SET,ai,warp', $config['rules'] ?? [], true));
check('mihomo sub-rules present', isset($config['sub-rules']['AI']));
check('mihomo users_shape exposed', ($config['users_shape'] ?? null) === 'array_uuid');
check('tuic map key is the uuid', (ListenerBuilder::shapeUsers('tuic', [[ 'username' => 'xb:u:1', 'credential' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' ]])['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'] ?? '') === 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');


echo "\n== shadowsocks obfuscation plugins ==\n";
$ssNode = fn(array $settings) => ['id' => 1, 'code' => 'ss', 'type' => 'shadowsocks', 'port' => 1, 'server_port' => 8388,
    'created_at' => 1700000000, 'cert_config' => null, 'protocol_settings' => $settings];
$l = ListenerBuilder::build($ssNode(['cipher' => 'aes-256-gcm', 'plugin' => 'obfs-local', 'plugin_opts' => 'obfs=http;obfs-host=bing.com']));
check('ss simple-obfs enabled', ($l['simple-obfs']['enable'] ?? null) === true);
check('ss simple-obfs mode', ($l['simple-obfs']['mode'] ?? '') === 'http');
$l = ListenerBuilder::build($ssNode(['cipher' => 'aes-256-gcm', 'plugin' => 'shadow-tls', 'plugin_opts' => 'password=pw;version=3;host=www.bing.com']));
check('ss shadow-tls version', ($l['shadow-tls']['version'] ?? null) === 3);
check('ss shadow-tls handshake dest', ($l['shadow-tls']['handshake']['dest'] ?? '') === 'www.bing.com:443');
$l = ListenerBuilder::build($ssNode(['cipher' => 'aes-256-gcm', 'plugin' => 'jls', 'plugin_opts' => 'username=u;password=p;host=h.example.com;sni=h.example.com']));
check('ss jls-config users required shape', ($l['jls-config']['users'][0]['username'] ?? '') === 'u');
check('ss jls-config dest', ($l['jls-config']['dest'] ?? '') === 'h.example.com');
$l = ListenerBuilder::build($ssNode(['cipher' => 'aes-256-gcm', 'plugin' => 'restls', 'plugin_opts' => 'password=p']));
check('ss res-tls skipped when dest missing', !isset($l['res-tls']));
$l = ListenerBuilder::build($ssNode(['cipher' => 'aes-256-gcm', 'plugin' => 'kcptun', 'plugin_opts' => 'key=k;crypt=aes;mode=fast;mtu=1350']));
check('ss kcp-tun enabled', ($l['kcp-tun']['enable'] ?? null) === true);
check('ss kcp-tun mtu int', ($l['kcp-tun']['mtu'] ?? null) === 1350);
$p = ClashMeta::buildShadowsocks('cred', ['name' => 'S', 'host' => 'h', 'port' => 1, 'type' => 'shadowsocks',
    'protocol_settings' => ['cipher' => 'aes-256-gcm', 'plugin' => 'jls', 'plugin_opts' => 'username=u;password=p;host=h']]);
check('ss proxy jls plugin-opts', ($p['plugin-opts']['username'] ?? '') === 'u');
check('ss proxy jls plugin name', ($p['plugin'] ?? '') === 'jls');


echo "\n== full coverage ==\n";
foreach (App\Models\Server::VALID_TYPES as $type) {
    $l = ListenerBuilder::build([
        'id' => 1, 'code' => 'n', 'type' => $type, 'port' => 1, 'server_port' => 1,
        'created_at' => 1700000000, 'cert_config' => null, 'protocol_settings' => [],
    ]);
    check("listener renders for {$type}", ($l['type'] ?? null) === Mihomo::listenerType($type));
}
foreach (App\Models\Server::SUBSCRIPTION_TYPES as $type) {
    $entry = ClashMeta::buildProxy('c', [
        'name' => 'n', 'host' => 'h', 'port' => 1, 'password' => 'c', 'type' => $type,
        'created_at' => 1700000000, 'protocol_settings' => [],
    ]);
    check("subscription proxy renders for {$type}", is_array($entry) && isset($entry['type']));
}
check(
    'every valid type is subscription-capable',
    App\Models\Server::VALID_TYPES === App\Models\Server::SUBSCRIPTION_TYPES
);
foreach (App\Models\Server::VALID_TYPES as $type) {
    check("{$type} is subscription-capable", Mihomo::isSubscriptionType($type));
}

echo "\n" . ($fail === 0 ? "ALL CHECKS PASSED\n" : "$fail CHECK(S) FAILED\n");
exit($fail === 0 ? 0 : 1);
