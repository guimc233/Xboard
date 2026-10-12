# mihomo (Clash.Meta) — Inbound / Listener Configuration Spec

Implementation-ready reference for a PHP config generator + TypeScript admin form.

**Sources**

* Wiki (rendered): `https://wiki.metacubex.one/en/config/inbound/`, `.../inbound/listeners/`, every listener subpage, `.../inbound/port/`, `.../inbound/tun/`, `.../config/sniff/`.
* Wiki source markdown (same content, no nav chrome): `MetaCubeX/Meta-Docs` → `docs/config/inbound/**/*.md`, `docs/config/sniff/index.en.md`. Chinese pages used where the English page was thin (e.g. `hysteria2-realm.md`).
* Authoritative code cross-check: `MetaCubeX/mihomo`, branch **Alpha**, commit `bc76c1398c6e70089367a30169d0df7c7f77c42d` — files `listener/parse.go`, `listener/inbound/*.go`, `config/config.go`, `common/structure/structure.go`.

**Legend for the `Doc?` column**

* ✅ — documented on its wiki listener page.
* ⚠️**src** — real, accepted YAML key (native `inbound:"..."` tag in the Go struct) but **not** shown/documented on the wiki page. Safe to emit; do not rely on wiki for it.
* Where a wiki page and the code disagree, the code wins for key spelling; discrepancies are listed in §9.

**Decoder facts that affect a config generator (verified in `common/structure`)**

1. Key matching is **case-insensitive** and `_` is normalized to `-` (`DefaultKeyReplacer = strings.NewReplacer("_","-")`). So `private_key`, `Private-Key`, `private-key` all bind to `private-key`; `alterid` binds to `alterId`. Emit the canonical spelling anyway.
2. **Unknown/extra keys are silently ignored** (unless a struct has a `remain` field — no listener struct does).
3. **Missing required keys are a fatal parse error**: a field whose tag has no `,omitempty` must be present in the YAML. This is why e.g. `users`, `cipher`, `password`, `psk`, `key`, `transport`, `target`, `network`, `certificate`/`private-key` (on TUIC/Hysteria2/TrustTunnel), `jls-upstream` (ShadowQUIC), `token` (hysteria2-realm) are mandatory. See §10 for the full required-field matrix.
4. Tag absence (e.g. `SnellObfsOption` uses an `obfs:"..."` tag while the decoder tag is `inbound`) falls back to the Go field name, then case-insensitive match — so `mode` / `host` resolve correctly.

---

## 1. Where inbounds are declared

| Top-level key | Shape | Maps to |
|---|---|---|
| `port` | int | HTTP proxy port |
| `socks-port` | int | SOCKS proxy port |
| `mixed-port` | int | HTTP+SOCKS5 proxy port |
| `redir-port` | int | redirect (Linux/Android, macOS) TCP transparent port |
| `tproxy-port` | int | TProxy (Linux/Android) TCP+UDP transparent port |
| `tun` | map | system TUN capture (see §6.6) |
| `listeners` | **array of maps** | all listener types below |
| `ss-config` | **string** (SS URI) | shortcut → `type: shadowsocks` listener |
| `vmess-config` | **string** (VMess URI) | shortcut → `type: vmess` listener |
| `tuic-server` | **map** | shortcut → `type: tuic` listener (v4 or v5) |
| `inbound-tfo` | bool | global: TCP Fast Open for **all** inbounds (source: `yaml:"inbound-tfo"`) |
| `inbound-mptcp` | bool | global: MPTCP for **all** inbounds (source: `yaml:"inbound-mptcp"`) |

`listeners` is a **plain array** (not a map keyed by name); each element is one inbound.

```yaml
listeners:
  - name: socks5-in-1
    type: socks
    port: 10808
    # listen: 0.0.0.0   # default
    # rule: sub-rule-name1
    # proxy: proxy-name
    # udp: false        # socks/mixed/tproxy/shadowsocks/snell only
```

---

## 2. Common fields — apply to EVERY `listeners[]` entry

Defined by `BaseOption`; identical for all 20 types.

| Key | YAML type | Default | Meaning | Doc |
|---|---|---|---|---|
| `name` | string | — (**required**) | Inbound name; matchable by inbound rules (`in-name`, `in-user`) and shown in logs/API. | ✅ |
| `type` | string | — (**required**) | Listener type discriminator; missing/unknown → `missing type` / `unsupport proxy type: X`. | ✅ |
| `listen` | string | `"0.0.0.0"` | Listening IP address (parsed with `netip.ParseAddr`; a hostname is **not** accepted). | ✅ |
| `port` | string | — (**required for socket types**) | Listening port(s). Accepts "ports format": `200,302` or `200,204,401-429,501-503`. Rendered as multiple sockets. Not enforced by the decoder (an absent port yields no sockets); the `tun` listener ignores it. | ✅ |
| `routing-mark` | int | `0` | `SO_MARK` on the listening socket. **Linux only.** Conflicts with TUN `route-address-set`/`route-exclude-address-set`. | ✅ |
| `rule` | string | `""` | Name of a `sub-rule` used as this inbound's matching rules. Empty → global `rules`. Unknown sub-rule name → falls back to `rules`. | ✅ |
| `proxy` | string | `""` | Force all inbound traffic to this proxy / proxy-group name (bypasses `rule`/`rules`). Must be a valid existing name or startup errors. | ✅ |

**Not** common (do not emit everywhere): `udp` (only `socks`, `mixed`, `tproxy`, `shadowsocks`, `snell`); `enable` (does not exist on any listener); per-listener `interface-name`, `tfo`, `mptcp`, `sniff` (do **not** exist — see §8 and §9).

---

## 3. Listener `type:` strings (authoritative, from `listener/parse.go`)

| Wiki page | Exact `type:` string | Notes |
|---|---|---|
| http | `http` | |
| socks | `socks` | |
| mixed | `mixed` | HTTP + SOCKS5 on one port |
| redirect | **`redir`** | ⚠️ Page is titled "redirect" but the accepted string is **`redir`**. `redirect` is **rejected**. |
| tproxy | `tproxy` | |
| tun (listener) | `tun` | |
| ShadowSocks | `shadowsocks` | **Not** `ss`. |
| VMess | `vmess` | |
| VLESS | `vless` | |
| Trojan | `trojan` | |
| AnyTLS | `anytls` | |
| Mieru | `mieru` | |
| Sudoku | `sudoku` | |
| TUIC v4 | **`tuic`** | v4 vs v5 is **not** a type string — chosen by `token` (v4) vs `users` (v5). |
| TUIC v5 | **`tuic`** | same type string as v4 |
| ShadowQUIC | `shadowquic` | |
| Hysteria2 | `hysteria2` | not `hy2` |
| Hysteria2 Realm | `hysteria2-realm` | hyphenated |
| TrustTunnel | `trusttunnel` | |
| tunnel | `tunnel` | |
| Snell | `snell` | |

No aliases exist: `ss`, `redirect`, `hy2`, `tuic-v4`, `tuic-v5`, `shadow-tls` are all invalid `type` values.

---

## 4. Reusable nested blocks

### 4.1 `users` — four distinct container shapes (very easy to get wrong)

| Listener(s) | Container | Element keys | Required? |
|---|---|---|---|
| `http`, `socks`, `mixed`, `trusttunnel` | **array** | `username` (string), `password` (string) | optional |
| `vmess` | **array** | `username` (string), `uuid` (string, **required**), `alterId` (int) | **required** |
| `vless` | **array** | `username` (string), `uuid` (string, **required**), `flow` (string, e.g. `xtls-rprx-vision`) | **required** |
| `trojan` | **array** | `username` (string), `password` (string, **required**) | **required** |
| `shadowquic` | **array** | `username` (string, required), `password` (string, required) | optional |
| `anytls`, `hysteria2`, `tuic`, `mieru` | **map** `name: password` | key = username / UUID, value = password | `mieru`/`tuic` semantics vary |
| `shadow-tls.users` | **array** | `name`, `password` (v3 mode) | optional |
| `jls-config.users` | **array** | `username` (required), `password` (required) | **required inside jls-config** |

Special semantics:

* `http` / `socks` / `mixed`: `users` absent → follow the global `authentication` list; `users: []` → **no authentication** for that inbound; non-empty → overrides global.
* `hysteria2`: username is **not** used for auth; it only feeds inbound rules (`in-user`). `tuic` v5 keys are UUIDs.
* `mieru`: `users` must be **non-empty** (`len(option.Users) == 0` → error).

```yaml
# array form
users:
  - username: username1
    password: password1
# map form
users:
  username1: password1
```

### 4.2 TLS block (shared by http, socks, mixed, vmess, vless, trojan, anytls, tuic, hysteria2, hysteria2-realm, trusttunnel)

| Key | YAML type | Meaning | Doc |
|---|---|---|---|
| `certificate` | string | Server certificate, **PEM text inline or a file path** (mihomo detects which). | ✅ |
| `private-key` | string | Matching private key, PEM inline or path. | ✅ |
| `client-auth-type` | string enum | mTLS mode: `""` (off), `request`, `require-any`, `verify-if-given`, `require-and-verify`. | ✅ |
| `client-auth-cert` | string | CA/client cert, PEM inline or path. **Must be non-empty** when `client-auth-type` is `verify-if-given` or `require-and-verify`. | ✅ |
| `ech-key` | string | ECH keys block (`-----BEGIN ECH KEYS-----`…). Generate with `mihomo generate ech-keypair <plaintext-domain>`. | ✅ |
| `reality-config` | map | REALITY server block; **mutually exclusive with `certificate`+`private-key`**. | ✅ on vmess/vless/trojan; ⚠️**src** on http/socks/mixed |
| `allow-insecure` | bool | Only on `vless`, `trojan`, `anytls`. Allows running without any TLS layer (for nginx/caddy upstreaming). | ✅ |

Naming note: listeners use **`certificate` / `private-key`**, *not* `certificate-path`/`key-path`. There is no `certificate-path` key anywhere in inbound listener config. `certificate` and `private-key` must be supplied **together**.

* `tuic`, `hysteria2`, `trusttunnel`: `certificate`+`private-key` are **required** (tags have no `omitempty`) → always emit them.
* `http`, `socks`, `mixed`, `vmess`, `trojan`, `anytls`, `hysteria2-realm`: optional at the parser level; each protocol imposes its own "need at least one TLS mechanism unless `allow-insecure`" rule (documented as `!!! warning` blocks).

### 4.3 `reality-config` (`RealityConfig`)

| Key | YAML type | Required | Meaning | Doc |
|---|---|---|---|---|
| `dest` | string | **yes** | Real TLS target, e.g. `test.com:443`. | ✅ |
| `private-key` | string | **yes** | X25519 private key, `mihomo generate reality-keypair`. | ✅ |
| `short-id` | array of string | **yes** | Allowed short IDs (hex), e.g. `["0123456789abcdef"]`. | ✅ |
| `server-names` | array of string | **yes** | Allowed SNI list. | ✅ |
| `max-time-difference` | int (µs) | no | Timestamp tolerance. | ✅ |
| `proxy` | string | no | Proxy used for the fallback/steal-oneself connection. | ✅ |
| `limit-fallback-upload` | map | no | Rate-limit failed-auth fallback upload. | ✅ |
| `limit-fallback-download` | map | no | Rate-limit failed-auth fallback download. | ✅ |

`limit-fallback-upload` / `limit-fallback-download` children: `after-bytes` (int), `bytes-per-sec` (int, 0 = disabled), `burst-bytes-per-sec` (int).

```yaml
reality-config:
  dest: test.com:443
  private-key: jNXHt1yRo0vDuchQlIP6Z0ZvjT3KtzVI-T4E7RoLJS0
  short-id: ["0123456789abcdef"]
  server-names: ["test.com"]
  max-time-difference: 0
  proxy: ""
  limit-fallback-upload:  { after-bytes: 0, bytes-per-sec: 0, burst-bytes-per-sec: 0 }
  limit-fallback-download:{ after-bytes: 0, bytes-per-sec: 0, burst-bytes-per-sec: 0 }
```

### 4.4 `shadow-tls`, `res-tls`, `jls-config` (mutually exclusive with each other on `snell`, and alternatives to TLS)

`shadow-tls` (`ShadowTLS`) — `enable` is **required inside the block**:

| Key | Type | Meaning | Doc |
|---|---|---|---|
| `enable` | bool | **required**; `true` turns ShadowTLS on. | ✅ |
| `version` | int | `1`/`2`/`3`. | ✅ |
| `password` | string | v2 password. | ✅ |
| `users` | array of `{name, password}` | v3 users. | ✅ |
| `handshake` | map | `{dest: "host:port" (required), proxy: "..."}`. | ✅ (`dest`) |
| `handshake-for-server-name` | map `sni: {dest, proxy}` | Per-SNI handshake targets. ⚠️**src** |
| `strict-mode` | bool | Enforce handshake SNI match. ⚠️**src** |
| `wildcard-sni` | string | Wildcard SNI handling. ⚠️**src** |

`res-tls` (`ResTLS`) — `enable`, `dest`, `password` all **required inside the block**:

| Key | Type | Meaning | Doc |
|---|---|---|---|
| `enable` | bool | required | ✅ |
| `dest` | string | Fallback TLS target. | ✅ |
| `password` | string | ResTLS password. | ✅ |
| `restls-script` | string | ResTLS script. | ✅ |
| `min-record-len` | int | Minimum TLS record length. | ✅ |
| `proxy` | string | Proxy for fallback traffic. | ✅ |
| `rate-limit` | int (bit/s) | Bidirectional fallback rate limit; 0 = unlimited. | ✅ |

`jls-config` (`JLSConfig`) — `enable`, `users`, `dest` **required inside the block**:

| Key | Type | Meaning | Doc |
|---|---|---|---|
| `enable` | bool | required | ✅ |
| `users` | array of `{username, password}` (both required) | JLS auth. | ✅ |
| `dest` | string | Fallback target for unauthenticated/regular TLS. | ✅ |
| `sni` | string | Inferred from `dest` when empty. | ✅ |
| `alpn` | array of string | e.g. `[h2, http/1.1]`. | ✅ |
| `proxy` | string | Fallback proxy. | ✅ |
| `rate-limit` | int (bit/s) | Fallback rate limit; 0 = unlimited. | ✅ |

### 4.5 `tlsmirror-config` (`TLSMirrorConfig`, VMess only in the docs)

| Key | Type | Meaning | Doc |
|---|---|---|---|
| `dest` | string | Mirror target. | ✅ |
| `primary-key` | string | Base64 of 32-byte primary key. | ✅ |
| `proxy` | string | Outbound proxy. | ✅ |
| `explicit-nonce-ciphersuites` | array of int | TLS1.2 explicit-nonce suites. | ✅ |
| `defer-instance-derived-write-time` | map | `base-nanoseconds` (int), `uniform-random-multiplier-nanoseconds` (int). | ✅ |
| `transport-layer-padding` | map | `enabled` (bool). | ✅ |
| `connection-enrolment` | map | `primary-ingress-outbound` (string), `primary-egress-outbound` (string ⚠️**src**). | ✅ (ingress) |
| `sequence-watermarking-enabled` | bool | Sequence watermarking. | ✅ |

### 4.6 `mux-option` (`MuxOption`) — supported by ss, vmess, vless, trojan, sudoku, tuic, hysteria2, shadowquic

| Key | Type | Meaning |
|---|---|---|
| `padding` | bool | Enable mux padding. |
| `brutal` | map | `{enabled: bool, up: int (Mbps), down: int (Mbps)}`. |

```yaml
mux-option:
  padding: true
  brutal:
    enabled: true
    up: 1000
    down: 1000
```

### 4.7 Shadowsocks-only obfuscation blocks

`simple-obfs` (`SimpleObfs`): `enable` (bool), `mode` (`http` | `tls`).

`kcp-tun` (`KcpTun`) — `enable` is **required inside the block**; config keys (all kcptun options):

| Key | Type | Key | Type |
|---|---|---|---|
| `enable` | bool (required) | `sndwnd` | int |
| `key` | string | `rcvwnd` | int |
| `crypt` | string (`aes`,`aes-128`,`aes-128-gcm`,`aes-192`,`salsa20`,`blowfish`,`twofish`,`cast5`,`3des`,`tea`,`xtea`,`xor`,`none`,`null`) | `datashard` | int |
| `mode` | string (`fast3`,`fast2`,`fast`,`normal`,`manual`) | `parityshard` | int |
| `conn` | int | `dscp` | int |
| `autoexpire` | int (s) | `nocomp` | bool |
| `scavengettl` | int (s) | `acknodelay` | bool |
| `mtu` | int | `nodelay` | int |
| `ratelimit` | int (B/s) | `interval` | int |
| `sockbuf` | int | `resend` | int |
| `smuxver` | int (1/2) | `nc` | bool (disable congestion) — ⚠️**src**, absent from the wiki example |
| `smuxbuf` | int | `keepalive` | int (s) |
| `framesize` | int | `streambuf` | int |

### 4.8 `xhttp-config` (VLESS only, `XHTTPConfig`)

| Key | Type | Doc |
|---|---|---|
| `path` | string | ✅ |
| `host` | string | ✅ |
| `mode` | string (`auto`,`stream-one`,`stream-up`,`packet-up`) | ✅ |
| `no-sse-header` | bool | ✅ |
| `x-padding-bytes` | string (`"100-1000"`) | ✅ |
| `x-padding-obfs-mode` | bool | ✅ |
| `x-padding-key` | string | ✅ |
| `x-padding-header` | string | ✅ |
| `x-padding-placement` | string (`queryInHeader`,`cookie`,`header`,`query`) | ✅ |
| `x-padding-method` | string (`repeat-x`,`tokenish`) | ✅ |
| `uplink-http-method` | string (`POST`,`PUT`,`PATCH`,`DELETE`) | ✅ |
| `session-placement` | string (`path`,`query`,`cookie`,`header`) | ✅ |
| `session-key` | string | ✅ |
| `session-table` | string (`""`,`uuid`, `ALPHABET`, `Alphabet`, `BASE36`, `Base62`, `HEX`, `alphabet`, `base36`, `hex`, `number`) | ✅ (page comments) |
| `session-length` | string (`"16-32"`) | ✅ (page comments) |
| `seq-placement` | string (`path`,`query`,`cookie`,`header`) | ✅ |
| `seq-key` | string | ✅ |
| `uplink-data-placement` | string (`body`,`cookie`,`header`) | ✅ |
| `uplink-data-key` | string | ✅ |
| `uplink-chunk-size` | string | ✅ |
| `sc-max-buffered-posts` | string | ✅ |
| `sc-stream-up-server-secs` | string (`"20-80"`) | ✅ |
| `sc-max-each-post-bytes` | string | ✅ |

(These were all documented as commented keys in the VLESS page; `no-sse-header` also present in source.)

### 4.9 `ss-option` (Trojan only, `TrojanSSOption`)

`enabled` (bool), `method` (`aes-128-gcm`/`aes-256-gcm`/`chacha20-ietf-poly1305`), `password` (string).

### 4.10 VMess `mekya-config` / `mkcp-config`

`mkcp-config` (`MKCPConfig`): `enable` (bool), `mtu` (int), `tti` (int ms), `uplink-capacity` (int MB/s), `downlink-capacity` (int MB/s), `congestion` (bool), `write-buffer` (int), `read-buffer` (int), `seed` (string), `header` (string: `none`/`srtp`/`utp`/`wechat-video`/`dtls`/`wireguard`).

`mekya-config` (`MekyaConfig`): `enable` (bool), `max-write-size` (int), `max-write-duration-ms` (int), `max-simultaneous-write-connection` (int), `packet-writing-buffer` (int), `kcp` (nested `MKCPConfig` with `mtu`,`tti`,`uplink-capacity`,`downlink-capacity`,`congestion`,`write-buffer`,`read-buffer`,`seed`,`header`).
Additional source-only keys on `mekya-config`: `url` (string), `h2-pool-size` (int), `max-write-delay` (int), `max-request-size` (int), `polling-interval-initial` (int) — ⚠️**src**.

---

## 5. Per-listener reference

> Notation: **bold** = required. Type strings and defaults verified against source.

### 5.1 `http`

| Key | Type | Default | Meaning | Doc |
|---|---|---|---|---|
| common | — | — | §2 | ✅ |
| `users` | array `{username,password}` | follows global auth | Per-inbound auth; `[]` disables auth | ✅ |
| `certificate` | string | — | Enables TLS (needs `private-key`) | ✅ |
| `private-key` | string | — | TLS key | ✅ |
| `client-auth-type` | string enum | `""` | mTLS mode | ✅ |
| `client-auth-cert` | string | — | mTLS cert | ✅ |
| `ech-key` | string | — | ECH keys | ✅ |
| `reality-config` | map | — | REALITY (§4.3) | ⚠️**src** |

No `udp` field (HTTP CONNECT is TCP-only). TLS is off unless both cert+key are set.

```yaml
listeners:
  - name: http-in
    type: http
    port: 7890
    listen: 0.0.0.0
    routing-mark: 0
    rule: sub-rule-1
    proxy: my-group
    users:
      - username: username1
        password: password1
    certificate: ./server.crt
    private-key: ./server.key
    client-auth-type: require-and-verify
    client-auth-cert: ./ca.crt
    ech-key: |
      -----BEGIN ECH KEYS-----
      ACATwY30o/RKgD6hgeQxwrSiApLaCgU+HKh7B6SUrAHaDwBD/g0APwAAIAAgHjzK
      -----END ECH KEYS-----
    reality-config:
      dest: test.com:443
      private-key: jNXHt1yRo0vDuchQlIP6Z0ZvjT3KtzVI-T4E7RoLJS0
      short-id: ["0123456789abcdef"]
      server-names: ["test.com"]
```

### 5.2 `socks`

| Key | Type | Default | Meaning | Doc |
|---|---|---|---|---|
| common | — | — | §2 | ✅ |
| `udp` | bool | **true** | Listen UDP (SOCKS5 UDP ASSOCIATE) | ✅ |
| `users` | array `{username,password}` | follows global auth | `[]` disables auth | ✅ |
| `certificate` / `private-key` | string | — | TLS | ✅ |
| `client-auth-type` / `client-auth-cert` | string | `""` / — | mTLS | ✅ |
| `ech-key` | string | — | ECH | ✅ |
| `reality-config` | map | — | REALITY | ⚠️**src** |

```yaml
listeners:
  - name: socks-in
    type: socks
    port: 7891
    listen: 0.0.0.0
    udp: true
    users:
      - username: username1
        password: password1
    certificate: ./server.crt
    private-key: ./server.key
```

### 5.3 `mixed`

Identical field set to `socks` (HTTP + SOCKS5 on the same port): `udp` (default **true**), `users`, TLS block, `reality-config` (⚠️**src**).

```yaml
listeners:
  - name: mixed-in
    type: mixed
    port: 7892
    listen: 0.0.0.0
    udp: true
    users:
      - username: username1
        password: password1
```

### 5.4 `redir` (page title: "redirect")

Only the common fields. No protocol-specific keys. Linux (Android) and macOS only; TCP only.

```yaml
listeners:
  - name: redir-in
    type: redir        # NOT "redirect"
    port: 7893
    listen: 0.0.0.0
```

### 5.5 `tproxy`

| Key | Type | Default | Meaning | Doc |
|---|---|---|---|---|
| common | — | — | §2 | ✅ |
| `udp` | bool | **true** | Listen UDP | ✅ |

```yaml
listeners:
  - name: tproxy-in
    type: tproxy
    port: 7894
    listen: 0.0.0.0
    udp: true
```

### 5.6 `tun` (listener form)

No `enable` key (the listener exists by being in the array). Defaults from `parse.go`: `stack: mips`, `dns-hijack: ["0.0.0.0:53"]`.

| Key | Type | Default | Meaning | Doc |
|---|---|---|---|---|
| `device` | string | — | Interface name (macOS must start with `utun`) | ✅ |
| `stack` | string | `mips` | `system`/`gvisor`/`mixed`/`mips` | ✅ |
| `dns-hijack` | array of string | `["0.0.0.0:53"]` | Hijack matching DNS into internal DNS; bare IP defaults to `udp://` | ✅ |
| `auto-route` | bool | false | Install default routes into TUN | ✅ |
| `auto-detect-interface` | bool | false | Auto-pick outbound interface | ✅ |
| `mtu` | int | (component default, e.g. 9000) | TUN MTU | ✅ |
| `gso` | bool | false | Generic Segmentation Offload (Linux) | ✅ |
| `gso-max-size` | int | — | Max GSO chunk | ✅ |
| `inet4-address` | array of CIDR | — | IPv4 address of TUN | ✅ (listener page) |
| `inet6-address` | array of CIDR | — | IPv6 address (needs global `ipv6: true`) | ✅ |
| `iproute2-table-index` | int | `2022` (top-level tun doc) | iproute2 table for auto-route | ✅ |
| `iproute2-rule-index` | int | `9000` (top-level tun doc) | iproute2 rule start index | ✅ |
| `auto-redirect` | bool | false | Linux iptables/nftables TCP redirect; needs `auto-route` | ✅ |
| `auto-redirect-input-mark` | uint32 | — | INPUT mark | ⚠️**src** |
| `auto-redirect-output-mark` | uint32 | — | OUTPUT mark | ⚠️**src** |
| `auto-redirect-iproute2-fallback-rule-index` | int | — | fallback rule index | ⚠️**src** |
| `loopback-address` | array of IP | — | loopback addresses | ⚠️**src** |
| `strict-route` | bool | false | Strict routing / block leaks | ✅ |
| `route-address` | array of CIDR | — | Custom routed subnets | ✅ |
| `route-address-set` | array of string | — | Route only rule-set CIDRs (Linux, nftables, needs auto-route+auto-redirect). Conflicts with `routing-mark`. | ✅ |
| `route-exclude-address` | array of CIDR | — | Excluded subnets | ✅ |
| `route-exclude-address-set` | array of string | — | Excluded rule-sets; same constraints | ✅ |
| `include-interface` / `exclude-interface` | array of string | — | Interface allow/deny (mutually exclusive) | ✅ |
| `include-uid` / `exclude-uid` | array of int | — | UID rules (Linux, needs auto-route) | ✅ |
| `include-uid-range` / `exclude-uid-range` | array of string | — | UID ranges. Listener page uses `1000-99999`; top-level TUN page shows `1000:9999` | ✅ |
| `exclude-src-port` / `exclude-src-port-range` | array of int / string | — | Source port exclusions | ⚠️**src** |
| `exclude-dst-port` / `exclude-dst-port-range` | array of int / string | — | Destination port exclusions | ⚠️**src** |
| `include-android-user` | array of int | — | Android user IDs (0 owner, 10 second space, 999 app cloner) | ✅ |
| `include-package` / `exclude-package` | array of string | — | Android package names | ✅ |
| `include-mac-address` / `exclude-mac-address` | array of string | — | LAN device MAC filtering (Linux, needs auto-route+auto-redirect) | ✅ |
| `endpoint-independent-nat` | bool | false | EIM NAT | ✅ |
| `udp-timeout` | int (s) | `300` | UDP NAT timeout | ✅ (top-level tun page) |
| `icmp-timeout` | int (s) | — | ICMP NAT timeout | ⚠️**src** |
| `disable-icmp-forwarding` | bool | — | Disable ICMP forwarding | ⚠️**src** |
| `congestion-controller` | string | `cubic` | `cubic`/`reno`/`bbr`/`bbr3`; mips stack only | ✅ |
| `file-descriptor` | int | — | TUN fd | ⚠️**src** |
| `inet4-route-address` / `inet6-route-address` | array of CIDR | — | Legacy route-address (deprecated) | ✅ |
| `inet4-route-exclude-address` / `inet6-route-exclude-address` | array of CIDR | — | Legacy route-exclude-address (deprecated) | ✅ |
| `recvmsgx` / `sendmsgx` | bool | — | Linux batch syscalls | ⚠️**src** |
| `processors-per-channel` | int | — | Linux channel parallelism | ⚠️**src** |

```yaml
listeners:
  - name: tun-in
    type: tun
    stack: mips
    dns-hijack:
      - 0.0.0.0:53
      - tcp://any:53
    auto-route: true
    auto-redirect: true
    auto-detect-interface: true
    device: utun0
    mtu: 9000
    strict-route: true
    gso: true
    gso-max-size: 65536
    inet4-address: [198.19.0.1/30]
    inet6-address: ["fdfe:dcba:9877::1/126"]
    iproute2-table-index: 2022
    iproute2-rule-index: 9000
    endpoint-independent-nat: false
    udp-timeout: 300
    route-address: [0.0.0.0/1, 128.0.0.0/1, "::/1", "8000::/1"]
    route-exclude-address: [192.168.0.0/16, fc00::/7]
    route-address-set: [ruleset-1]
    route-exclude-address-set: [ruleset-2]
    include-interface: [eth0]
    exclude-interface: [eth1]
    include-uid: [0]
    include-uid-range: ["1000-99999"]
    exclude-uid: [1000]
    exclude-uid-range: ["1000-99999"]
    include-mac-address: ["00:11:22:33:44:55"]
    exclude-mac-address: ["00:11:22:33:44:66"]
    include-android-user: [0, 10]
    include-package: [com.android.chrome]
    exclude-package: [com.android.captiveportallogin]
    congestion-controller: cubic
```

### 5.7 `shadowsocks`

| Key | Type | Default | Meaning | Doc |
|---|---|---|---|---|
| `cipher` | string | — (**required**) | See cipher table below | ✅ |
| `password` | string | — (**required**) | Password (length depends on 2022 ciphers) | ✅ |
| `udp` | bool | **true** | Listen UDP | ✅ |
| `simple-obfs` | map | — | `{enable, mode: http\|tls}` | ✅ |
| `shadow-tls` | map | — | §4.4 | ✅ |
| `res-tls` | map | — | §4.4 | ✅ |
| `jls-config` | map | — | §4.4 (wraps TCP only) | ✅ |
| `kcp-tun` | map | — | §4.7 kcptun options | ✅ |
| `mux-option` | map | — | §4.6 | ✅ |

Ciphers / password length: `2022-blake3-aes-128-gcm` (16 B base64), `2022-blake3-aes-256-gcm` (32 B), `2022-blake3-chacha20-poly1305` (32 B), `none`, `aes-128-gcm`, `aes-192-gcm`, `aes-256-gcm`, `chacha20-ietf-poly1305`, `xchacha20-ietf-poly1305`. For non-`none`, non-2022 ciphers any string is accepted.

```yaml
listeners:
  - name: ss-in
    type: shadowsocks
    port: 10001
    listen: 0.0.0.0
    cipher: 2022-blake3-aes-256-gcm
    password: vlmpIPSyHH6f4S8WVPdRIHIlzmB+GIRfoH3aNJ/t9Gg=
    udp: true
    simple-obfs:
      enable: false
      mode: http            # http | tls
    shadow-tls:
      enable: true
      version: 3            # 1 | 2 | 3
      password: password    # v2
      users:                # v3
        - name: "1"
          password: password
      handshake:
        dest: test.com:443
    res-tls:
      enable: true
      dest: test.com:443
      password: password
      restls-script: ""
      min-record-len: 0
      proxy: ""
      rate-limit: 0
    jls-config:
      enable: true
      users:
        - username: jls-user
          password: jls-password
      dest: www.example.com:443
      sni: www.example.com
      alpn: [h2, http/1.1]
      proxy: ""
      rate-limit: 0
    kcp-tun:
      enable: true
      key: it's a secrect
      crypt: aes
      mode: fast
      conn: 1
      autoexpire: 0
      scavengettl: 600
      ratelimit: 0
      mtu: 1350
      sndwnd: 128
      rcvwnd: 512
      datashard: 10
      parityshard: 3
      dscp: 0
      nocomp: false
      acknodelay: false
      nc: false
      nodelay: 0
      interval: 50
      resend: 0
      sockbuf: 4194304
      smuxver: 1
      smuxbuf: 4194304
      framesize: 8192
      streambuf: 2097152
      keepalive: 10
    mux-option:
      padding: true
      brutal: { enabled: true, up: 1000, down: 1000 }
```

### 5.8 `vmess`

| Key | Type | Required | Meaning | Doc |
|---|---|---|---|---|
| `users` | array `{username, uuid, alterId}` (`uuid` required) | **yes** | Users | ✅ |
| `ws-path` | string | no | Non-empty enables WebSocket transport | ✅ |
| `grpc-service-name` | string | no | Non-empty enables gRPC transport | ✅ |
| `certificate` / `private-key` | string | no | TLS (both together) | ✅ |
| `client-auth-type` / `client-auth-cert` | string | no | mTLS | ✅ |
| `ech-key` | string | no | ECH | ✅ |
| `shadow-tls` / `res-tls` / `jls-config` | map | no | §4.4 | ✅ |
| `reality-config` | map | no | §4.3; mutually exclusive with cert+key | ✅ |
| `tlsmirror-config` | map | no | §4.5; mutually exclusive with cert+key | ✅ |
| `mekya-config` | map | no | §4.10; cannot combine with mkcp/ws/grpc | ✅ |
| `mkcp-config` | map | no | §4.10 | ✅ |
| `mux-option` | map | no | §4.6 | ✅ |

No `allow-insecure` on VMess (does not exist).

```yaml
listeners:
  - name: vmess-in-1
    type: vmess
    port: 10814
    listen: 0.0.0.0
    users:
      - username: "1"
        uuid: 9d0cb9d0-964f-4ef6-897d-6c6b3ccf9e68
        alterId: 1
    ws-path: "/"
    grpc-service-name: "GunService"
    certificate: ./server.crt
    private-key: ./server.key
    client-auth-type: ""
    client-auth-cert: ""
    ech-key: ""
    reality-config:
      dest: test.com:443
      private-key: jNXHt1yRo0vDuchQlIP6Z0ZvjT3KtzVI-T4E7RoLJS0
      short-id: ["0123456789abcdef"]
      server-names: ["test.com"]
    tlsmirror-config:
      dest: test.com:443
      primary-key: MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=
      proxy: ""
      explicit-nonce-ciphersuites: [156, 157, 49195]
      defer-instance-derived-write-time: { base-nanoseconds: 0, uniform-random-multiplier-nanoseconds: 0 }
      transport-layer-padding: { enabled: false }
      connection-enrolment: { primary-ingress-outbound: "" }
      sequence-watermarking-enabled: false
    mekya-config:
      enable: true
      max-write-size: 10485760
      max-write-duration-ms: 5000
      max-simultaneous-write-connection: 128
      packet-writing-buffer: 65536
      kcp:
        mtu: 1350
        tti: 15
        uplink-capacity: 40
        downlink-capacity: 2000
        congestion: false
        write-buffer: 67108864
        read-buffer: 67108864
        seed: ""
        header: ""
    mkcp-config:
      enable: false
      mtu: 1350
      tti: 50
      uplink-capacity: 5
      downlink-capacity: 20
      congestion: false
      write-buffer: 2097152
      read-buffer: 2097152
      seed: ""
      header: ""
    mux-option:
      padding: true
      brutal: { enabled: true, up: 1000, down: 1000 }
```

### 5.9 `vless`

| Key | Type | Required | Meaning | Doc |
|---|---|---|---|---|
| `users` | array `{username, uuid, flow}` (`uuid` required) | **yes** | Users; `flow` e.g. `xtls-rprx-vision` | ✅ |
| `decryption` | string | no | Server-side VLESS encryption string (e.g. `mlkem768x25519plus.…`); generate keys with `mihomo generate vless-x25519` / `vless-mlkem768` | ✅ |
| `ws-path` | string | no | WebSocket transport | ✅ |
| `xhttp-config` | map | no | §4.8 | ✅ |
| `grpc-service-name` | string | no | gRPC transport | ✅ |
| `certificate` / `private-key` | string | no | TLS | ✅ |
| `client-auth-type` / `client-auth-cert` | string | no | mTLS | ✅ |
| `ech-key` | string | no | ECH | ✅ |
| `allow-insecure` | bool | no | Permit running with no TLS layer (default false) | ✅ |
| `shadow-tls` / `res-tls` / `jls-config` / `reality-config` | map | no | §4.4 / §4.3 | ✅ |
| `mux-option` | map | no | §4.6 | ✅ |

Rule: unless `allow-insecure: true`, at least one of `certificate`+`private-key`, `shadow-tls`, `res-tls`, `jls-config`, `reality-config`, or `decryption` must be present.

```yaml
listeners:
  - name: vless-in-1
    type: vless
    port: 10817
    listen: 0.0.0.0
    users:
      - username: "1"
        uuid: 9d0cb9d0-964f-4ef6-897d-6c6b3ccf9e68
        flow: xtls-rprx-vision
    decryption: "mlkem768x25519plus.native.600s"
    ws-path: "/"
    xhttp-config:
      path: "/"
      host: ""
      mode: auto
      no-sse-header: false
      x-padding-bytes: "100-1000"
      x-padding-obfs-mode: false
      x-padding-key: x_padding
      x-padding-header: Referer
      x-padding-placement: queryInHeader
      x-padding-method: repeat-x
      uplink-http-method: POST
      session-placement: path
      session-key: ""
      session-table: ""
      session-length: "16-32"
      seq-placement: path
      seq-key: ""
      uplink-data-placement: body
      uplink-data-key: ""
      uplink-chunk-size: 0
      sc-max-buffered-posts: 30
      sc-stream-up-server-secs: "20-80"
      sc-max-each-post-bytes: 1000000
    grpc-service-name: "GunService"
    allow-insecure: false
    certificate: ./server.crt
    private-key: ./server.key
    reality-config:
      dest: test.com:443
      private-key: jNXHt1yRo0vDuchQlIP6Z0ZvjT3KtzVI-T4E7RoLJS0
      short-id: ["0123456789abcdef"]
      server-names: ["test.com"]
```

### 5.10 `trojan`

| Key | Type | Required | Meaning | Doc |
|---|---|---|---|---|
| `users` | array `{username, password}` (`password` required) | **yes** | Users | ✅ |
| `ws-path` | string | no | WebSocket transport | ✅ |
| `grpc-service-name` | string | no | gRPC transport | ✅ |
| `certificate` / `private-key` | string | no | TLS | ✅ |
| `client-auth-type` / `client-auth-cert` | string | no | mTLS | ✅ |
| `ech-key` | string | no | ECH | ✅ |
| `allow-insecure` | bool | no | No-TLS mode | ✅ |
| `shadow-tls` / `res-tls` / `jls-config` / `reality-config` | map | no | §4.4 / §4.3 | ✅ |
| `ss-option` | map | no | §4.9 (trojan-go style SS) | ✅ |
| `mux-option` | map | no | §4.6 | ✅ |

Rule: unless `allow-insecure: true`, at least one of cert+key, `shadow-tls`, `res-tls`, `jls-config`, `reality-config`, or `ss-option` must be present.

```yaml
listeners:
  - name: trojan-in-1
    type: trojan
    port: 10819
    listen: 0.0.0.0
    users:
      - username: "1"
        password: 9d0cb9d0-964f-4ef6-897d-6c6b3ccf9e68
    ws-path: "/"
    grpc-service-name: "GunService"
    certificate: ./server.crt
    private-key: ./server.key
    allow-insecure: false
    ss-option:
      enabled: false
      method: aes-128-gcm
      password: "example"
    mux-option:
      padding: true
      brutal: { enabled: true, up: 1000, down: 1000 }
```

### 5.11 `anytls`

| Key | Type | Required | Meaning | Doc |
|---|---|---|---|---|
| `users` | **map** `username: password` | no | Authentication | ✅ |
| `certificate` / `private-key` | string | no | TLS (must be filled when no shadow-tls/res-tls/jls and not allow-insecure) | ✅ |
| `client-auth-type` / `client-auth-cert` | string | no | mTLS | ✅ |
| `ech-key` | string | no | ECH | ✅ |
| `shadow-tls` / `res-tls` / `jls-config` | map | no | §4.4 (mutually exclusive with cert+key) | ✅ |
| `allow-insecure` | bool | no | No-TLS mode | ✅ |
| `padding-scheme` | string | no | AnyTLS padding scheme (`CmdUpdatePaddingScheme`) | ✅ |

Rule: unless `allow-insecure: true`, at least one of cert+key, `shadow-tls`, `res-tls`, `jls-config` must be present.

```yaml
listeners:
  - name: anytls-in-1
    type: anytls
    port: 10818
    listen: 0.0.0.0
    users:
      username1: password1
      username2: password2
    certificate: ./server.crt
    private-key: ./server.key
    allow-insecure: false
    padding-scheme: ""
```

### 5.12 `mieru`

| Key | Type | Required | Default | Meaning | Doc |
|---|---|---|---|---|---|
| `transport` | string | **yes** | — | `TCP` or `UDP` (exact, uppercase) | ✅ |
| `users` | **map** `username: password` | **yes** | — | Must be non-empty | ✅ |
| `traffic-pattern` | string (base64) | no | `""` | Fine-tune network behaviour | ✅ |
| `user-hint-is-mandatory` | bool | no | false | Reject clients that send no user hint | ✅ |

```yaml
listeners:
  - name: mieru-in-1
    type: mieru
    port: 10818
    listen: 0.0.0.0
    transport: TCP
    users:
      username1: password1
      username2: password2
    traffic-pattern: ""
    user-hint-is-mandatory: false
```

### 5.13 `sudoku`

Only a **single port** is supported (no port ranges).

| Key | Type | Required | Meaning | Doc |
|---|---|---|---|---|
| `key` | string | **yes** | ED25519 public key from a Sudoku keypair, or any UUID used as a shared key | ✅ |
| `aead-method` | string | no | `chacha20-poly1305` \| `aes-128-gcm` \| `none` (not recommended) | ✅ |
| `padding-min` | int | no | Minimum padding ratio (0–100) | ✅ |
| `padding-max` | int | no | Maximum padding ratio (0–100, `>= padding-min`) | ✅ |
| `table-type` | string | no | `prefer_ascii` \| `prefer_entropy` \| `up_ascii_down_entropy` \| `up_entropy_down_ascii` | ✅ |
| `custom-table` | string | no | Byte layout with 2×`x`, 2×`p`, 4×`v` (entropy direction only) | ✅ (commented) |
| `custom-tables` | array of string | no | Rotating layouts; overrides `custom-table` when non-empty | ✅ (commented) |
| `handshake-timeout` | int (s) | no | Handshake timeout | ✅ (commented) |
| `enable-pure-downlink` | bool | no | false = bandwidth-optimised downlink; true = pure Sudoku downlink | ✅ |
| `httpmask` | map | no | `{disable: bool, mode: legacy\|stream\|poll\|auto\|ws, path-root: string}` | ✅ |
| `disable-http-mask` | bool | no | Legacy flat form of `httpmask.disable` | ✅ (commented) |
| `http-mask-mode` | string | no | Legacy flat form of `httpmask.mode` (default `legacy`) | ✅ (commented) |
| `path-root` | string | no | Legacy flat form of `httpmask.path-root` | ✅ (commented) |
| `fallback` | string | no | Forward rejected HTTPMask traffic to `host:port` | ✅ (commented) |
| `mux-option` | map | no | §4.6 | ✅ (commented) |

```yaml
listeners:
  - name: sudoku-in-1
    type: sudoku
    port: 8443
    listen: 0.0.0.0
    key: "<server_key>"
    aead-method: chacha20-poly1305
    padding-min: 1
    padding-max: 15
    table-type: prefer_ascii
    custom-table: xpxvvpvv
    custom-tables: ["xpxvvpvv", "vxpvxvvp"]
    handshake-timeout: 5
    enable-pure-downlink: false
    httpmask:
      disable: false
      mode: legacy
      path-root: ""
    fallback: "127.0.0.1:80"
    mux-option:
      padding: true
      brutal: { enabled: true, up: 1000, down: 1000 }
```

### 5.14 `tuic` — covers TUIC **v4** and **v5** (one type string)

| Key | Type | Required | Default | Meaning | Doc |
|---|---|---|---|---|---|
| `token` | array of string | v4: yes (if no users) | — | **TUIC v4** tokens. Cannot be used together with `users`. | ✅ (v4 page) |
| `users` | **map** `UUID: PASSWORD` | v5: yes (if no token) | — | **TUIC v5** users. Cannot be used together with `token`. | ✅ (v5 page) |
| `certificate` | string | **yes** | — | TLS cert (PEM/path) | ✅ |
| `private-key` | string | **yes** | — | TLS key | ✅ |
| `client-auth-type` / `client-auth-cert` | string | no | `""` / — | mTLS | ✅ |
| `ech-key` | string | no | — | ECH | ✅ |
| `congestion-controller` | string | no | `bbr` | QUIC CC | ✅ |
| `max-idle-time` | int (ms) | no | `15000` | Max idle | ✅ |
| `authentication-timeout` | int (ms) | no | `1000` | Auth timeout | ✅ |
| `alpn` | array of string | no | `[h3]` | ALPN | ✅ |
| `max-udp-relay-packet-size` | int | no | `1500` | Max relayed UDP packet | ✅ |
| `bbr-profile` | string | no | `standard` | `standard`/`conservative`/`aggressive` | ✅ (commented) |
| `cwnd` | int | no | — | Initial congestion window | ⚠️**src** |
| `mux-option` | map | no | — | §4.6 | ✅ (commented) |

How to pick v4 vs v5: presence of `token` ⇒ v4; presence of `users` (UUID map) ⇒ v5. Both use `type: tuic`.

```yaml
listeners:
  # TUIC v4
  - name: tuicv4-in
    type: tuic
    port: 10003
    listen: 0.0.0.0
    token:
      - TOKEN
    certificate: ./server.crt
    private-key: ./server.key
    congestion-controller: bbr
    bbr-profile: standard
    max-idle-time: 15000
    authentication-timeout: 1000
    alpn: [h3]
    max-udp-relay-packet-size: 1500
    mux-option:
      padding: true
      brutal: { enabled: true, up: 1000, down: 1000 }
  # TUIC v5
  - name: tuicv5-in
    type: tuic
    port: 10004
    listen: 0.0.0.0
    users:
      00000000-0000-0000-0000-000000000000: PASSWORD-0
      00000000-0000-0000-0000-000000000001: PASSWORD-1
    certificate: ./server.crt
    private-key: ./server.key
    congestion-controller: bbr
    max-idle-time: 15000
    authentication-timeout: 1000
    alpn: [h3]
    max-udp-relay-packet-size: 1500
```

### 5.15 `shadowquic`

| Key | Type | Required | Default | Meaning | Doc |
|---|---|---|---|---|---|
| `users` | array `{username, password}` (both required per element) | no | — | Authentication | ✅ |
| `jls-upstream` | map | **yes** | — | Fallback QUIC camouflage upstream | ✅ |
| `jls-upstream.addr` | string | **yes** | — | Camouflage upstream addr | ✅ |
| `jls-upstream.sni` | string | no | inferred from `addr` | Camouflage SNI | ✅ |
| `jls-upstream.proxy` | string | no | — | Proxy for upstream | ✅ |
| `jls-upstream.rate-limit` | int (bit/s) | no | 0 | Rate limit, 0 = unlimited | ✅ |
| `alpn` | array of string | no | `[h3]` | ALPN | ✅ |
| `quic-versions` | array of string | no | (nil; docs example `[v1]`) | `v1`/`v2` | ✅ |
| `zero-rtt` | bool | no | `true` | 0-RTT | ✅ |
| `congestion-controller` | string | no | `bbr` | `cubic`/`new_reno`/`bbr` | ✅ |
| `up` / `down` | string/int (Mbps) | no | — | Brutal bandwidth negotiation | ✅ |
| `ignore-client-bandwidth` | bool | no | false | Use listener `down` instead of client's | ✅ |
| `cwnd` | int | no | `32` | Initial congestion window | ✅ |
| `bbr-profile` | string | no | `standard` | BBR aggressiveness | ✅ |
| `max-idle-time` | int (ms) | no | `30000` | Max idle | ✅ |
| `max-datagram-frame-size` | int | no | `1400` | Max UDP datagram frame | ✅ |
| `recv-window-conn` | int | no | 0 (internal default) | Connection receive window | ✅ |
| `recv-window` | int | no | 0 (internal default) | Stream receive window | ✅ |
| `disable-mtu-discovery` | bool | no | false | Disable path MTU discovery | ✅ |
| `mux-option` | map | no | — | §4.6 | ⚠️**src** |

```yaml
listeners:
  - name: shadowquic-in-1
    type: shadowquic
    port: 10822
    listen: 0.0.0.0
    users:
      - username: username
        password: password
    jls-upstream:
      addr: www.example.com:443
      sni: example.com
      proxy: proxy
      rate-limit: 0
    alpn: [h3]
    quic-versions: [v1]
    zero-rtt: true
    congestion-controller: bbr
    up: 100 Mbps
    down: 100 Mbps
    ignore-client-bandwidth: false
    cwnd: 10
    bbr-profile: ""
    max-idle-time: 30000
    max-datagram-frame-size: 1400
    recv-window-conn: 0
    recv-window: 0
    disable-mtu-discovery: false
    mux-option:
      padding: true
      brutal: { enabled: true, up: 1000, down: 1000 }
```

### 5.16 `hysteria2`

| Key | Type | Required | Default | Meaning | Doc |
|---|---|---|---|---|---|
| `users` | **map** `user: password` | no | — | Auth; username only used for inbound rules | ✅ |
| `up` / `down` | int (Mbps) | no | — | Bandwidth | ✅ |
| `ignore-client-bandwidth` | bool | no | false | Always use BBR, ignore client bandwidth | ✅ |
| `obfs` | string | no | — | Only `salamander`; empty disables | ✅ |
| `obfs-password` | string | no | — | Obfs password | ✅ |
| `obfs-min-packet-size` | int | no | — | Obfs min packet size | ⚠️**src** |
| `obfs-max-packet-size` | int | no | — | Obfs max packet size | ⚠️**src** |
| `masquerade` | string | no | — | `file:///path` or `http(s)://host:port`; empty → 404 | ✅ |
| `bbr-profile` | string | no | `standard` | `standard`/`conservative`/`aggressive` | ✅ (commented) |
| `realm-opts` | map | no | — | Hysteria2 Realm client options for this server | ✅ (commented) |
| `alpn` | array of string | no | `h3` | ALPN | ✅ |
| `certificate` | string | **yes** | — | TLS cert | ✅ |
| `private-key` | string | **yes** | — | TLS key | ✅ |
| `client-auth-type` / `client-auth-cert` | string | no | `""` / — | mTLS | ✅ |
| `ech-key` | string | no | — | ECH | ✅ |
| `max-idle-time` | int | no | — | Max idle | ⚠️**src** |
| `cwnd` | int | no | — | Congestion window | ⚠️**src** |
| `udp-mtu` | int | no | — | QUIC UDP MTU | ⚠️**src** |
| `initial-stream-receive-window` | int | no | — | QUIC stream recv window | ⚠️**src** |
| `max-stream-receive-window` | int | no | — | QUIC stream recv window max | ⚠️**src** |
| `initial-connection-receive-window` | int | no | — | QUIC conn recv window | ⚠️**src** |
| `max-connection-receive-window` | int | no | — | QUIC conn recv window max | ⚠️**src** |
| `mux-option` | map | no | — | §4.6 | ✅ (commented) |

`realm-opts` children (all source `inbound` tags; `enable` must be set to `true` manually):

| Key | Type | Doc |
|---|---|---|
| `enable` | bool | ✅ (comment "must be enabled manually") |
| `server-url` | string | ✅ |
| `token` | string | ✅ |
| `realm-id` | string | ✅ |
| `stun-servers` | array of string | ✅ |
| `sni` | string | ✅ (page comment lists TLS options) |
| `skip-cert-verify` | bool | ✅ |
| `name-cert-verify` | string | ✅ |
| `fingerprint` | string | ✅ |
| `certificate` | string | ✅ |
| `private-key` | string | ✅ |
| `alpn` | array of string | ✅ |
| `proxy` | string | ✅ |

```yaml
listeners:
  - name: hy-in
    type: hysteria2
    port: 8443
    listen: 0.0.0.0
    users:
      user1: password1
      user2: password2
    up: 1000
    down: 1000
    ignore-client-bandwidth: false
    obfs: salamander
    obfs-password: password
    obfs-min-packet-size: 512
    obfs-max-packet-size: 1200
    masquerade: "https://www.example.com"
    bbr-profile: standard
    realm-opts:
      enable: true
      server-url: https://realm.hy2.io
      token: public
      realm-id: my-cabin-1f3a8c2e9b
      stun-servers:
        - stun.nextcloud.com:3478
        - stun.sip.us:3478
        - global.stun.twilio.com:3478
      proxy: DIRECT
      sni: example.com
      skip-cert-verify: false
      name-cert-verify: example.com
      fingerprint: ""
      certificate: ""
      private-key: ""
      alpn: [h3]
    alpn: [h3]
    certificate: ./server.crt
    private-key: ./server.key
    mux-option:
      padding: true
      brutal: { enabled: true, up: 1000, down: 1000 }
```

### 5.17 `hysteria2-realm`

This is **not** a Hysteria2 proxy server: it is the HTTP/HTTPS "realm" registry that self-hosted Hysteria2 inbounds/outbounds point at through `realm-opts.server-url`.

| Key | Type | Required | Default | Meaning | Doc |
|---|---|---|---|---|---|
| `token` | string | **yes** | — | Bearer token (`Authorization: Bearer <token>`) | ✅ |
| `max-realms` | int | no | `65536` (0 = unlimited) | Max total realms | ✅ |
| `max-realms-per-ip` | int | no | `4` (0 = unlimited) | Max realms per client IP | ✅ |
| `trusted-proxy-header` | string | no | `""` | Header carrying real client IP (e.g. `X-Forwarded-For`) | ✅ |
| `realm-name-pattern` | string (regex) | no | `^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$` | Allowed realm-name pattern | ✅ |
| `certificate` / `private-key` | string | no | — | Configure both ⇒ HTTPS; absent ⇒ plaintext HTTP | ✅ |
| `client-auth-type` / `client-auth-cert` | string | no | `""` / — | mTLS | ✅ |
| `ech-key` | string | no | — | ECH | ✅ |
| `alpn` | array of string | no | `["h2","http/1.1"]` | ALPN | ✅ |

```yaml
listeners:
  - name: hysteria2-realm-in-1
    type: hysteria2-realm
    port: 10820
    listen: 0.0.0.0
    token: public
    max-realms: 65536
    max-realms-per-ip: 4
    trusted-proxy-header: ""
    realm-name-pattern: "^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$"
    certificate: ./server.crt
    private-key: ./server.key
    alpn: ["h2", "http/1.1"]
```

### 5.18 `trusttunnel`

| Key | Type | Required | Meaning | Doc |
|---|---|---|---|---|
| `users` | array `{username, password}` | no | Auth | ✅ |
| `certificate` / `private-key` | string | **yes** | TLS (required) | ✅ |
| `network` | array of string | no | `[]`/`["tcp"]` ⇒ HTTP/2 only; include `udp` ⇒ HTTP/3 | ✅ |
| `congestion-controller` | string | no | QUIC CC (e.g. `bbr`) | ✅ |
| `cwnd` | int | no | Initial congestion window | ⚠️**src** |
| `bbr-profile` | string | no | `standard`/`conservative`/`aggressive` | ✅ (commented) |
| `client-auth-type` / `client-auth-cert` | string | no | mTLS | ✅ |
| `ech-key` | string | no | ECH | ✅ |

```yaml
listeners:
  - name: trusttunnel-in-1
    type: trusttunnel
    port: 10821
    listen: 0.0.0.0
    users:
      - username: "1"
        password: 9d0cb9d0-964f-4ef6-897d-6c6b3ccf9e68
    certificate: ./server.crt
    private-key: ./server.key
    network: ["tcp", "udp"]
    congestion-controller: bbr
    bbr-profile: standard
```

### 5.19 `tunnel` (plain port forwarding)

| Key | Type | Required | Meaning | Doc |
|---|---|---|---|---|
| `network` | array of string | **yes** | `[tcp]`, `[udp]`, or `[tcp, udp]` | ✅ |
| `target` | string | **yes** | Forward target `host:port` (no scheme) | ✅ |

```yaml
listeners:
  - name: tunnel-in
    type: tunnel
    port: 10816
    listen: 0.0.0.0
    network: [tcp, udp]
    target: target.com
```

### 5.20 `snell`

| Key | Type | Required | Default | Meaning | Doc |
|---|---|---|---|---|---|
| `psk` | string | **yes** | — | Pre-shared key / password | ✅ |
| `version` | int | no | `4` (valid 1–5; other values error) | Snell protocol version | ✅ |
| `udp` | bool | no | **true** | UDP over TCP tunnel | ✅ |
| `obfs-opts` | map | no | — | `{mode: http\|tls, host: "bing.com"}` | ✅ |
| `shadow-tls` | map | no | — | §4.4 | ✅ |
| `res-tls` | map | no | — | §4.4 | ✅ |
| `jls-config` | map | no | — | §4.4 | ✅ |

`shadow-tls`, `res-tls`, `jls-config` are **mutually exclusive**.

```yaml
listeners:
  - name: snell-in-1
    type: snell
    port: 10815
    listen: 0.0.0.0
    psk: your-password
    version: 4
    udp: true
    obfs-opts:
      mode: http      # http | tls
      host: bing.com
    shadow-tls:
      enable: false
      version: 3
      password: password
      users:
        - name: "1"
          password: password
      handshake:
        dest: test.com:443
    res-tls:
      enable: false
      dest: test.com:443
      password: restls-password
      rate-limit: 0
    jls-config:
      enable: false
      users:
        - username: jls-user
          password: jls-password
      dest: test.com:443
```

---

## 6. Shortcut / legacy inbounds and how they map

| Top-level key | YAML shape | Maps to | Notes |
|---|---|---|---|
| `ss-config` | **string** — a standard `ss://` URI: `ss://CIPHER:PASSWORD@:PORT` | one `listeners[]` entry of `type: shadowsocks` | No per-protocol map form; URI only. |
| `vmess-config` | **string** — `vmess://USER:UUID@:PORT` | one `listeners[]` entry of `type: vmess` | URI only. |
| `tuic-server` | **map** (`RawTuicServer`) | one `listeners[]` entry of `type: tuic` | Uses `token` ⇒ v4, `users` ⇒ v5 (same exclusivity as the listener). |

`tuic-server` map fields (exact keys, from `RawTuicServer`):

| Key | Type | Note |
|---|---|---|
| `enable` | bool | Must be `true` to activate |
| `listen` | string | `ip:port` (single combined field, unlike the listener's separate `listen`+`port`) |
| `token` | array of string | TUIC v4 |
| `users` | map `UUID: PASSWORD` | TUIC v5 |
| `certificate` | string | TLS cert |
| `private-key` | string | TLS key |
| `congestion-controller` | string | default `bbr` |
| `max-idle-time` | int | default `15000` |
| `authentication-timeout` | int | default `1000` |
| `alpn` | array of string | default `[h3]` |
| `max-udp-relay-packet-size` | int | default `1500` |
| `cwnd` | int | (source) |

```yaml
ss-config: ss://2022-blake3-aes-256-gcm:vlmpIPSyHH6f4S8WVPdRIHIlzmB+GIRfoH3aNJ/t9Gg=@:23456
vmess-config: vmess://1:9d0cb9d0-964f-4ef6-897d-6c6b3ccf9e68@:12345
tuic-server:
  enable: true
  listen: 127.0.0.1:10443
  token:
    - TOKEN
  # users:
  #   00000000-0000-0000-0000-000000000000: PASSWORD-0
  certificate: ./server.crt
  private-key: ./server.key
  congestion-controller: bbr
  max-idle-time: 15000
  authentication-timeout: 1000
  alpn: [h3]
  max-udp-relay-packet-size: 1500
```

Docs explicitly say: shortcuts are legacy/compat; prefer `listeners` for new configs needing extra protocol options or independent routing. There is **no** `ss-config` map form and **no** shortcut for any other protocol.

---

## 7. Sniffing options (global — there is NO per-listener `sniff` field)

Verified: no `inbound:"sniff…"` tag exists on any listener struct in `listener/inbound/*.go`. Sniffing is configured once at the top level under `sniffer:`.

Current form (documented, `docs/config/sniff/index.en.md`):

| Key | Type | Default | Meaning |
|---|---|---|---|
| `enable` | bool | `false` | Master switch |
| `force-dns-mapping` | bool | `true` | Also sniff traffic resolved via `redir-host` mapping |
| `parse-pure-ip` | bool | `true` | Sniff even when no domain was obtained |
| `override-destination` | bool | `true` | Use sniffed value as the real destination |
| `sniff` | **map** of protocol → options | — | Protocols: `HTTP`, `TLS`, `QUIC` (those exact strings) |
| `sniff.<PROTO>.ports` | array of string | — | Ports / ranges, e.g. `[80, 8080-8880]` |
| `sniff.<PROTO>.override-destination` | bool | — | Per-protocol override of the global value |
| `force-domain` | array of string | — | Domains always sniffed (wildcards `+.v2ex.com`) |
| `skip-domain` | array of string | — | Domains never sniffed (wildcards) |
| `skip-src-address` | array of CIDR | — | Source IPs excluded |
| `skip-dst-address` | array of string | — | Destination IPs/ranges excluded |

Legacy/source-only keys still decoded (`RawSniffer`): `sniffing` (array of string, legacy protocol list), `port-whitelist` (array of string — this is the YAML key for the Go field named `Ports`). Prefer the `sniff` map form; the docs table-of-contents only lists the keys above.

```yaml
sniffer:
  enable: false
  force-dns-mapping: true
  parse-pure-ip: true
  override-destination: false
  sniff:
    HTTP:
      ports: [80, 8080-8880]
      override-destination: true
    TLS:
      ports: [443, 8443]
    QUIC:
      ports: [443, 8443]
  force-domain:
    - +.v2ex.com
  skip-domain:
    - "Mijia Cloud"
  skip-src-address:
    - 192.168.0.3/32
  skip-dst-address:
    - 192.168.0.3/32
```

---

## 8. Fields present in source but NOT documented on the listener wiki page

Emit only if you want to expose advanced options; the wiki does not document them (mark as ⚠️**src** in the UI):

* `http` / `socks` / `mixed`: `reality-config` (the wiki examples for these three omit REALITY even though the source wires it into the TLS `AuthServer`).
* `vmess` `mekya-config`: `url`, `h2-pool-size`, `max-write-delay`, `max-request-size`, `polling-interval-initial`.
* `hysteria2`: `obfs-min-packet-size`, `obfs-max-packet-size`, `max-idle-time`, `cwnd`, `udp-mtu`, `initial-stream-receive-window`, `max-stream-receive-window`, `initial-connection-receive-window`, `max-connection-receive-window`.
* `tuic`: `cwnd`.
* `trusttunnel`: `cwnd`.
* `shadowquic`: `mux-option`.
* `shadow-tls`: `handshake-for-server-name`, `strict-mode`, `wildcard-sni`.
* `ss` `kcp-tun`: `nc` (disable congestion control).
* TUN listener: `auto-redirect-input-mark`, `auto-redirect-output-mark`, `auto-redirect-iproute2-fallback-rule-index`, `loopback-address`, `exclude-src-port`, `exclude-src-port-range`, `exclude-dst-port`, `exclude-dst-port-range`, `icmp-timeout`, `disable-icmp-forwarding`, `file-descriptor`, `recvmsgx`, `sendmsgx`, `processors-per-channel`.
* Top-level sniffer: `sniffing`, `port-whitelist`.

Conversely, **every** key shown in the wiki listener examples exists in source — no phantom wiki keys were found.

---

## 9. Documentation vs code discrepancies worth knowing

1. **`type` for redirect is `redir`**, not `redirect` (wiki page title is misleading).
2. **TUIC v4 and v5 share `type: tuic`**; the wiki presents them as separate pages. Differentiation is `token` (v4) vs `users` (v5).
3. **`users` container shape differs per protocol** (array vs map) and is the single most common generator bug. See §4.1.
4. **`udp` is not a universal listener field** even though the "Inbounds" overview example comments it. Only `socks`, `mixed`, `tproxy`, `shadowsocks`, `snell` decode `udp`. Setting it elsewhere is silently ignored.
5. **`listen` must be a bare IP** (`netip.ParseAddr`); hostnames/CIDRs are rejected. Empty ⇒ `0.0.0.0`.
6. **`port` is a string in the Go struct** and goes through `utils.NewUnsignedRanges[uint16]`; use the ports-format string, not a YAML list.
7. **TLS naming**: listeners use `certificate` / `private-key` (PEM text or path). There is no `*-path` variant on listeners; the top-level `tls:` block uses the same two names.
8. **`certificate`+`private-key` vs `reality-config`**: mutually exclusive on vmess/vless/trojan (documented). For real TLS you must set both; setting only one is a config error at runtime.
9. **`allow-insecure` exists only on `vless`, `trojan`, `anytls`** (not vmess, not http/socks/mixed).
10. **Required-by-decoder vs required-by-protocol** differ. E.g. `hysteria2.certificate`/`private-key` are parser-required (no `omitempty`), while `anytls.certificate` is parser-optional but protocol-mandatory unless another TLS layer is present. See §10.
11. The `redirect` listener is **TCP-only**; `tproxy` handles TCP+UDP; the ports are OS-restricted (redirect: Linux/Android + macOS; tproxy: Linux/Android; `routing-mark`: Linux only).
12. `tun` listener supports `inet4-address` (listener page) whereas the top-level `tun` doc uses `inet6-address` and legacy `inet4-route-address`/`inet6-route-address`; both forms exist in source.
13. `route-address-set` / `route-exclude-address-set` **conflict with any `routing-mark`** (documented in the top-level TUN page).

---

## 10. Required-field matrix (parse fails if absent)

| Type | Required keys (must be present in YAML) |
|---|---|
| all | `name`, `type` (decoder-enforced); `port` for every socket type (`tun` ignores it) |
| `shadowsocks` | `cipher`, `password` |
| `vmess` | `users` (each element: `uuid`) |
| `vless` | `users` (each element: `uuid`) |
| `trojan` | `users` (each element: `password`) |
| `mieru` | `transport`, `users` (non-empty) |
| `sudoku` | `key` |
| `tuic` | `certificate`, `private-key` |
| `hysteria2` | `certificate`, `private-key` |
| `hysteria2-realm` | `token` |
| `trusttunnel` | `certificate`, `private-key` |
| `shadowquic` | `jls-upstream` (with `addr`) |
| `tunnel` | `network`, `target` |
| `snell` | `psk` |
| nested `shadow-tls` | `enable`; if `handshake` present: `handshake.dest` |
| nested `res-tls` | `enable`, `dest`, `password` |
| nested `jls-config` | `enable`, `users` (each: `username`,`password`), `dest` |
| nested `reality-config` | `dest`, `private-key`, `short-id`, `server-names` |
| nested `kcp-tun` | `enable` |
| `http`/`socks`/`mixed`/`anytls`/`tproxy`/`redir`/`tun`/`tunnel`(net/target aside) | no protocol-specific required keys |

Because unknown keys are ignored, a generator that misspells an optional key will silently produce a config that drops the option (no error). Validate against the exact spellings in this document.
