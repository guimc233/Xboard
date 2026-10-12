# mihomo (Clash.Meta) OUTBOUND `proxies:` spec — implementation-ready

Sources: <https://wiki.metacubex.one/en/config/proxies/> + each `/en/config/proxies/<name>/`,
`/en/config/proxies/tls/`, `/en/config/proxies/transport/`, `/en/config/proxies/dialer-proxy/`;
plus `MetaCubeX/mihomo@Meta` source: `adapter/parser.go` (type switch), `adapter/outbound/*.go`
(option structs = the exact `proxy:"..."` YAML tags), `common/structure/structure.go` (decoder),
`transport/*` (mode constants, defaults).

Legend: **wiki** = stated in docs · **src** = verified only in kernel source (undocumented on wiki).

---

## 0. Decoder semantics — read this first (PHP builder critical)

`adapter/parser.go`:
```go
decoder := structure.NewDecoder(structure.Option{
    TagName: "proxy", WeaklyTypedInput: true, KeyReplacer: structure.DefaultKeyReplacer})
```
`common/structure/structure.go`: `DefaultKeyReplacer = strings.NewReplacer("_", "-")`.

1. **Key matching**: first an exact tag match; otherwise each key is `_`→`-` replaced and compared with
   `strings.EqualFold` (case-insensitive).
   - ✅ `alterId`, `alterid`, `ALTERID`, `alter_id` all resolve to tag `alterId`.
   - ✅ `skip_cert_verify`, `Skip-Cert-Verify` → `skip-cert-verify`.
   - ✅ wiki's own `hysteria.disable_mtu_discovery` works as `disable-mtu-discovery`.
   - ❌ Dashes are **not** removed: `skipcertverify` does **not** match. Emit exact kebab-case keys.
2. **Unknown keys are silently ignored** (no error) — a typo produces a silently wrong config, not a
   parse failure. Exception: `ss.plugin-opts` and `snell.obfs-opts` are `map[string]any`, decoded again
   with `TagName: "obfs"` (same rules).
3. **Missing required fields** (tag without `,omitempty`) abort the config:
   `'<StructName>' has unset fields: a, b`.
4. **`WeaklyTypedInput: true`**: `"443"`→int, `"true"`→bool, `50`→string all accepted. PHP may emit
   native YAML scalars everywhere; only nested maps/lists must keep their shape.
5. **Aliases are only case + `_`↔`-`.** There is **no** `sni`↔`servername` alias (see §8.5/§8.6).
6. `type` must be an exact lower-case string from the switch in §1 (no case folding, no aliases).
7. `structure`s `Decode` rejects nothing on extra keys, but `omitempty` fields that are *present* are
   honored (an empty list `[]` is still "set" and overrides defaults in some protocols).

---

## 1. Accepted `type:` strings (exact)

From the `switch proxyType` in `adapter/parser.go` (anything else → `unsupport proxy type: X`):

| `type:` | struct | notes |
|---|---|---|
| `ss` | `ShadowSocksOption` | Shadowsocks |
| `ssr` | `ShadowSocksROption` | ShadowsocksR |
| `socks5` | `Socks5Option` | **not** `socks` — `type: socks` is a hard error |
| `http` | `HttpOption` | HTTP CONNECT |
| `vmess` | `VmessOption` | |
| `vless` | `VlessOption` | |
| `snell` | `SnellOption` | |
| `trojan` | `TrojanOption` | |
| `hysteria` | `HysteriaOption` | v1 |
| `hysteria2` | `Hysteria2Option` | |
| `wireguard` | `WireGuardOption` | **not** `wg` |
| `tuic` | `TuicOption` | v4 (`token`) / v5 (`uuid`+`password`) |
| `shadowquic` | `ShadowQuicOption` | |
| `anytls` | `AnyTLSOption` | |
| `mieru` | `MieruOption` | |
| `sudoku` | `SudokuOption` | |
| `ssh` | `SshOption` | |
| `direct`, `dns`, `reject`, `rematch`, `gost-relay`, `masque`, `trusttunnel`, `openvpn`, `tailscale`, `zerotier`, `easytier` | — | beyond scope; `direct` covered in §9 |

> ⚠️ The wiki `dialer-proxy` page contains `- { name: "socks1", type: "socks" }`. That example is
> wrong for mihomo; only `socks5` exists.

---

## 2. Common fields (embedded `BasicOption` — valid on **every** type)

| key | type | default | meaning |
|---|---|---|---|
| `name` | string | required | unique proxy name |
| `type` | string | required | see §1 |
| `server` | string | required* | host/IP (`*` optional on wireguard peers / hysteria with `ports`) |
| `port` | int | required* | |
| `ip-version` | string | `dual` | `dual`/`ipv4`/`ipv6`/`ipv4-prefer`/`ipv6-prefer` — affects resolution of `server` only |
| `tfo` | bool | `false` | TCP Fast Open (TCP-dialing types only, see §3) |
| `mptcp` | bool | `false` | TCP MultiPath (TCP-dialing types only) |
| `interface-name` | string | — | bind outgoing sockets to an interface |
| `routing-mark` | int | `0` | SO_MARK on outgoing sockets |
| `dialer-proxy` | string | — | name of another outbound **or of a proxy-group**; this node's connections are made through it |
| `udp` | bool | `false` | per-type; see §3 |
| `smux` | map | disabled | sing-mux; see §2.1 |

### 2.1 `smux` (decoded for **every** type, applied only when `enabled: true`)

`outbound.SingMuxOption`:
```yaml
smux:
  enabled: true              # bool, default false  — if false the whole block is ignored
  protocol: h2mux            # smux | yamux | h2mux   (default h2mux)
  max-connections: 4         # int — conflicts with max-streams
  min-streams: 4             # int — conflicts with max-streams
  max-streams: 0             # int — conflicts with max-connections & min-streams
  statistic: false           # bool — show the underlying conn in the dashboard
  only-tcp: false            # bool — if true, UDP bypasses smux
  padding: true              # bool
  brutal-opts:
    enabled: true            # bool
    up: 50                   # string (Mbps) — src: struct field is `string`; weakly-typed, 50 ok
    down: 100                # string (Mbps)
```
`smux` only makes sense for protocols with a TCP transport (ss/socks5/http/vmess/vless/trojan/
snell/anytls/ssh); it is accepted (and silently useless) elsewhere.

### 2.2 `dialer-proxy` interaction (src, important)

`base.go:NewDialer` — when `dialer-proxy` is non-empty, `dialer.NewDialer(opts...)` is **not** called,
so `interface-name`, `routing-mark`, `ip-version`, `tfo`, `mptcp` are **dropped for that node**; the
connection is made by the referenced proxy/group instead. Proxy-groups accept `dialer-proxy` only
through `proxy-providers[].override` (groups have no `dialer-proxy` field of their own).

---

## 3. Capability matrix (verified from each `NewBase(BaseOption{...})`)

`udp` is **not** part of `BasicOption` — each protocol declares it individually (or not at all).
A `udp:` key on a type that does not declare it is an unknown key and is **silently ignored**.

| type | `udp` | `tfo` | `mptcp` | `ip-version` | `interface-name` / `routing-mark` | `dialer-proxy` |
|---|---|---|---|---|---|---|
| `ss` | ✅ field, default `false` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `ssr` | ✅ field, default `false` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `socks5` | ✅ field, default `false` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `http` | ✖ no field (HTTP is TCP-only) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `vmess` | ✅ field, default `false` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `vless` | ✅ field, default `false` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `trojan` | ✅ field, default `false` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `snell` | ✅ field, default `false` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `anytls` | ✅ field, default `false` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `mieru` | ✅ field, default `false` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `wireguard` | ✅ field, default `false` | ✖ ignored | ✖ ignored | ✅ | ✅ | ✅ |
| `ssh` | ✖ no field (UDP never supported) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `sudoku` | ✖ no field — always `true` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `hysteria` | ✖ no field — always `true` | via `fast-open` | ✖ | ✅ | ✅ | ✅ |
| `hysteria2` | ✖ no field — always `true` | ✖ ignored | ✖ | ✅ | ✅ | ✅ |
| `tuic` | ✖ no field — always `true` | via `fast-open` | ✖ | ✅ | ✅ | ✅ |
| `shadowquic` | ✖ no field — always `true` | ✖ ignored | ✖ | ✅ | ✅ | ✅ |
| `direct` | ✖ no field — always `true` | ✅ | ✅ | ✅ | ✅ | ✖ decoded but **not honored** (`direct` builds its own dialer; its `interface-name`/`routing-mark`/`ip-version`/`tfo`/`mptcp` still work) |

✅ = the option reaches `Base` and is honored; "✖ ignored" = the key decodes into `BasicOption` but is
not passed to `NewBase` for that protocol, so it has no effect. `hysteria`/`hysteria2`/`tuic`/
`shadowquic`/`wireguard` are UDP-based transports and always carry UDP.
`mptcp` and `tfo` only affect TCP dialing.

---

## 4. TLS options

### 4.1 Which auth/TLS keys exist per protocol (TLS options are **not** shared code; each struct lists them)

| key | http | socks5 | ss | vmess | vless | trojan | snell | anytls | hysteria | hysteria2 | tuic | shadowquic |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `tls` | ✅ | ✅ | – | ✅ | ✅ | ✖ (always TLS) | – | ✖ (always) | ✖ | ✖ | ✖ | ✖ |
| `sni` | ✅ | ✖ | – | ✖ | ✖ | ✅ | – | ✅ | ✅ | ✅ | ✅ | ✅ |
| `servername` | ✖ | ✖ | – | ✅ | ✅ | ✖ | – | ✖ | ✖ | ✖ | ✖ | ✖ |
| `alpn` | ✖ | ✖ | – | ✅ | ✅ | ✅ | – | ✅ | ✅ | ✅ | ✅ | ✅ |
| `skip-cert-verify` | ✅ | ✅ | – | ✅ | ✅ | ✅ | – | ✅ | ✅ | ✅ | ✅ | ✖ |
| `name-cert-verify` | ✅ | ✅ | – | ✅ | ✅ | ✅ | – | ✅ | ✅ | ✅ | ✅ | ✖ |
| `fingerprint` (pin) | ✅ | ✅ | – | ✅ | ✅ | ✅ | – | ✅ | ✅ | ✅ | ✅ | ✖ |
| `certificate` | ✅ | ✅ | – | ✅ | ✅ | ✅ | – | ✅ | ✅ | ✅ | ✅ | ✖ |
| `private-key` | ✅ | ✅ | – | ✅ | ✅ | ✅ | – | ✅ | ✅ | ✅ | ✅ | ✖ |
| `client-fingerprint` | ✖ | ✖ | ✅* | ✅ | ✅ | ✅ | ✅ | ✅ | ✖ | ✖ | ✖ | ✖ |
| `ech-opts` | ✖ | ✖ | – | ✅ | ✅ | ✅ | – | ✅ | ✅ | ✅ | ✅ | ✖ |
| `reality-opts` | ✖ | ✖ | – | ✅ | ✅ | ✅ | – | ✖ (never) | ✖ | ✖ | ✖ | ✖ |
| `shadow-tls-opts` | ✖ | ✖ | – | ✅ | ✅ | ✅ | – | ✅ | ✖ | ✖ | ✖ | ✖ |
| `restls-opts` | ✖ | ✖ | – | ✅ | ✅ | ✅ | – | ✅ | ✖ | ✖ | ✖ | ✖ |
| `jls-opts` | ✖ | ✖ | – | ✅ | ✅ | ✅ | – | ✅ | ✖ | ✖ | ✖ | ✖ |
| `tlsmirror-opts` | ✖ | ✖ | – | ✅ | ✖ | ✖ | – | ✖ | ✖ | ✖ | ✖ | ✖ |
| `ss-opts` | ✖ | ✖ | – | ✖ | ✖ | ✅ | – | ✖ | ✖ | ✖ | ✖ | ✖ |
| `realm-opts` | ✖ | ✖ | – | ✖ | ✖ | ✖ | – | ✖ | ✖ | ✅ | ✖ | ✖ |

`*` ss has `client-fingerprint` at top level and inside `plugin-opts` (shadow-tls / restls / jls).

### 4.2 Semantics (wiki)

| key | meaning |
|---|---|
| `tls` | enable TLS. Only HTTP/SOCKS5/VMess/VLESS have the switch; trojan/anytls/hysteria/hysteria2/tuic/shadowquic are TLS-native (a `tls:` key there is accepted-but-ignored). Trojan **requires** TLS on the server. |
| `sni` / `servername` | SNI. Empty → `server`. VMess/VLESS use **`servername` only**; all others use **`sni`**. |
| `fingerprint` | SHA-256 **certificate** fingerprint (pinning); `openssl x509 -noout -fingerprint -sha256 -inform pem -in cert.pem`. Leaf cert ⇒ exact match only; CA/intermediate ⇒ chain verification, and since v1.19.20 SNI must match. Overrides `experimental.fingerprints`. |
| `alpn` | list, ordered by preference; no common protocol ⇒ handshake fails. |
| `skip-cert-verify` | bool, default false. |
| `name-cert-verify` | string — only changes the DNSName checked against the cert, not the SNI. |
| `certificate` / `private-key` | mTLS; PEM content or path; must be set together. |
| `client-fingerprint` | uTLS ClientHello. Values: `chrome`, `firefox`, `safari`, `iOS`, `android`, `edge`, `360`, `qq`, `random`, plus legacy `chrome120`, `chrome_psk`, `chrome_psk_shuffle`, `chrome_padding_psk_shuffle`, `chrome_pq`, `chrome_pq_psk`, and `none`/empty ⇒ plain Go TLS. `random` picks a weighted modern browser (chrome 6 / safari 3 / ios 2 / firefox 1). Claimed for VMess/VLESS/Trojan/AnyTLS; present in ss, snell too. |
| `reality-opts` | non-empty `public-key` ⇒ REALITY enabled. |
| `ech-opts` | `enable: true` enables ECH. |

### 4.3 Sub-option structs (exact keys)

```yaml
reality-opts:
  public-key: xxxx                    # string, required to enable REALITY (base64url, 32 B)
  short-id: xxxx                      # string, hex, <= 8 bytes
  support-x25519mlkem768: true        # bool, default false   (src; wiki lists under reality-opts)

ech-opts:
  enable: true                        # bool, default false
  config: base64_encoded_config       # string; empty ⇒ resolved via DNS HTTPS record
  query-server-name: xxx.com          # string, optional; domain used for the DNS query

shadow-tls-opts:                      # requires tls: true (vmess/vless/trojan/anytls)
  version: 3                          # int, 1|2|3, empty ⇒ 2
  password: shadow-tls-password       # string

restls-opts:                          # requires tls: true
  password: restls-password           # string
  version-hint: tls13                 # tls12 | tls13
  restls-script: "300?100<1,400~100"  # string, optional post-handshake carrier script

jls-opts:                             # requires tls: true
  username: jls-user                  # string
  password: jls-password              # string

tlsmirror-opts:                       # VMess ONLY (wiki: do not use with other protocols)
  primary-key: <base64 32 bytes>      # string, required
  explicit-nonce-ciphersuites: [156, 157, ...]   # []uint16, TLS1.2 carrier suites
  defer-instance-derived-write-time: { base-nanoseconds: 0, uniform-random-multiplier-nanoseconds: 0 }
  transport-layer-padding: { enabled: false }
  connection-enrolment:
    primary-ingress-outbound: ""      # string
    primary-egress-outbound: ""       # string (usually empty for mihomo VMess outbound)
  sequence-watermarking-enabled: false
  embedded-traffic-generator:         # disabled when `steps` is absent
    steps:
      - name: example                 # string, identifier only
        host: example.com             # string
        path: /                       # string
        method: GET                   # string
        headers:
          - name: User-Agent          # string
            value: mihomo             # string (single value)
            values: [text/html, application/xhtml+xml]   # []string (multi value)
        connection-ready: true        # bool
        connection-recall-exit: true  # bool
        h2-do-not-wait-for-download-finish: false
        wait-time: { base-nanoseconds: 1000000000, uniform-random-multiplier-nanoseconds: 0 }
        next-step:
          - { weight: 1, goto-location: 0 }   # int, int (target step index)
```
TLSMirror carrier reuses `servername`, `alpn`, `skip-cert-verify`, `name-cert-verify`,
`fingerprint`, `certificate`, `private-key`, `client-fingerprint`, `ech-opts` from the same outbound.

---

## 5. Transport options (VMess / VLESS / Trojan only)

`network` is omitted/`tcp` ⇒ plain TCP. Accepted values per protocol (src switches):

| `network` | vmess | vless | trojan |
|---|:-:|:-:|:-:|
| (unset)/`tcp` | ✅ | ✅ | ✅ |
| `ws` | ✅ | ✅ | ✅ |
| `http` | ✅ | ✅ | ✖ |
| `h2` | ✅ | ✅ | ✖ |
| `grpc` | ✅ | ✅ | ✅ |
| `mkcp` (alias `kcp`) | ✅ | ✖ | ✖ |
| `mekya` | ✅ | ✖ | ✖ |
| `xhttp` | ✖ | ✅ (only VLESS) | ✖ |

There is **no** `network: httpupgrade`; v2ray HTTP Upgrade is `network: ws` +
`ws-opts.v2ray-http-upgrade: true`.

### 5.1 `ws-opts`

| key | type | default | meaning |
|---|---|---|---|
| `path` | string | `/` | request path |
| `headers` | map[string]string | — | extra request headers (use `Host:` here for the WS host) |
| `max-early-data` | int | 0 | early-data first-packet length threshold |
| `early-data-header-name` | string | `Sec-WebSocket-Protocol` | header carrying early data |
| `v2ray-http-upgrade` | bool | false | use HTTP Upgrade instead of WS |
| `v2ray-http-upgrade-fast-open` | bool | false | fast open for the upgrade request |

TLS/SNI note (src, vmess/vless ws): when `tls: true`, SNI = `servername` if set, else the `Host` header
of `ws-opts.headers`, else `server`.

### 5.2 `grpc-opts`

| key | type | default | meaning |
|---|---|---|---|
| `grpc-service-name` | string | — | service name |
| `grpc-user-agent` | string | — | User-Agent (src) |
| `ping-interval` | int | 0 (off) | heartbeat seconds (src) |
| `max-connections` | int | 1 | max underlying conns; conflicts with `max-streams` (src) |
| `min-streams` | int | — | min multiplexed streams per conn (src) |
| `max-streams` | int | — | max multiplexed streams per conn; conflicts with the above (src) |

### 5.3 `h2-opts`

| key | type | meaning |
|---|---|---|
| `host` | []string | host list; client picks one at random, server validates |
| `path` | string | request path |

wiki note: mihomo's H2 transport does **not** multiplex; use gRPC or sing-mux for that.

### 5.4 `http-opts`

| key | type | meaning |
|---|---|---|
| `method` | string | e.g. `GET` |
| `path` | []string | one or more paths |
| `headers` | map[string][]string | request headers, **list values** |

### 5.5 `mkcp-opts` (VMess only)

`mtu` (uint32), `tti` (uint32, ms), `uplink-capacity` (uint32, MB/s), `downlink-capacity` (uint32,
MB/s), `congestion` (bool), `write-buffer` (uint32, B), `read-buffer` (uint32, B), `seed` (string,
AES-GCM auth seed, empty ⇒ default), `header` (string: `none`/`srtp`/`utp`/`wechat-video`/`dtls`/
`wireguard`).

### 5.6 `mekya-opts` (VMess only)

`url`, `h2-pool-size`, `max-write-delay`, `max-request-size`, `polling-interval-initial`,
`max-write-size`, `max-write-duration-ms`, `max-simultaneous-write-connection`,
`packet-writing-buffer` (all int except `url`), and `kcp:` = the same keys as `mkcp-opts`.

### 5.7 `xhttp-opts` (VLESS only; wiki + src)

Default transport is h2; set top-level `alpn: [h3]` for HTTP/3, `alpn: [http/1.1]` for HTTP/1.1.

| key | type | notes |
|---|---|---|
| `path` | string | request path |
| `host` | string | Host header |
| `mode` | string | `auto`/`stream-one`/`stream-up`/`packet-up` |
| `headers` | map[string]string | extra headers |
| `no-grpc-header` | bool | suppress the gRPC-mimicking `Content-Type: application/grpc` |
| `x-padding-bytes` | string | e.g. `"100-1000"` (default, randomized per request) |
| `x-padding-obfs-mode` | bool | default false |
| `x-padding-key` | string | default `x_padding` |
| `x-padding-header` | string | default `Referer` |
| `x-padding-placement` | string | `queryInHeader`/`cookie`/`header`/`query` (only when obfs-mode true) |
| `x-padding-method` | string | `repeat-x` (default) / `tokenish` |
| `uplink-http-method` | string | `POST`/`PUT`/`PATCH`/`DELETE` |
| `session-placement` | string | `path`/`query`/`cookie`/`header` |
| `session-key` | string | n/a for `path` |
| `session-table` | string | `""`,`uuid`,`ALPHABET`,`Alphabet`,`BASE36`,`Base62`,`HEX`,`alphabet`,`base36`,`hex`,`number` |
| `session-length` | string | e.g. `"16-32"`; start ≠ 0; ID space > 2.1e9 |
| `seq-placement` | string | `path`/`query`/`cookie`/`header`; if session is `path` this must be `path` |
| `seq-key` | string | n/a for `path` |
| `uplink-data-placement` | string | `body`/`cookie`/`header` (cookie/header only for `packet-up`) |
| `uplink-data-key` | string | base key for data fragments |
| `uplink-chunk-size` | string | bytes, min 64; default 3072 (cookie) / 4096 (header); int in YAML ok |
| `sc-max-each-post-bytes` | string | default `1000000` |
| `sc-min-posts-interval-ms` | string | default `30` |

```yaml
xhttp-opts:
  reuse-settings:              # aka XMUX — no default; omit ⇒ one conn per request
    max-concurrency: "16-32"
    max-connections: "0"       # conflicts with max-concurrency
    c-max-reuse-times: "0"
    h-max-request-times: "600-900"
    h-max-reusable-secs: "1800-3000"
    h-keep-alive-period: 0     # int only (no range); 0 ⇒ chrome h2 45s / quic-go h3 10s; -1 disables
  download-settings:           # overrides the uplink values; unset = inherit
    path: "/"                  # *string
    host: xxx.com              # *string
    headers: { X-Forwarded-For: "" }   # *map[string]string
    reuse-settings: { ... }    # same keys as above
    server: server             # proxy part (all *optional)
    port: 443
    tls: true
    alpn: [h3]
    ech-opts: { ... }
    shadow-tls-opts: { ... }
    restls-opts: { ... }
    jls-opts: { ... }
    reality-opts: { ... }
    skip-cert-verify: false
    name-cert-verify: example.com
    fingerprint: ...
    certificate: ...
    private-key: ...
    servername: xxx.com
    client-fingerprint: chrome
```

---

## 6. Per-protocol specs

Required = tag without `,omitempty` (parser errors if absent). Types/defaults from source structs.

### 6.1 `ss` — Shadowsocks

| key | type | default | notes |
|---|---|---|---|
| `cipher` | string | **required** | see list below |
| `password` | string | **required** | |
| `udp` | bool | false | |
| `udp-over-tcp` | bool | false | |
| `udp-over-tcp-version` | int | 1 | `1`/`2` |
| `plugin` | string | — | `obfs`,`v2ray-plugin`,`gost-plugin`,`shadow-tls`,`restls`,`kcptun`,`jls` |
| `plugin-opts` | map | — | decoded with `obfs` tags |
| `client-fingerprint` | string | — | used by shadow-tls/restls/jls plugins |

Ciphers (wiki): `aes-128/192/256-ctr|-cfb|-gcm|-ccm`, `aes-128-gcm-siv`, `aes-256-gcm-siv`,
`chacha20-ietf`, `chacha20`, `xchacha20`, `chacha20-ietf-poly1305`, `xchacha20-ietf-poly1305`,
`chacha8-ietf-poly1305`, `xchacha8-ietf-poly1305`, `2022-blake3-aes-128-gcm`,
`2022-blake3-aes-256-gcm`, `2022-blake3-chacha20-poly1305`, `lea-128/192/256-gcm`,
`rabbit128-poly1305`, `aegis-128l`, `aegis-256`, `aez-384`, `deoxys-ii-256-128`, `rc4-md5`, `none`.

`plugin-opts` per plugin (src struct tags; `mode` accepted values in parentheses):

| plugin | keys |
|---|---|
| `obfs` | `mode` (`tls`,`http` — anything else errors), `host` (default `bing.com`) |
| `v2ray-plugin` | `mode`, `host` (def `bing.com`), `path`, `tls`, `ech-opts`, `fingerprint`, `certificate`, `private-key`, `headers`, `skip-cert-verify`, `name-cert-verify`, `mux` (def `true`), `v2ray-http-upgrade`, `v2ray-http-upgrade-fast-open` |
| `gost-plugin` | `mode`, `host`, `path`, `tls`, `ech-opts`, `fingerprint`, `certificate`, `private-key`, `headers`, `skip-cert-verify`, `name-cert-verify`, `mux` |
| `shadow-tls` | `password`, `host`, `fingerprint`, `certificate`, `private-key`, `skip-cert-verify`, `name-cert-verify`, `version` (1/2/3), `alpn` |
| `restls` | `password`, `host`, `version-hint` (`tls12`/`tls13`), `restls-script`, `fingerprint`, `skip-cert-verify`, `name-cert-verify`, `force-tls12` (src, test) |
| `jls` | `host`, `username`, `password`, `alpn` |
| `kcptun` | `key`,`crypt`,`mode`,`conn`,`autoexpire`,`scavengettl`,`mtu`,`ratelimit`,`sndwnd`,`rcvwnd`,`datashard`,`parityshard`,`dscp`,`nocomp`,`acknodelay`,`nodelay`,`interval`,`resend`,`nc`,`sockbuf`,`smuxver`,`smuxbuf`,`framesize`,`streambuf`,`keepalive` (all int except `key`,`crypt`,`mode`; `nocomp`/`acknodelay` bool) |

```yaml
proxies:
  - name: "ss-01"
    type: ss
    server: 203.0.113.10
    port: 8388
    cipher: 2022-blake3-aes-128-gcm
    password: "cGFzc3dvcmQ="
    udp: true
    udp-over-tcp: false
    udp-over-tcp-version: 2
    ip-version: ipv4-prefer
    interface-name: eth0
    routing-mark: 100
    tfo: true
    plugin: v2ray-plugin
    plugin-opts:
      mode: websocket
      host: bing.com
      path: "/ws"
      tls: true
      skip-cert-verify: false
      mux: true
      headers:
        custom: value
      v2ray-http-upgrade: false
    smux:
      enabled: true
      protocol: h2mux
      max-streams: 0
      min-streams: 4
      statistic: false
      only-tcp: false
      padding: true
      brutal-opts:
        enabled: true
        up: 50
        down: 100
```

### 6.2 `ssr` — ShadowsocksR

| key | type | default | notes |
|---|---|---|---|
| `cipher` | string | **required** | e.g. `chacha20-ietf`, `aes-256-cfb` |
| `password` | string | **required** | |
| `obfs` | string | **required** | e.g. `tls1.2_ticket_auth` |
| `protocol` | string | **required** | e.g. `auth_sha1_v4`, `auth_aes128_md5` |
| `obfs-param` | string | — | |
| `protocol-param` | string | — | |
| `udp` | bool | false | SSR UDP relay |

```yaml
  - name: "ssr-01"
    type: ssr
    server: 203.0.113.11
    port: 443
    cipher: chacha20-ietf
    password: "password"
    obfs: tls1.2_ticket_auth
    protocol: auth_sha1_v4
    obfs-param: domain.tld
    protocol-param: "#"
    udp: true
```

### 6.3 `socks5` — SOCKS5

| key | type | default | notes |
|---|---|---|---|
| `username` | string | — | |
| `password` | string | — | |
| `tls` | bool | false | SOCKS over TLS |
| `udp` | bool | false | SOCKS5 UDP ASSOCIATE |
| `skip-cert-verify`,`name-cert-verify`,`fingerprint`,`certificate`,`private-key` | | | TLS only |

No `sni`, no `alpn`, no `headers`.

```yaml
  - name: "socks-01"
    type: socks5
    server: 203.0.113.12
    port: 1080
    username: user
    password: pass
    tls: true
    skip-cert-verify: true
    name-cert-verify: example.com
    fingerprint: xxxx
    udp: true
```

### 6.4 `http` — HTTP CONNECT

| key | type | default | notes |
|---|---|---|---|
| `username` | string | — | → `Proxy-Authorization: Basic …` |
| `password` | string | — | |
| `tls` | bool | false | HTTPS proxy |
| `sni` | string | — | |
| `skip-cert-verify`, `name-cert-verify`, `fingerprint`, `certificate`, `private-key` | | | |
| `headers` | map[string]string | — | **src-only**, wiki shows the key with no description: extra headers added to the CONNECT request |

UDP is impossible (no `udp` field). No `alpn`, no `client-fingerprint`.

```yaml
  - name: "http-01"
    type: http
    server: 203.0.113.13
    port: 3128
    username: user
    password: pass
    tls: true
    sni: custom.com
    skip-cert-verify: true
    name-cert-verify: example.com
    fingerprint: xxxx
    headers:
      X-Custom: value
    ip-version: dual
```

### 6.5 `vmess`

| key | type | default | notes |
|---|---|---|---|
| `uuid` | string | **required** | |
| `alterId` | int | **required** | non-zero ⇒ legacy protocol (accepts `alterid`/`alter_id`) |
| `cipher` | string | **required** | `auto`/`none`/`zero`/`aes-128-gcm`/`chacha20-poly1305` |
| `udp` | bool | false | |
| `tls` | bool | false | |
| `servername` | string | — | SNI (**no `sni` alias**) |
| `alpn` | []string | — | |
| `skip-cert-verify`, `name-cert-verify`, `fingerprint`, `certificate`, `private-key` | | | |
| `client-fingerprint` | string | — | |
| `network` | string | tcp | `ws`/`http`/`h2`/`grpc`/`mkcp`(`kcp`)/`mekya` |
| `packet-encoding` | string | — | `packetaddr`(alias `packet`)/`xudp`; empty ⇒ raw |
| `packet-addr` | bool | false | legacy; `packet-encoding` also sets it |
| `xudp` | bool | false | legacy; when true it wins over `packet-addr` |
| `global-padding` | bool | false | protocol param |
| `authenticated-length` | bool | false | protocol param |
| `ws-opts`,`grpc-opts`,`h2-opts`,`http-opts`,`mkcp-opts`,`mekya-opts` | map | — | §5 |
| `ech-opts`,`reality-opts`,`shadow-tls-opts`,`restls-opts`,`jls-opts`,`tlsmirror-opts` | map | — | §4 (tlsmirror = VMess only) |

```yaml
  - name: "vmess-01"
    type: vmess
    server: 203.0.113.14
    port: 443
    uuid: b831381d-6324-4d53-ad4f-8cda48b30811
    alterId: 0
    cipher: auto
    udp: true
    tls: true
    servername: example.com
    alpn: [h2, http/1.1]
    fingerprint: xxxx
    client-fingerprint: chrome
    skip-cert-verify: true
    name-cert-verify: example.com
    network: ws
    ws-opts:
      path: /path
      headers:
        Host: example.com
      max-early-data: 2048
      early-data-header-name: Sec-WebSocket-Protocol
      v2ray-http-upgrade: false
      v2ray-http-upgrade-fast-open: false
    packet-encoding: xudp
    global-padding: false
    authenticated-length: false
    reality-opts:
      public-key: xxxx
      short-id: xxxx
    ech-opts:
      enable: true
      config: AEn+DQBFKwAgACABWIHUGj4u...
      query-server-name: xxx.com
    shadow-tls-opts:
      version: 3
      password: shadow-tls-password
    restls-opts:
      password: restls-password
      version-hint: tls13
    jls-opts:
      username: jls-user
      password: jls-password
    smux:
      enabled: false
```

### 6.6 `vless`

| key | type | default | notes |
|---|---|---|---|
| `uuid` | string | **required** | |
| `flow` | string | — | only `xtls-rprx-vision` |
| `tls` | bool | false | |
| `servername` | string | — | SNI (**no `sni` alias**) |
| `udp` | bool | false | |
| `packet-encoding` | string | — | `packetaddr`(=`packet`) sets `packet-addr`, else **XUDP is enabled by default** (src) |
| `packet-addr` | bool | false | legacy |
| `xudp` | bool | **true by default** (src) | legacy |
| `encryption` | string | `""` | VLESS encryption; `""` for plain. Format & padding documented on the wiki |
| `network` | string | tcp | `ws`/`http`/`h2`/`grpc`/`xhttp` |
| `alpn`,`skip-cert-verify`,`name-cert-verify`,`fingerprint`,`certificate`,`private-key`,`client-fingerprint` | | | |
| `ws-opts`,`ws-headers` | map | — | `ws-headers` is a legacy flat `map[string]string` (src) merged with `ws-opts.headers` |
| `grpc-opts`,`h2-opts`,`http-opts`,`xhttp-opts` | map | — | §5 |
| `ech-opts`,`reality-opts`,`shadow-tls-opts`,`restls-opts`,`jls-opts` | map | — | §4 |

wiki note: mihomo's `xtls-*` flow already implies `xtls-*-udp443`; block UDP/443 with a rule
`AND,((NETWORK,UDP),(DST-PORT,443)),REJECT`.

```yaml
  - name: "vless-01"
    type: vless
    server: 203.0.113.15
    port: 443
    uuid: b831381d-6324-4d53-ad4f-8cda48b30811
    flow: xtls-rprx-vision
    tls: true
    servername: example.com
    alpn: [h2, http/1.1]
    udp: true
    packet-encoding: xudp
    encryption: ""
    client-fingerprint: chrome
    skip-cert-verify: true
    name-cert-verify: example.com
    fingerprint: xxxx
    network: xhttp
    xhttp-opts:
      path: "/"
      host: xxx.com
      mode: stream-one
      headers:
        X-Forwarded-For: ""
      no-grpc-header: false
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
      sc-max-each-post-bytes: 1000000
      sc-min-posts-interval-ms: 30
      reuse-settings:
        max-concurrency: "16-32"
        max-connections: "0"
        c-max-reuse-times: "0"
        h-max-request-times: "600-900"
        h-max-reusable-secs: "1800-3000"
        h-keep-alive-period: 0
    reality-opts:
      public-key: xxxx
      short-id: xxxx
      support-x25519mlkem768: true
    smux:
      enabled: false
```

### 6.7 `trojan`

| key | type | default | notes |
|---|---|---|---|
| `password` | string | **required** | |
| `udp` | bool | false | |
| `sni` | string | — | empty ⇒ `server` (src sets `option.SNI = option.Server`) |
| `alpn` | []string | — | |
| `skip-cert-verify`,`name-cert-verify`,`fingerprint`,`certificate`,`private-key`,`client-fingerprint` | | | |
| `network` | string | tcp | only `ws`/`grpc` |
| `ws-opts`,`grpc-opts` | map | — | §5 |
| `ech-opts`,`shadow-tls-opts`,`restls-opts`,`jls-opts`,`reality-opts` | map | — | §4 |
| `ss-opts` | map | — | trojan-go SS AEAD |
| `ss-opts.enabled` | bool | false | |
| `ss-opts.method` | string | — | `aes-128-gcm`/`aes-256-gcm`/`chacha20-ietf-poly1305` |
| `ss-opts.password` | string | — | |

There is **no** `tls` key (trojan is always TLS) and **no** `servername` (use `sni`).

```yaml
  - name: "trojan-01"
    type: trojan
    server: 203.0.113.16
    port: 443
    password: yourpsk
    udp: true
    sni: example.com
    alpn: [h2, http/1.1]
    client-fingerprint: random
    fingerprint: xxxx
    skip-cert-verify: true
    name-cert-verify: example.com
    network: grpc
    grpc-opts:
      grpc-service-name: example
      grpc-user-agent: mihomo
      ping-interval: 0
      max-connections: 1
    reality-opts:
      public-key: xxxx
      short-id: xxxx
    shadow-tls-opts:
      version: 3
      password: shadow-tls-password
    restls-opts:
      password: restls-password
      version-hint: tls13
    jls-opts:
      username: jls-user
      password: jls-password
    ss-opts:
      enabled: false
      method: aes-128-gcm
      password: "example"
    smux:
      enabled: false
```

### 6.8 `snell`

| key | type | default | notes |
|---|---|---|---|
| `psk` | string | **required** | pre-shared key |
| `version` | int | `1` (src `DefaultSnellVersion = Version1`) | v1–v5; UDP only on v3/4/5 |
| `reuse` | bool | false | v4/v5 only |
| `udp` | bool | false | |
| `client-fingerprint` | string | wiki: `chrome` | passed to shadow-tls/restls/jls; src: empty ⇒ transport default (shadow-tls ⇒ plain TLS) |
| `obfs-opts` | map | — | decoded with `obfs` tags |

`obfs-opts.mode`: `http`, `tls` (simple obfs, `host` default `bing.com`), `shadow-tls`, `restls`,
`jls`. Keys per mode:

| mode | keys |
|---|---|
| `http` / `tls` / (empty) | `mode`, `host` |
| `shadow-tls` | `password`, `host`, `fingerprint`, `certificate`, `private-key`, `skip-cert-verify`, `name-cert-verify`, `version` (1/2/3, default 2), `alpn` (default = shadowtls default) |
| `restls` | `password`, `host`, `version-hint` (`tls12`/`tls13`), `restls-script`, `fingerprint`, `skip-cert-verify`, `name-cert-verify`, `force-tls12` |
| `jls` | `host`, `username`, `password`, `alpn` |

```yaml
  - name: "snell-01"
    type: snell
    server: 203.0.113.17
    port: 44046
    psk: yourpsk
    version: 4
    udp: true
    reuse: false
    client-fingerprint: chrome
    obfs-opts:
      mode: shadow-tls
      host: bing.com
      password: "shadow_tls_password"
      version: 2
      alpn: ["h2"]
```

### 6.9 `anytls`

| key | type | default | notes |
|---|---|---|---|
| `password` | string | **required** | |
| `udp` | bool | false | |
| `sni` | string | — | |
| `alpn` | []string | — | |
| `client-fingerprint` | string | — | |
| `skip-cert-verify`,`name-cert-verify`,`fingerprint`,`certificate`,`private-key` | | | |
| `client-metadata` | string | **not sent** since v1.19.30 | optional metadata |
| `idle-session-check-interval` | int | 30 (s) | |
| `idle-session-timeout` | int | 30 (s) | |
| `min-idle-session` | int | 0 | |
| `disable-reuse` | bool | false | **src-only**, undocumented |
| `ech-opts`,`shadow-tls-opts`,`restls-opts`,`jls-opts` | map | — | §4 — **no `reality-opts`** (mihomo refuses AnyTLS+Reality) |

```yaml
  - name: "anytls-01"
    type: anytls
    server: 1.2.3.4
    port: 443
    password: "<your password>"
    client-fingerprint: chrome
    udp: true
    client-metadata: ""
    idle-session-check-interval: 30
    idle-session-timeout: 30
    min-idle-session: 0
    disable-reuse: false
    sni: "example.com"
    alpn: [h2, http/1.1]
    skip-cert-verify: true
    name-cert-verify: example.com
    shadow-tls-opts:
      version: 3
      password: shadow-tls-password
```

### 6.10 `mieru`

| key | type | default | notes |
|---|---|---|---|
| `transport` | string | **required** | `TCP` / `UDP` (case as-is) |
| `username` | string | **required** | |
| `password` | string | **required** | |
| `port` | int | — | mutually exclusive with `port-range` |
| `port-range` | string | — | e.g. `2090-2099`; cannot be combined with `port` |
| `udp` | bool | false | |
| `multiplexing` | string | `MULTIPLEXING_LOW` | `MULTIPLEXING_OFF`/`_LOW`/`_MIDDLE`/`_HIGH` |
| `handshake-mode` | string | `HANDSHAKE_STANDARD` | `HANDSHAKE_STANDARD`/`HANDSHAKE_NO_WAIT` (0-RTT) |
| `traffic-pattern` | string | `""` | base64 tuning string |

```yaml
  - name: "mieru-01"
    type: mieru
    server: server.example.com
    port: 2999
    transport: TCP
    username: user
    password: password
    multiplexing: MULTIPLEXING_LOW
    handshake-mode: HANDSHAKE_STANDARD
    traffic-pattern: ""
```
With a range:
```yaml
  - name: "mieru-range"
    type: mieru
    server: server.example.com
    port-range: 2090-2099
    transport: UDP
    username: user
    password: password
```

### 6.11 `sudoku`

| key | type | default | notes |
|---|---|---|---|
| `key` | string | **required** | ED25519 private key, or the same UUID as the server |
| `aead-method` | string | — | `chacha20-poly1305`/`aes-128-gcm`/`none` |
| `padding-min` | int | — | 0–100 |
| `padding-max` | int | — | ≥ `padding-min`, 0–100 |
| `table-type` | string | — | `prefer_ascii`/`prefer_entropy`/`up_ascii_down_entropy`/`up_entropy_down_ascii` |
| `custom-table` | string | — | e.g. `xpxvvpvv`; exactly 2×x, 2×p, 4×v; entropy direction only |
| `custom-tables` | []string | — | overrides `custom-table` when non-empty |
| `multiplex` | string | `off` | `off`/`auto`/`on` |
| `enable-pure-downlink` | bool | — | must match server |
| `httpmask` | map | — | nested form (preferred) |
| `httpmask.disable` | bool | false | disable all HTTP masking |
| `httpmask.mode` | string | `legacy` | `legacy`/`stream`/`poll`/`auto`/`ws` |
| `httpmask.tls` | bool | — | only stream/poll/auto/ws: true forces HTTPS, false forces HTTP |
| `httpmask.host` | string | — | overrides Host/SNI, `example.com` or `example.com:443` |
| `httpmask.path-root` | string | — | first path segment, e.g. `aabbcc` ⇒ `/aabbcc/session`… |
| `httpmask.multiplex` | string | — | legacy, overrides top-level `multiplex` |
| `http-mask` | bool | — | **src-only flat alias**; `true` = enable mask (`disable = !http-mask`) |
| `http-mask-mode`, `http-mask-tls`, `http-mask-host`, `path-root`, `http-mask-multiplex` | | | **src-only flat aliases**; nested `httpmask` wins (applied last) |

`udp` is always on (hardcoded). No `port-range`.

```yaml
  - name: "sudoku-01"
    type: sudoku
    server: 1.2.3.4
    port: 443
    key: "<client_key>"
    aead-method: chacha20-poly1305
    padding-min: 2
    padding-max: 7
    table-type: prefer_ascii
    custom-table: xpxvvpvv
    custom-tables: ["xpxvvpvv", "vxpvxvvp"]
    multiplex: "off"
    enable-pure-downlink: false
    httpmask:
      disable: false
      mode: legacy
      tls: true
      host: ""
      path-root: ""
      multiplex: "off"
```

### 6.12 `hysteria` (v1)

| key | type | default | notes |
|---|---|---|---|
| `up` | string | **required** | e.g. `"30 Mbps"`; no unit ⇒ Mbps |
| `down` | string | **required** | |
| `port` | int | — | may be omitted only if `ports` is set |
| `ports` | string | — | e.g. `1000,2000-3000,4000` (port hopping) |
| `protocol` | string | `udp` (src `DefaultProtocol`) | `udp`/`wechat-video`/`faketcp` |
| `obfs-protocol` | string | — | **Stash-compat alias**; if set it overwrites `protocol` |
| `auth-str` | string | — | auth string (⚠ sender: `auth_str` also works via `_`→`-`) |
| `auth` | string | — | **base64** auth; takes priority over `auth-str` |
| `obfs` | string | — | XPlus obfuscation password/string |
| `up-speed` | int | — | **Stash-compat**; Mbps; overrides `up` |
| `down-speed` | int | — | **Stash-compat**; Mbps; overrides `down` |
| `hop-interval` | int | 10 | seconds (src `DefaultHopInterval = 10`) |
| `sni` | string | `server` | |
| `alpn` | []string | `["hysteria"]` (src `DefaultALPN`) | docs example uses `h3` |
| `skip-cert-verify`,`name-cert-verify`,`fingerprint`,`certificate`,`private-key` | | | |
| `ech-opts` | map | — | §4 |
| `recv-window-conn` | int | 15728640/10 | stream receive window |
| `recv-window` | int | 67108864/10 | connection receive window |
| `disable-mtu-discovery` | bool | false | wiki writes `disable_mtu_discovery` |
| `fast-open` | bool | false | also becomes the node's TFO flag |
| `udp` | — | always true | no `udp` field: the key is ignored |
| `mptcp` | — | ignored | accepted in `BasicOption` but not passed to `NewBase` |

No `tls` key (always TLS 1.3).

```yaml
  - name: "hysteria-01"
    type: hysteria
    server: server.com
    port: 443
    ports: 1000,2000-3000,4000
    hop-interval: 30
    auth-str: yourpassword
    protocol: udp
    obfs: obfs_str
    obfs-protocol: udp
    up: "30 Mbps"
    down: "200 Mbps"
    up-speed: 30
    down-speed: 200
    sni: server.com
    alpn: [h3]
    skip-cert-verify: false
    name-cert-verify: example.com
    fingerprint: xxxx
    recv-window-conn: 12582912
    recv-window: 52428800
    disable-mtu-discovery: false
    fast-open: true
```

### 6.13 `hysteria2`

| key | type | default | notes |
|---|---|---|---|
| `port` | int | — | ignored if `ports` is set |
| `ports` | string | — | `443-8443` / `1000,2000-3000`; enables port hopping |
| `hop-interval` | **string** | 30 | `"30"` or `"15-30"` (one range only, no commas; start 0 ⇒ 30, start < 5 clamped to 5) |
| `password` | string | — | auth password (src: `omitempty`, not strictly required by the decoder) |
| `up` | string | — | Brutal up; no unit ⇒ Mbps |
| `down` | string | — | Brutal down |
| `obfs` | string | `""` | `salamander` or `gecko`; empty ⇒ disabled |
| `obfs-password` | string | — | |
| `obfs-min-packet-size` | int | — | **gecko only** |
| `obfs-max-packet-size` | int | — | **gecko only** |
| `sni` | string | `server` | |
| `alpn` | []string | — | docs use `[h3]` |
| `skip-cert-verify`,`name-cert-verify`,`fingerprint`,`certificate`,`private-key` | | | |
| `ech-opts` | map | — | §4 |
| `cwnd` | int | — | **src-only** |
| `bbr-profile` | string | `"standard"` | `standard`/`conservative`/`aggressive` |
| `udp-mtu` | int | 1197 (src `1200-3`) | **src-only** |
| `handshake-timeout` | int | 0 (s) | 0 ⇒ only the outer conn timeout |
| `realm-opts` | map | — | see below |

```yaml
realm-opts:
  enable: true                # bool, must be turned on manually
  server-url: https://realm.hy2.io
  token: public
  realm-id: my-cabin-1f3a8c2e9b
  stun-servers:
    - stun.nextcloud.com:3478
    - stun.sip.us:3478
    - global.stun.twilio.com:3478
  # TLS for server-url:
  sni: example.com
  skip-cert-verify: false
  name-cert-verify: example.com
  fingerprint: xxxx
  certificate: ...
  private-key: ...
  alpn: [h3]
```

quic-go knobs (src-only; docs say "do not modify unless you know what you are doing"):
`initial-stream-receive-window`, `max-stream-receive-window`, `initial-connection-receive-window`,
`max-connection-receive-window` (uint64).

No `tls`, no `tfo`, no `mptcp`, no `client-fingerprint`.

```yaml
  - name: "hysteria2-01"
    type: hysteria2
    server: server.com
    port: 443
    ports: 443-8443
    hop-interval: "15-30"
    password: yourpassword
    up: "30 Mbps"
    down: "200 Mbps"
    obfs: salamander
    obfs-password: yourpassword
    obfs-min-packet-size: 512
    obfs-max-packet-size: 1200
    sni: server.com
    skip-cert-verify: false
    name-cert-verify: example.com
    fingerprint: xxxx
    alpn: [h3]
    bbr-profile: standard
    cwnd: 0
    udp-mtu: 1197
    handshake-timeout: 30
    initial-stream-receive-window: 8388608
    max-stream-receive-window: 8388608
    initial-connection-receive-window: 20971520
    max-connection-receive-window: 20971520
    realm-opts:
      enable: true
      server-url: https://realm.hy2.io
      token: public
      realm-id: my-cabin-1f3a8c2e9b
      stun-servers:
        - stun.nextcloud.com:3478
```

### 6.14 `tuic` (v4 & v5)

| key | type | default | notes |
|---|---|---|---|
| `token` | string | — | **v4 only**; must not be set for v5 |
| `uuid` | string | — | **v5 only** |
| `password` | string | — | **v5 only** |
| `ip` | string | — | overrides the DNS result for `server` |
| `heartbeat-interval` | int | — | ms |
| `alpn` | []string | — | docs use `[h3]` |
| `disable-sni` | bool | false | |
| `reduce-rtt` | bool | false | QUIC 0-RTT |
| `request-timeout` | int | — | ms |
| `udp-relay-mode` | string | — | `native`/`quic` |
| `congestion-controller` | string | — | `cubic`/`new_reno`/`bbr` |
| `max-udp-relay-packet-size` | int | — | bytes |
| `fast-open` | bool | false | also the node's TFO flag |
| `max-open-streams` | int | — | |
| `sni` | string | — | |
| `skip-cert-verify`,`name-cert-verify`,`fingerprint`,`certificate`,`private-key` | | | |
| `ech-opts` | map | — | §4 |
| `cwnd` | int | — | **src-only** |
| `bbr-profile` | string | — | **src-only** |
| `recv-window-conn`, `recv-window` | int | — | **src-only** |
| `disable-mtu-discovery` | bool | false | **src-only** |
| `max-datagram-frame-size` | int | — | **src-only** |
| `udp-over-stream` | bool | false | **src-only** |
| `udp-over-stream-version` | int | — | **src-only** |

No `tls`, no `mptcp`. `port` is required.

```yaml
  # TUIC v5
  - name: "tuic-v5"
    type: tuic
    server: www.example.com
    port: 10443
    uuid: 00000000-0000-0000-0000-000000000001
    password: PASSWORD_1
    alpn: [h3]
    sni: example.com
    disable-sni: false
    reduce-rtt: true
    request-timeout: 8000
    heartbeat-interval: 10000
    udp-relay-mode: native
    congestion-controller: bbr
    bbr-profile: standard
    max-udp-relay-packet-size: 1500
    max-open-streams: 20
    fast-open: true
    skip-cert-verify: true
    name-cert-verify: example.com
    cwnd: 0
    recv-window-conn: 0
    recv-window: 0
    disable-mtu-discovery: false
    max-datagram-frame-size: 0
    udp-over-stream: false
    udp-over-stream-version: 0

  # TUIC v4
  - name: "tuic-v4"
    type: tuic
    server: www.example.com
    port: 10443
    token: TOKEN
    alpn: [h3]
    disable-sni: true
    reduce-rtt: true
    udp-relay-mode: native
```

### 6.15 `shadowquic`

| key | type | default | notes |
|---|---|---|---|
| `username` | string | — | required for auth (src: `omitempty`) |
| `password` | string | — | required for auth |
| `sni` | string | — | optional SNI |
| `alpn` | []string | `[h3]` | |
| `quic-versions` | []string | `[v1]` | `v1`/`v2` |
| `udp-over-stream` | bool | false | |
| `zero-rtt` | bool | false | official server may break with 0-RTT |
| `keep-alive-interval` | int | — | ms |
| `congestion-controller` | string | `cubic` | `cubic`/`new_reno`/`bbr` |
| `up` | string | — | `"100 Mbps"` |
| `down` | string | — | `"100 Mbps"` |
| `cwnd` | int | 32 | initial congestion window |
| `bbr-profile` | string | — | `standard`/`conservative`/`aggressive` (only with bbr) |
| `max-datagram-frame-size` | int | 1400 | |
| `max-open-streams` | int | 1024 | |
| `recv-window-conn` | int | 0 ⇒ internal default | per-stream recv window |
| `recv-window` | int | 0 ⇒ internal default | connection recv window |
| `disable-mtu-discovery` | bool | false | |

Always JLS: `jls-opts` must **not** be configured. No `skip-cert-verify`/`fingerprint`/
`certificate`/`private-key` fields exist. No `tfo`/`mptcp`.

```yaml
  - name: "shadowquic-01"
    type: shadowquic
    server: www.example.com
    port: 10443
    username: username
    password: password
    sni: example.com
    alpn: [h3]
    quic-versions: [v1]
    udp-over-stream: false
    zero-rtt: false
    keep-alive-interval: 10000
    congestion-controller: cubic
    up: 100 Mbps
    down: 100 Mbps
    cwnd: 32
    bbr-profile: "standard"
    max-datagram-frame-size: 1400
    max-open-streams: 1024
    recv-window-conn: 0
    recv-window: 0
    disable-mtu-discovery: false
```

### 6.16 `wireguard` (type string is `wireguard`, **not** `wg`)

Top-level:

| key | type | default | notes |
|---|---|---|---|
| `private-key` | string | **required** | base64 client private key |
| `ip` | string | — | local IPv4 in the WG network |
| `ipv6` | string | — | local IPv6 |
| `peers` | list | — | full syntax; if used, top-level `server`/`port`/`public-key`/`pre-shared-key`/`reserved` are ignored |
| `server`,`port`,`public-key`,`pre-shared-key`,`reserved`,`allowed-ips` | | | peer fields, allowed top-level in simplified syntax |
| `allowed-ips` | []string | — | e.g. `['0.0.0.0/0']` |
| `reserved` | []uint8 or string | — | "U4An" string form accepted |
| `persistent-keepalive` | int | — | seconds |
| `udp` | bool | false | |
| `mtu` | int | — | |
| `workers` | int | — | **src-only** |
| `refresh-server-ip-interval` | int | — | **src-only** |
| `ip-stack` | map | — | `mode`: `auto`(default)/`gvisor`/`mips`; `congestion-controller`: `cubic`/`reno`/`bbr`/`bbr3` |
| `remote-dns-resolve` | bool | false | |
| `dns` | []string | — | only with `remote-dns-resolve: true` |
| `amnezia-wg-option` | map | — | presence enables AmneziaWG |

AmneziaWG keys (src): `version` (int; 3 ⇒ v3 impl, other ⇒ legacy), `jc`,`jmin`,`jmax`,`s1`,`s2`,
`s3`,`s4` (int), `h1`–`h4` (string; single value on v1.0/v1.5, ranges on v2+),
`i1`–`i5` (string, v1.5+), `j1`–`j3` (string, v1.5 only, removed in v2+), `itime` (int64, v1.5 only),
`header-protection-key` (string, v3+), `content-padding-addition` (string, v3+),
`rekey-after-time`,`rekey-timeout`,`reject-after-time`,`keepalive-timeout`,`max-handshake-attempts`
(string, v3+), `random-trailers` (bool, v3.1+), `disable-cookies` (bool, v3.1+).

No `tfo`/`mptcp` (accepted but ignored).

```yaml
  # simplified syntax (single peer)
  - name: "wg-01"
    type: wireguard
    server: 162.159.192.1
    port: 2480
    ip: 172.16.0.2
    ipv6: fd01:5ca1:ab1e:80fa:ab85:6eea:213f:f4a5
    private-key: eCtXsJZ27+4PbhDkHnB923tkUn2Gj59wZw5wFA75MnU=
    public-key: Cr8hWlKvtDt7nrvf+f0brNQQzabAqrjfBvas9pmowjo=
    allowed-ips: ['0.0.0.0/0']
    pre-shared-key: 31aIhAPwktDGpH4JDhA8GNvjFXEf/a6+UaQRyOAiyfM=
    reserved: [209, 98, 59]
    persistent-keepalive: 25
    mtu: 1408
    udp: true
    workers: 4
    refresh-server-ip-interval: 0
    ip-stack:
      mode: auto
      congestion-controller: cubic
    remote-dns-resolve: true
    dns: [1.1.1.1, 8.8.8.8]
    amnezia-wg-option:
      version: 3
      jc: 5
      jmin: 500
      jmax: 501
      s1: 30
      s2: 40
      s3: 50
      s4: 8
      h1: "123456"
      h2: "67543"
      h3: "123123"
      h4: "32345"
      i1: "<b 0xf6ab3267fa><b 0xf6ab><t><r 10>"
      i2: "<b 0xf6ab3267fa><r 100>"
      header-protection-key: MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=
      content-padding-addition: 0-32
      rekey-after-time: "120"
      rekey-timeout: "5"
      reject-after-time: "180"
      keepalive-timeout: "10"
      max-handshake-attempts: "18"
      random-trailers: true
      disable-cookies: true

  # full syntax (multiple peers)
  - name: "wg-multi"
    type: wireguard
    ip: 172.16.0.2
    ipv6: fd01:5ca1:ab1e:80fa:ab85:6eea:213f:f4a5
    private-key: eCtXsJZ27+4PbhDkHnB923tkUn2Gj59wZw5wFA75MnU=
    udp: true
    mtu: 1408
    peers:
      - server: 162.159.192.1
        port: 2480
        public-key: Cr8hWlKvtDt7nrvf+f0brNQQzabAqrjfBvas9pmowjo=
        allowed-ips: ['0.0.0.0/0']
        pre-shared-key: 31aIhAPwktDGpH4JDhA8GNvjFXEf/a6+UaQRyOAiyfM=
        reserved: [209, 98, 59]
      - server: 162.159.192.2
        port: 2480
        public-key: Cr8hWlKvtDt7nrvf+f0brNQQzabAqrjfBvas9pmowjo=
        allowed-ips: ['0.0.0.0/1']
```

### 6.17 `ssh`

| key | type | default | notes |
|---|---|---|---|
| `username` | string | **required** | |
| `password` | string | — | |
| `private-key` | string | — | key content **or path** |
| `private-key-passphrase` | string | — | wiki heading spells it `private_key_passphrase`, which decodes to the same key |
| `host-key` | []string | — | empty ⇒ accept all |
| `host-key-algorithms` | []string | — | e.g. `rsa` |

UDP is never supported (`UDP: false` hardcoded). No TLS keys.

```yaml
  - name: "ssh-01"
    type: ssh
    server: 127.0.0.1
    port: 22
    username: root
    password: password
    private-key: key
    private-key-passphrase: key_passphrase
    host-key:
      - "ssh-rsa AAAAB3NzaC1yc2EAA..."
    host-key-algorithms:
      - rsa
```

---

## 7. Built-in pseudo-proxies

```yaml
proxies:
  - name: "direct"
    type: direct          # name only; udp always true
    udp: true             # accepted, ignored (always true)
    ip-version: ipv4
    interface-name: eth0
    routing-mark: 1234
```
`type: dns` and `type: reject` also exist in the switch; `rematch` requires a `rematch` rule set.
`DIRECT`/`REJECT`/`GLOBAL`/`PASS`/`COMPATIBLE` are rule targets, not `proxies` entries.

---

## 8. Gotchas checklist for the PHP builder

1. Emit `socks5`, never `socks`. Emit `wireguard`, never `wg`.
2. VMess/VLESS: SNI key is **`servername`**. Trojan/anytls/hysteria/hysteria2/tuic/shadowquic/http
   use **`sni`**. Emitting `sni` on vmess/vless is silently ignored.
3. `alterId` on vmess is required by the decoder even when `0`.
4. `trojan` has no `tls` key; a `tls: true` there is harmless but meaningless.
5. `http` has no `alpn`/`client-fingerprint`/`udp`.
6. `ssh` never carries UDP; `http` never carries UDP; `sudoku`/`hysteria*`/`tuic`/`shadowquic`
   always carry UDP.
7. `interface-name` + `dialer-proxy` on the same node ⇒ interface/routing-mark/ip-version/tfo/mptcp
   are dropped (src).
8. `_` vs `-`: both work; prefer kebab-case in output.
9. Unknown keys never error — validate key names in PHP rather than relying on mihomo.
10. Nested maps must be real mappings (`ws-opts.headers` is a map, `http-opts.headers` is
    map-of-lists, `h2-opts.host` is a list).
11. `smux.brutal-opts.up/down` are strings in source but weakly-typed — `50` and `"50 Mbps"` both
    decode.
12. `hysteria.hop-interval` is an **int**, `hysteria2.hop-interval` is a **string range**.
13. `mieru.port` and `mieru.port-range` are mutually exclusive.
14. `sudoku.httpmask` (nested) and the flat `http-mask*` keys both exist; nested wins.
15. `tuic.token` (v4) and `tuic.uuid`+`password` (v5) are mutually exclusive in practice.
16. `shadowquic` has **no** `skip-cert-verify`/`fingerprint` keys — do not emit them.
17. `anytls` must not be combined with `reality-opts`.
18. `tlsmirror-opts` is VMess-only.
