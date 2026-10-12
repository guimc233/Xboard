<?php

namespace App\Support;

/**
 * Central registry for the mihomo (Clash.Meta) kernel.
 *
 * The panel drives exactly one proxy kernel. Everything that used to branch
 * between Xray and sing-box now resolves through this class, so the supported
 * inbound set, the aliases accepted from legacy rows, and the value mihomo
 * expects on the wire all live in one place.
 *
 * @see docs/en/kernel/mihomo-inbound-listeners.md for the full field reference
 */
class Mihomo
{
    public const KERNEL = 'mihomo';

    /**
     * Canonical server type => the `type:` string mihomo uses in `listeners[]`.
     *
     * Only inbounds that can be rendered as a client proxy are offered as node
     * types. mihomo's non-proxy listeners (tun, redir, tproxy, tunnel) and the
     * Hysteria2 realm registry are deliberately excluded: a node that produces
     * no subscription entry has no place in this panel.
     */
    public const INBOUNDS = [
        'http' => 'http',
        'socks' => 'socks',
        'mixed' => 'mixed',
        'shadowsocks' => 'shadowsocks',
        'vmess' => 'vmess',
        'vless' => 'vless',
        'trojan' => 'trojan',
        'anytls' => 'anytls',
        'snell' => 'snell',
        'mieru' => 'mieru',
        'sudoku' => 'sudoku',
        'tuic' => 'tuic',
        'hysteria2' => 'hysteria2',
        'shadowquic' => 'shadowquic',
        'trusttunnel' => 'trusttunnel',
    ];

    /**
     * Accepted spellings from older rows and admin input, folded into the
     * canonical server type. Keys are lowercase.
     */
    public const ALIASES = [
        'hysteria' => 'hysteria2',
        'hy2' => 'hysteria2',
        'hysteria-2' => 'hysteria2',
        'ss' => 'shadowsocks',
        'ss2022' => 'shadowsocks',
        'socks5' => 'socks',
        'socks4' => 'socks',
        'v2ray' => 'vmess',
        'vmess-aead' => 'vmess',
        'hy' => 'hysteria2',
        // NaiveProxy is an HTTP/2 CONNECT proxy over TLS; mihomo serves that as
        // an `http` listener with the certificate attached.
        'naive' => 'http',
        'naiveproxy' => 'http',
    ];

    /**
     * Inbound types that require a certificate + private key: mihomo declares
     * their TLS fields without `omitempty`, so a missing pair is a fatal parse
     * error and the panel must refuse to push such a node.
     */
    public const TLS_REQUIRED = [
        'tuic',
        'hysteria2',
        'trusttunnel',
    ];

    /**
     * Inbound types whose TLS block is optional (plaintext allowed).
     */
    public const TLS_OPTIONAL = [
        'http',
        'socks',
        'mixed',
        'vmess',
        'vless',
        'trojan',
        'anytls',
    ];

    /**
     * Inbound types that support a `reality-config` block.
     */
    public const REALITY_CAPABLE = [
        'vmess',
        'vless',
        'trojan',
        'http',
        'socks',
        'mixed',
    ];

    /**
     * Shape of the generated `users` list per inbound type.
     *
     * map                 => { username: password }
     * array_user_password => [{ username, password }]
     * array_uuid          => [{ username, uuid, alterId|flow }]
     * array_password      => [{ username, password }]  (trojan)
     * array_name_password => [{ name, password }]      (shadow-tls only)
     */
    public const USER_SHAPES = [
        'http' => 'array_user_password',
        'socks' => 'array_user_password',
        'mixed' => 'array_user_password',
        'trusttunnel' => 'array_user_password',
        'shadowquic' => 'array_user_password',
        'vmess' => 'array_uuid',
        'vless' => 'array_uuid',
        'trojan' => 'array_password',
        'anytls' => 'map',
        'hysteria2' => 'map',
        'mieru' => 'map',
        'tuic' => 'map',
    ];

    /**
     * Shadowsocks ciphers mihomo accepts, with the 2022 key sizes used by the
     * per-node shared key. Stream ciphers from the Xray era (cfb/ctr/rc4-md5)
     * are deliberately absent: mihomo rejects them.
     */
    public const SHADOWSOCKS_CIPHERS = [
        '2022-blake3-aes-128-gcm' => 16,
        '2022-blake3-aes-256-gcm' => 32,
        '2022-blake3-chacha20-poly1305' => 32,
        'none' => null,
        'dummy' => null,
        'aes-128-gcm' => null,
        'aes-192-gcm' => null,
        'aes-256-gcm' => null,
        'chacha20-ietf-poly1305' => null,
        'xchacha20-ietf-poly1305' => null,
    ];

    public static function normalizeType(?string $type): ?string
    {
        if ($type === null || trim($type) === '') {
            return null;
        }

        $normalized = strtolower(trim($type));

        return self::ALIASES[$normalized] ?? $normalized;
    }

    public static function isValidType(?string $type): bool
    {
        return $type !== null && array_key_exists(self::normalizeType($type) ?? '', self::INBOUNDS);
    }

    /**
     * The `type:` string mihomo expects for a canonical server type.
     */
    public static function listenerType(?string $type): ?string
    {
        $normalized = self::normalizeType($type);

        return $normalized === null ? null : (self::INBOUNDS[$normalized] ?? null);
    }

    /**
     * Every accepted inbound produces a client proxy, so "subscription type"
     * and "valid type" are the same set.
     */
    public static function isSubscriptionType(?string $type): bool
    {
        return self::isValidType($type);
    }

    public static function requiresTls(?string $type): bool
    {
        return in_array(self::normalizeType($type), self::TLS_REQUIRED, true);
    }

    public static function supportsTls(?string $type): bool
    {
        $normalized = self::normalizeType($type);

        return in_array($normalized, self::TLS_REQUIRED, true)
            || in_array($normalized, self::TLS_OPTIONAL, true);
    }

    public static function supportsReality(?string $type): bool
    {
        return in_array(self::normalizeType($type), self::REALITY_CAPABLE, true);
    }

    public static function userShape(?string $type): ?string
    {
        return self::USER_SHAPES[self::normalizeType($type) ?? ''] ?? null;
    }

    /**
     * Canonical server types, in a stable display order.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::INBOUNDS);
    }

    /**
     * Canonical server types that can appear in a user subscription.
     *
     * @return list<string>
     */
    public static function subscriptionTypes(): array
    {
        return self::types();
    }
}
