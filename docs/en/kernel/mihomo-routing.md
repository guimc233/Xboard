# mihomo (Clash.Meta) ROUTING spec — implementation-ready

Sources: <https://wiki.metacubex.one/en/config/rules/>, `/en/config/rule-providers/`,
`/en/config/rule-providers/content/`, `/en/config/sub-rule/`, `/en/config/general/`,
`/en/config/inbound/listeners/`, `/en/handbook/syntax/`, `/en/config/proxies/built-in/`,
plus `MetaCubeX/mihomo@Meta` source (`rules/parser.go`, `rules/common/*`, `rules/provider/*`,
`constant/provider/interface.go`, `constant/metadata.go`, `component/geodata/*`, `config/config.go`).

`wiki` = stated in the docs. `src` = verified only in the kernel source (undocumented on the wiki).

Notation for rule params: `no-resolve` and `src` are the only two params the parser knows
(`rules/common/base.go`: `NoResolve = "no-resolve"`, `Src = "src"`).

---

## 1. Route rule types

General grammar (`rules/common/base.go`, `ParseRulePayload`):

```
TYPE,payload,target[,param...]          # default form
MATCH,target                            # no payload, no params
TYPE,payload-with-commas,target         # AND, OR, NOT, SUB-RULE, DOMAIN-REGEX,
                                        # PROCESS-NAME-REGEX, PROCESS-PATH-REGEX:
                                        # payload may contain commas; target MUST be the last field
                                        # and no params are accepted for these types
```

- `target` is validated BEFORE the rule is parsed: it must be an existing proxy or proxy-group
  name, or a built-in policy, otherwise the config fails with `proxy [X] not found`
  (`config/config.go:parseRules`). For `SUB-RULE` it must be a key of `sub-rules`.
- Adapter/param/target names are trimmed of spaces (`strings.Trim(e, " ")`).
- Type string is upper-cased by the parser (`tp = strings.ToUpper(item[0])`), so `domain,x,y`
  works; generated configs should still emit upper-case.
- First match wins, evaluated top to bottom. If a matched outbound cannot handle UDP
  (e.g. `ss` node without `udp: true`), matching continues to the next rule (wiki: Priority).

| Type | Payload format | `no-resolve` | `src` | Realistic example |
|---|---|---|---|---|
| `DOMAIN` | exact domain, host compared to `RuleHost()` (sniffed host if sniffing overrode it); payload lower-cased | ignored | ignored | `DOMAIN,ad.com,REJECT` |
| `DOMAIN-SUFFIX` | suffix; matches `x.<suffix>` and `<suffix>` exactly, never `content-google.com` | ignored | ignored | `DOMAIN-SUFFIX,google.com,PROXY` |
| `DOMAIN-KEYWORD` | substring of host | ignored | ignored | `DOMAIN-KEYWORD,google,PROXY` |
| `DOMAIN-WILDCARD` | mihomo wildcard: `*` = zero+ chars (crosses `.`), `?` = exactly one char; lower-cased. NOT the clash wildcard (`+`/`.` not supported here) | ignored | ignored | `DOMAIN-WILDCARD,*.google.com,PROXY` |
| `DOMAIN-REGEX` | regexp2 pattern, compiled `IgnoreCase`; payload may contain commas; no params | ignored | ignored | `DOMAIN-REGEX,^abc.*com,PROXY` |
| `GEOSITE` | geosite list name; `list@attr`, `!list` supported (src, see §3) | ignored | ignored | `GEOSITE,youtube,PROXY` |
| `IP-CIDR` | `netip.ParsePrefix` — CIDR with `/bits` required (`1.1.1.1` alone is invalid) | ✔ | ✔ | `IP-CIDR,127.0.0.0/8,DIRECT,no-resolve` |
| `IP-CIDR6` | alias, identical behaviour to `IP-CIDR` | ✔ | ✔ | `IP-CIDR6,2620:0:2d0:200::7/32,PROXY` |
| `IP-SUFFIX` | `netip.ParsePrefix`; compares the last `bits` bits only (`8.8.8.8/24` matches any IPv4 ending `8.8.8`) | ✔ | ✔ | `IP-SUFFIX,8.8.8.8/24,PROXY` |
| `IP-ASN` | numeric ASN string, compared for equality with the MMDB ASN lookup result | ✔ | ✔ | `IP-ASN,13335,DIRECT` |
| `GEOIP` | country code (payload lower-cased); pseudo-code `lan` = private/loopback/link-local/multicast/unspecified; `!cc` negation only in geodata-mode (src) | ✔ | ✔ | `GEOIP,CN,DIRECT` |
| `SRC-GEOIP` | as `GEOIP`, matched against source IP | implied (hard-coded `true`) | n/a (always source) | `SRC-GEOIP,cn,DIRECT` |
| `SRC-IP-ASN` | as `IP-ASN`, source IP | implied | n/a | `SRC-IP-ASN,9808,DIRECT` |
| `SRC-IP-CIDR` | as `IP-CIDR`, source IP (IPv6 too — same type string) | implied | n/a | `SRC-IP-CIDR,192.168.1.201/32,DIRECT` |
| `SRC-IP-SUFFIX` | as `IP-SUFFIX`, source IP | implied | n/a | `SRC-IP-SUFFIX,192.168.1.201/8,DIRECT` |
| `DST-PORT` | uint16 port/range list (`-` range, `/` or `,` separators) | ignored | ignored | `DST-PORT,80,DIRECT` / `DST-PORT,114-514/810-1919,65530,DIRECT` |
| `SRC-PORT` | uint16 port/range list | ignored | ignored | `SRC-PORT,7777,DIRECT` |
| `IN-PORT` | uint16 port/range list; matches inbound listener port | ignored | ignored | `IN-PORT,7890,PROXY` |
| `IN-TYPE` | `/`-separated tokens, upper-cased: `HTTP`, `HTTPS`, `SOCKS` (expands to SOCKS4+SOCKS5), `SOCKS4`, `SOCKS5`, `SHADOWSOCKS`, `SNELL`, `VMESS`, `VLESS`, `REDIR`, `TPROXY`, `TROJAN`, `TUNNEL`, `TUN`, `TUIC`, `HYSTERIA2`, `ANYTLS`, `MIERU`, `SUDOKU`, `TRUSTTUNNEL`, `SHADOWQUIC`. **`MIXED` is NOT a valid token** (a `mixed` listener reports HTTP or SOCKS4/5) | ignored | ignored | `IN-TYPE,SOCKS/HTTP,PROXY` |
| `IN-USER` | inbound username(s), `/`-separated (src: multiple allowed) | ignored | ignored | `IN-USER,mihomo,PROXY` |
| `IN-NAME` | listener `name`(s), `/`-separated (src) | ignored | ignored | `IN-NAME,ss,PROXY` |
| `REMATCH-NAME` | rematch outbound name(s), `/`-separated (src) | ignored | ignored | `REMATCH-NAME,rematch1,PROXY` |
| `PROCESS-PATH` | full process path, case-insensitive equality | ignored | ignored | `PROCESS-PATH,/usr/bin/wget,PROXY` |
| `PROCESS-PATH-WILDCARD` | `*`/`?`, case-insensitive | ignored | ignored | `PROCESS-PATH-WILDCARD,/usr/*/wget,PROXY` |
| `PROCESS-PATH-REGEX` | regexp2, `IgnoreCase`; commas allowed in payload; no params | ignored | ignored | `PROCESS-PATH-REGEX,(?i).*Application\\chrome.*,PROXY` |
| `PROCESS-NAME` | process name (Android: package name), case-insensitive equality | ignored | ignored | `PROCESS-NAME,chrome.exe,PROXY` |
| `PROCESS-NAME-WILDCARD` | `*`/`?`, case-insensitive | ignored | ignored | `PROCESS-NAME-WILDCARD,*telegram*,PROXY` |
| `PROCESS-NAME-REGEX` | regexp2, `IgnoreCase`; commas allowed; no params | ignored | ignored | `PROCESS-NAME-REGEX,(?i)Telegram,PROXY` |
| `UID` | uint32 uid/range list; Linux/Android ONLY, otherwise load error | ignored | ignored | `UID,1001,DIRECT` |
| `NETWORK` | `tcp` or `udp` (case-insensitive) | ignored | ignored | `NETWORK,udp,DIRECT` |
| `DSCP` | uint8 range list, values 0–63 only; tproxy UDP inbound only | ignored | ignored | `DSCP,4,DIRECT` |
| `RULE-SET` | rule-provider name (see §2) | ✔ | ✔ | `RULE-SET,reject,DIRECT,no-resolve` |
| `AND` | `((rule),(rule),...)`; inner rules are `TYPE,payload[,params]` **without target**; `MATCH` and `SUB-RULE` are rejected inside | ignored | ignored | `AND,((DOMAIN,baidu.com),(NETWORK,UDP)),DIRECT` |
| `OR` | same as AND, at least 2 usually | ignored | ignored | `OR,((NETWORK,UDP),(DOMAIN,baidu.com)),REJECT` |
| `NOT` | `((rule))` — exactly ONE inner rule | ignored | ignored | `NOT,((DOMAIN,baidu.com)),PROXY` |
| `SUB-RULE` | `(rule)` — exactly ONE inner rule, `MATCH`/`SUB-RULE` rejected inside; target = sub-rule name | ignored | ignored | `SUB-RULE,(NETWORK,tcp),sub-rule-1` |
| `MATCH` | no payload; `MATCH,target` (target required) | ignored | ignored | `MATCH,PROXY` |

Notes on flags (`rules/parser.go`, `rules/common/base.go:ParseParams`):

- Only these types actually read params: `GEOIP`, `IP-ASN`, `IP-CIDR`/`IP-CIDR6`, `IP-SUFFIX`,
  `RULE-SET`. Every other type silently ignores trailing tokens — emitting
  `DOMAIN,a.com,DIRECT,no-resolve` validates but does nothing.
- `SRC-GEOIP`, `SRC-IP-ASN`, `SRC-IP-CIDR`, `SRC-IP-SUFFIX` never call `ParseParams`; matching is
  hard-coded source-side and treat `no-resolve` as set. Do not emit params for them.
- `src` implies `no-resolve` (`if isSrc { noResolve = true }`), and `src` swaps src/dst metadata
  before matching (`constants/metadata.go:SwapSrcDst`).
- Semantics of `no-resolve`: for target-IP rules mihomo normally triggers DNS resolution of the
  destination domain before matching; `no-resolve` skips it. Once DNS has been triggered by an
  earlier rule, later `no-resolve` rules still match the resolved IP (wiki).
- Built-in targets: `DIRECT`, `REJECT`, `REJECT-DROP`, `PASS`, `PASS-RULE`, `COMPATIBLE`
  (`/en/config/proxies/built-in/`). Any other target must be an existing proxy or proxy-group
  name. Case matters for the target (proxy names are not transformed).
- Dangling/duplicate names: `SUB-RULE` target resolution checks `proxies` first; only if the target
  is not a proxy name is it checked against `sub-rules`.

---

## 2. `rule-providers` entry schema

YAML key: top-level `rule-providers:` (map). Each child key is the provider **name**
(required, must be unique, is what `RULE-SET` references).

Exact key set (`rules/provider/parse.go:ruleProviderSchema`, struct tags `provider:"..."` =
YAML keys):

| Key | Required | Type / allowed values | Semantics |
|---|---|---|---|
| `type` | **yes** | `file` \| `http` \| `inline` (exact, case-sensitive) | anything else → `unsupported vehicle type: X` |
| `behavior` | **yes** | `domain` \| `ipcidr` \| `classical` (exact, case-sensitive) | anything else → `unsupported behavior type: X`. Empty/missing also → that error |
| `format` | no | `yaml` \| `text` \| `mrs`; omitted/empty → `yaml` | anything else → `unsupported format type: X` |
| `url` | for `http` | string | HTTP(S) source; `file`/`inline` ignore it |
| `path` | no | string (file path) | `file`: the file to read. `http`: overrides the cache path (otherwise md5 of the URL is used: `rules/<md5>` under HomeDir). Must be inside `-d` HomeDir unless `SAFE_PATHS` |
| `interval` | no | int seconds (`time.Duration(uint(interval)) * time.Second`) | provider update interval; `http` only |
| `proxy` | no | proxy name | download/update through that proxy; `http` only |
| `size-limit` | no | int, bytes; `0` = unlimited (default) | max downloadable size; `http` only |
| `header` | no | map<string, list<string>> (`map[string][]string`) | custom HTTP request headers; `http` only |
| `payload` | yes for `inline` | list<string> | inline rule content; `inline` only |
| `path-in-bundle` | no | string | if the local file is missing, extract that entry from `BundleMRS.7z` in HomeDir |
| `rules` | — | list<string> | **src**: the decoder also accepts a `rules:` key instead of `payload:` (`RulePayload` struct) — undocumented on the wiki |

Decoder behaviour worth knowing for a generator: the provider mapping is decoded with
`structure.Decoder` in *non-strict* mode — **unknown keys are silently ignored** (no
`ErrorUnused`; `common/structure/structure.go`). So a typo like `size-limits:` is dropped
without error, while a typo of `behavior`/`type` fails only because the field ends up empty.
Always emit the exact key names above.

`format` vs `behavior` compatibility (`rules/provider/mrs_reader.go`):

- `mrs` is a zstd-compressed binary whose header stores a behavior byte
  (`domain`=0, `ipcidr`=1, `classical`=2). `classical` has no MRS reader → load error
  `invalid format`; a behavior mismatch with the file → `invalid behavior`.
  Therefore `format: mrs` works only with `behavior: domain` or `behavior: ipcidr`.
- `yaml`: file has a top-level `payload:` (list). Missing `payload` → `file must have a payload field`.
- `text`: one entry per line; `#` and `//` are comments; blank lines skipped.
- `classical` payload lines are full rule expressions **without a target**, e.g.
  `IP-CIDR,127.0.0.0/8,no-resolve`; `MATCH`, `RULE-SET` and `SUB-RULE` are rejected inside a
  classical provider.

Per-behavior payload content (`/en/config/rule-providers/content/`):

- `domain`: list of clash-format wildcard domains — `'.blogger.com'`, `'*.*.microsoft.com'`,
  `'books.itunes.apple.com'`. Clash wildcards: `*` one label only, `+` = suffix (multi-level),
  leading `.` = multi-level but not the bare domain. A `/` in a domain entry is skipped with a
  warning (src).
- `ipcidr`: list of CIDRs — `'192.168.1.0/24'`.
- `classical`: list of rule expressions, all types except rule-set/sub-rule.

`RULE-SET` rule syntax:

```
RULE-SET,<provider-name>,<target>[,no-resolve]
RULE-SET,<provider-name>,<target>[,src]
```

- `<provider-name>` must equal a `rule-providers` key (checked after parsing:
  `rule set [X] not found`).
- `<target>` is required and must be a valid proxy/proxy-group/built-in.
- `no-resolve`: suppresses DNS resolution for the provider's target-IP rules (it sets
  `helper.ResolveIP = nil` for the whole provider match).
- `src`: swaps source/destination before matching and implies `no-resolve`.
- A `domain`-behavior provider never resolves (matches the host); an `ipcidr`-behavior provider
  always tries to resolve **unless** the `RULE-SET` line carries `no-resolve`/`src`.

---

## 3. GEOX / geodata

There is **no `/en/config/geox/` page** (HTTP 404, EN and ZH). All GEO config lives in the
General page + source (`config/config.go`). Keys (top-level):

| Key | Type / default | Meaning |
|---|---|---|
| `geodata-mode` | bool, default `false` | `false` → `GEOIP` reads MMDB (`geox-url.mmdb`); `true` → `GEOIP` reads `geoip.dat` (`geox-url.geoip`) |
| `geodata-loader` | string, default `memconservative` | `standard` \| `memconservative`; `memc` is accepted as an alias for `memconservative` (src). Not validated: any other string makes the loader lookup fail at load time |
| `geosite-matcher` | string, default `succinct` | **src / not in the wiki**: values `mph` or `hybrid` select the MPH matcher; anything else (incl. omitted) selects `succinct` |
| `geo-auto-update` | bool, default `false` | periodically refresh local geodata |
| `geo-update-interval` | int hours, default `24` | interval for the above |
| `geox-url` | map | `geoip`, `mmdb`, `asn`, `geosite` — exact sub-keys, all strings |

`geox-url` defaults (`config/config.go` rawDefaultConfig):

```yaml
geox-url:
  geoip: "https://github.com/MetaCubeX/meta-rules-dat/releases/download/latest/geoip.dat"
  mmdb: "https://github.com/MetaCubeX/meta-rules-dat/releases/download/latest/geoip.metadb"
  asn: "https://github.com/MetaCubeX/meta-rules-dat/releases/download/latest/GeoLite2-ASN.mmdb"
  geosite: "https://github.com/MetaCubeX/meta-rules-dat/releases/download/latest/geosite.dat"
```

(Note the sub-key is `geoip`, while the internal JSON tag is `geo-ip` — the YAML key is `geoip`.)

How geosite/geoip resolve:

- **Always local matching.** `GEOSITE` loads `GeoSite.dat`, `GEOIP` loads either `GeoIP.dat`
  (`geodata-mode: true`) or the MMDB, `IP-ASN` loads the ASN MMDB. Files are taken from the
  working directory; if missing they are downloaded once from `geox-url`, verified, and
  re-downloaded when invalid (`component/geodata/init.go`). Remote access happens only for that
  download, not per-request.
- `GEOSITE` ignores `geodata-mode` — it always uses `geosite.dat`.
- `GEOIP` country codes are lower-cased. Pseudo-code `lan` matches private, unspecified,
  loopback, multicast and link-local addresses (`src`; not in the wiki).
- `GEOIP,!cn` negation works **only** in `geodata-mode: true` (handled by
  `LoadGeoIPMatcher`); in MMDB mode `!cn` is looked up literally and never matches (`src`).
- `GEOSITE` payload may be `list`, `list@attr` (e.g. `cn@ads`) or `!list`; a leading `!`
  negates the whole matcher; attribute filtering happens after loading the list (`src`).
- DNS interaction: `GEOIP`/`IP-ASN`/`IP-CIDR`/`IP-SUFFIX` match on the destination IP and will
  trigger domain→IP resolution unless `no-resolve` (or `src`). `GEOSITE` matches the host and
  never resolves.

---

## 4. `sub-rules` and listener `rule:`

`sub-rules` is a top-level map `string -> list of rule strings`:

```yaml
sub-rules:
  sub-rule-1:
    - DOMAIN-SUFFIX,baidu.com,DIRECT
    - MATCH,PROXY
  sub-rule-2:
    - IP-CIDR,1.1.1.1/32,REJECT
    - IP-CIDR,8.8.8.8/32,ss1
    - DOMAIN,dns.alidns.com,REJECT
```

- Each entry is parsed with the same `TYPE,payload,target[,param...]` grammar as `rules`.
- Rules inside a sub-rule are evaluated in order; the first match's target decides.
- A sub-rule may reference another sub-rule with `SUB-RULE,(rule),other-sub-rule`; circular
  references are detected and rejected (`sub-rule error: circular references a->b->a`).
- An empty sub-rule name is a config error.
- `SUB-RULE` must wrap exactly one inner rule and that inner rule cannot be `MATCH` or `SUB-RULE`.
- `PASS` inside a sub-rule leaves the sub-rule and continues in the main `rules` list (later
  lines of the same sub-rule are not evaluated). `PASS-RULE` skips only the current rule branch
  and continues inside the sub-rule (`/en/config/proxies/built-in/`, `rules/logic/logic.go`).

Listener reference (`/en/config/inbound/listeners/`):

```yaml
listeners:
  - name: in-name
    type: shadowsocks
    port: 10000
    listen: 0.0.0.0
    routing-mark: 0
    rule: sub-rule-1        # references a sub-rules key
    proxy: proxy            # optional; overrides rules entirely
```

- `rule` is the **name** of a `sub-rules` entry (no `SUB-RULE,` prefix, no parentheses).
- Empty/missing `rule` → the global `rules` list is used.
- If the referenced sub-rule does not exist, the global `rules` list is used directly
  (silently, no error).
- `proxy` overrides everything for that listener; when non-empty it must be a valid proxy
  name or startup fails.

---

## 5. End-to-end example

```yaml
# ---------- general ----------
mode: rule                      # rule | global | direct
log-level: info                 # silent | error | warning | info | debug
ipv6: true
allow-lan: false
bind-address: "*"
mixed-port: 7890
external-controller: 127.0.0.1:9090
secret: ""
external-ui: ui
unified-delay: false
tcp-concurrent: true
find-process-mode: strict       # always | strict | off
interface-name: ""
routing-mark: 0
# global-client-fingerprint is DEPRECATED (set client-fingerprint per proxy)
profile:
  store-selected: true
  store-fake-ip: true

# ---------- geodata ----------
geodata-mode: false             # false: GEOIP uses mmdb; true: GEOIP uses geoip.dat
geodata-loader: memconservative # standard | memconservative
geosite-matcher: succinct       # src-only key; mph|hybrid => mph, else succinct
geo-auto-update: true
geo-update-interval: 24         # hours
geox-url:
  geoip: "https://github.com/MetaCubeX/meta-rules-dat/releases/download/latest/geoip.dat"
  geosite: "https://github.com/MetaCubeX/meta-rules-dat/releases/download/latest/geosite.dat"
  mmdb: "https://github.com/MetaCubeX/meta-rules-dat/releases/download/latest/geoip.metadb"
  asn: "https://github.com/MetaCubeX/meta-rules-dat/releases/download/latest/GeoLite2-ASN.mmdb"

# ---------- rules ----------
rule-providers:
  reject-domain:                # behavior: domain  -> payload is a list of domains
    type: http
    behavior: domain
    format: yaml
    url: "https://raw.githubusercontent.com/MetaCubeX/meta-rules-dat/meta/geo/geosite/category-ads-all.yaml"
    path: ./ruleset/reject-domain.yaml
    interval: 86400
    proxy: DIRECT
    size-limit: 0
    header:
      User-Agent:
        - "mihomo/1.18.3"

  private-ip:                   # behavior: ipcidr  -> payload is a list of CIDRs
    type: http
    behavior: ipcidr
    format: text
    url: "https://raw.githubusercontent.com/MetaCubeX/meta-rules-dat/meta/geo/geoip/private.txt"
    path: ./ruleset/private-ip.txt
    interval: 86400

  cn-classical:                 # behavior: classical -> payload is a list of rule expressions
    type: http
    behavior: classical
    format: yaml
    url: "https://raw.githubusercontent.com/MetaCubeX/meta-rules-dat/meta/geo/geosite/cn.yaml"
    path: ./ruleset/cn-classical.yaml
    interval: 86400
    proxy: DIRECT
    size-limit: 10485760

  inline-ad:                    # type: inline -> payload carries the rules directly
    type: inline
    behavior: domain
    payload:
      - "ads.example.com"
      - "+.doubleclick.net"

  local-mrs:                    # mrs works only with domain / ipcidr behavior
    type: file
    behavior: ipcidr
    format: mrs
    path: ./ruleset/cn-ip.mrs

rules:
  # --- inline domain / port / process rules ---
  - DOMAIN,ad.com,REJECT
  - DOMAIN-SUFFIX,google.com,PROXY
  - DOMAIN-KEYWORD,telegram,PROXY
  - DOMAIN-WILDCARD,*.googleapis.com,PROXY
  - DOMAIN-REGEX,^abc.*\.com$,PROXY
  - DST-PORT,114-514/810-1919,REJECT
  - IN-TYPE,SOCKS/HTTP,PROXY
  - PROCESS-NAME,chrome.exe,PROXY

  # --- provider-backed rules (with and without no-resolve) ---
  - RULE-SET,reject-domain,REJECT
  - RULE-SET,inline-ad,REJECT
  - RULE-SET,private-ip,DIRECT,no-resolve
  - RULE-SET,local-mrs,DIRECT,no-resolve
  - RULE-SET,cn-classical,DIRECT

  # --- source-IP style matching ---
  - SRC-IP-CIDR,192.168.1.201/32,DIRECT
  - SRC-IP-SUFFIX,192.168.1.201/8,DIRECT

  # --- geosite / geoip ---
  - GEOSITE,youtube,PROXY
  - GEOSITE,cn,DIRECT
  - GEOIP,CN,DIRECT
  - GEOIP,lan,DIRECT
  - IP-CIDR,10.0.0.0/8,DIRECT,no-resolve
  - IP-ASN,13335,DIRECT

  # --- logic rules ---
  - AND,((DOMAIN,baidu.com),(NETWORK,UDP)),DIRECT
  - OR,((NETWORK,UDP),(DOMAIN,baidu.com)),REJECT
  - NOT,((DOMAIN,baidu.com)),PROXY
  - SUB-RULE,(NETWORK,tcp),sub-rule-1

  - MATCH,PROXY

# ---------- sub-rules ----------
sub-rules:
  sub-rule-1:
    - DOMAIN-SUFFIX,baidu.com,DIRECT
    - IP-CIDR,1.1.1.1/32,DIRECT,no-resolve
    - MATCH,PROXY
  sub-rule-2:
    - IP-CIDR,8.8.8.8/32,REJECT
    - SUB-RULE,(NETWORK,udp),sub-rule-1

# ---------- listeners referencing a sub-rule ----------
listeners:
  - name: ss-in
    type: shadowsocks
    port: 10000
    listen: 0.0.0.0
    rule: sub-rule-1
  - name: http-in
    type: http
    port: 10001
    listen: 0.0.0.0
    proxy: PROXY
```

---

## 6. Generator checklist (PHP)

1. `type` ∈ {`file`,`http`,`inline`}; `behavior` ∈ {`domain`,`ipcidr`,`classical`};
   `format` ∈ {`yaml`,`text`,`mrs`} (omit or `yaml` for the default). Reject `mrs` + `classical`.
2. `url` required iff `type: http`; `payload` required iff `type: inline`; `path` required in
   practice for `type: file`; `interval` in **seconds**; `size-limit` in **bytes**, `0` = unlimited.
3. `header` must be emitted as header-name → **list** of values.
4. `RULE-SET,<name>,<target>` — validate `<name>` against the `rule-providers` keys and
   `<target>` against proxies/proxy-groups/built-ins before writing the config, because mihomo
   aborts the whole config on an unknown name.
5. Emit `,no-resolve` only for `GEOIP`, `IP-CIDR`, `IP-CIDR6`, `IP-SUFFIX`, `IP-ASN`, `RULE-SET`;
   never emit params on `SRC-*` rules (ignored) or on `AND`/`OR`/`NOT`/`SUB-RULE`/`*-REGEX`
   (parsed as part of the payload).
6. Quote YAML strings that start with `.`, `+`, `*`, `!`, or contain `@`, `,`, `#`.
   Clash wildcards in `behavior: domain` payloads must be quoted (`'.blogger.com'`).
7. Do not emit `MIXED` in `IN-TYPE` (use `SOCKS`/`SOCKS4`/`SOCKS5`); do not emit
   `global-client-fingerprint` (deprecated).
8. `geox-url` sub-keys are `geoip`, `mmdb`, `asn`, `geosite` (not `geo-ip`/`geo-site`);
   `geo-update-interval` is in **hours**.
9. Undocumented but valid (state explicitly if surfaced in the admin UI):
   `geosite-matcher`, the `rules:` provider key, `GEOSITE` `list@attr` / `!list`,
   `GEOIP,lan`, `GEOIP,!cc` (geodata-mode only), multi-value `IN-NAME`/`REMATCH-NAME`,
   `path-in-bundle`.
