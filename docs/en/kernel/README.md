# mihomo kernel

This build drives exactly one proxy kernel: [mihomo](https://github.com/MetaCubeX/mihomo)
(Clash.Meta). Xray and sing-box support has been removed.

The three documents in this directory are the source of truth for the wire
format:

- [mihomo-inbound-listeners.md](mihomo-inbound-listeners.md) — every listener
  (inbound) type with its exact YAML keys, required fields and defaults.
- [mihomo-proxies.md](mihomo-proxies.md) — every outbound `proxies:` type used
  when generating subscriptions.
- [mihomo-routing.md](mihomo-routing.md) — route rules, `rule-providers`,
  geodata and `sub-rules`.

## What changed

| Concern | Before | Now |
| --- | --- | --- |
| Node kernel | Xray or sing-box, chosen per machine | mihomo only |
| Machine `kernel_type` | `xray` / `singbox` | `mihomo` (legacy values are folded on report and by migration) |
| Subscriptions | v2ray base64, sing-box JSON, Clash, Surge, Stash, … | Clash/mihomo YAML only |
| Node config | normalized dual-core payload | ready-to-use mihomo `listeners`, `rule-providers`, `rules`, `sub-rules` |
| Rule sets | `settings.singbox` + `settings.xray` | `settings.mihomo` (rule-provider schema) |

## Code map

| Path | Responsibility |
| --- | --- |
| `app/Support/Mihomo.php` | Kernel registry: inbound type list, aliases, TLS requirements, `users` shapes. |
| `app/Support/Mihomo/ListenerBuilder.php` | Node row → mihomo `listeners[]` entry; also shapes `users` per inbound. |
| `app/Support/Mihomo/RuleBuilder.php` | Route templates → mihomo `rules`, `rule-providers`, `sub-rules`. |
| `app/Services/ServerService.php` | `buildNodeConfig()` / `buildMihomoConfig()` — the payload pushed to agents. |
| `app/Protocols/ClashMeta.php` | The single subscription handler; renders every node type as a mihomo proxy. |
| `app/Http/Controllers/V1/Client/ClientController.php` | Resolves every subscription request to the Clash/mihomo profile. |
| `resources/admin/src/lib/mihomo.ts` | Admin metadata and the generic inbound option table. |

## Supported inbounds

All 15 supported inbounds produce a client proxy, so every accepted node type
appears in subscriptions:

| Node type | mihomo `type:` |
| --- | --- |
| `vless` | `vless` |
| `vmess` | `vmess` |
| `trojan` | `trojan` |
| `shadowsocks` | `shadowsocks` |
| `hysteria2` | `hysteria2` |
| `tuic` | `tuic` (v4 token / v5 users) |
| `anytls` | `anytls` |
| `snell` | `snell` |
| `mieru` | `mieru` |
| `sudoku` | `sudoku` |
| `shadowquic` | `shadowquic` |
| `trusttunnel` | `trusttunnel` |
| `socks` | `socks` |
| `mixed` | `mixed` (rendered as `socks5`) |
| `http` | `http` |

mihomo's non-proxy listeners (`tun`, `redir`, `tproxy`, `tunnel`) and the
Hysteria2 realm registry are deliberately **not** offered: a node that produces
no subscription entry has no place in this panel.

Legacy spellings are normalized: `hysteria`/`hy2` → `hysteria2`,
`ss` → `shadowsocks`, `socks5` → `socks`, `v2ray` → `vmess`, `naive` → `http`.

## Anything else the kernel supports

The listener builder models the documented options for every inbound. Any
remaining key — including kernel fields added after this release — can be set
through `protocol_settings.listener`, a raw map merged over the generated
listener (with `type` and `name` protected):

```json
{
  "tls": 1,
  "network": "ws",
  "network_settings": { "path": "/ws" },
  "listener": {
    "mux-option": { "padding": true },
    "client-auth-type": "require-and-verify",
    "client-auth-cert": "-----BEGIN CERTIFICATE-----"
  }
}
```

The admin node form exposes the same map as the "高级协议配置（JSON）" editor.
The `Server` model preserves unknown `protocol_settings` keys through a
round-trip specifically so this stays forward compatible.

## Users and credentials

Per-user credentials keep flowing through the dedicated user channel
(`GET /api/v2/server/user`, `sync.users`); they are **not** duplicated into
every node config. The generated `mihomo` block instead carries `users_shape`,
the container mihomo expects for that inbound:

| `users_shape` | Inbounds |
| --- | --- |
| `map` (`{username: password}`) | `anytls`, `hysteria2`, `mieru`, `tuic` (key is the client UUID) |
| `array_user_password` | `http`, `socks`, `mixed`, `trusttunnel`, `shadowquic` |
| `array_uuid` | `vmess`, `vless` |
| `array_password` | `trojan` |
| `null` | single-credential inbounds: `shadowsocks`, `snell` |

`ListenerBuilder::shapeUsers()` implements exactly this mapping and is covered
by the verification script, so an agent or plugin can reuse it instead of
re-deriving the shapes.

## Certificates

Listener TLS material comes from the node's `cert_config`, never from protocol
settings:

- `content` → `certificate` / `private-key` are emitted inline.
- `file` → the configured paths are emitted.
- `self` / `http` / `dns` → the certificate is provisioned on the node; the
  panel forwards `cert_config` and the agent injects the paths.

`tuic`, `hysteria2` and `trusttunnel` require a certificate in mihomo; a node of
those types without one is rejected by the admin form.

## Shadowsocks limitation

mihomo's Shadowsocks inbound authenticates with a **single** password — there is
no per-user list. The panel therefore derives one shared server PSK per node and
uses it for both the listener and the subscription. Per-user traffic accounting
continues to work through the panel's reporting API, not through distinct
credentials.

## Verifying

A dependency-free check of the builders lives at
`tests/scripts/mihomo_builders_check.php`:

```bash
php tests/scripts/mihomo_builders_check.php
```

It covers type normalization, listener generation for the major protocols,
`users` shaping, rule/rule-provider compilation and subscription proxy output.
