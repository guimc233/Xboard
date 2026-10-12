<?php

namespace App\Support\Mihomo;

/**
 * Compiles the panel's normalized route templates into mihomo route rules,
 * rule-providers and sub-rules.
 *
 * Input is the `route_rules_v2` / `rule_sets` shape produced by
 * ServerService::buildNodeConfig, so this class stays free of database access.
 *
 * @see docs/en/kernel/mihomo-routing.md
 */
class RuleBuilder
{
    /**
     * mihomo built-in outbound targets.
     */
    public const ACTION_TARGETS = [
        'direct' => 'DIRECT',
        'block' => 'REJECT',
    ];

    /**
     * Rule types that accept a trailing `no-resolve` flag.
     */
    private const NO_RESOLVE_TYPES = [
        'GEOIP',
        'IP-CIDR',
        'IP-CIDR6',
        'IP-SUFFIX',
        'IP-ASN',
        'RULE-SET',
    ];

    /**
     * Build the `rule-providers:` map from configured rule sets.
     *
     * @param iterable<array{tag?: string, mihomo?: array<string, mixed>}> $ruleSets
     * @return array<string, array<string, mixed>>
     */
    public static function ruleProviders(iterable $ruleSets): array
    {
        $providers = [];

        foreach ($ruleSets as $ruleSet) {
            $tag = trim((string) ($ruleSet['tag'] ?? ''));
            $settings = $ruleSet['mihomo'] ?? null;
            if ($tag === '' || !is_array($settings)) {
                continue;
            }

            $type = $settings['type'] ?? 'http';
            $behavior = $settings['behavior'] ?? 'domain';
            $format = $settings['format'] ?? 'yaml';

            // mrs is a binary format that only carries domain/ipcidr behaviour;
            // classical providers must stay textual.
            if ($format === 'mrs' && $behavior === 'classical') {
                $format = 'yaml';
            }

            $provider = array_filter([
                'type' => $type,
                'behavior' => $behavior,
                'format' => $format,
                'url' => $type === 'http' ? ($settings['url'] ?? null) : null,
                'path' => $settings['path'] ?? null,
                'interval' => self::intervalToSeconds($settings['interval'] ?? null),
                'proxy' => $type === 'http' ? ($settings['proxy'] ?? null) : null,
                'size-limit' => $type === 'http' ? ($settings['size_limit'] ?? null) : null,
                'header' => $type === 'http' ? self::normalizeHeader($settings['header'] ?? null) : null,
                'payload' => $type === 'inline' ? ($settings['payload'] ?? null) : null,
            ], fn ($value) => $value !== null && $value !== '' && $value !== []);

            $providers[$tag] = $provider;
        }

        return $providers;
    }

    /**
     * Compile route rules into mihomo rule lines. Rule sets referenced by a
     * rule are expected to exist in the provider map already.
     *
     * @param iterable<array<string, mixed>> $routes
     * @param array<string, array<string, mixed>> $providers
     * @return list<string>
     */
    public static function rules(iterable $routes, array $providers = []): array
    {
        $rules = [];

        foreach ($routes as $route) {
            if (!empty($route['disabled'])) {
                continue;
            }

            $target = self::target($route['action'] ?? []);
            if ($target === null) {
                continue;
            }

            $match = is_array($route['match'] ?? null) ? $route['match'] : [];

            foreach ((array) ($match['rule_sets'] ?? []) as $tag) {
                $tag = trim((string) $tag);
                if ($tag !== '' && array_key_exists($tag, $providers)) {
                    $rules[] = self::line('RULE-SET', $tag, $target, self::providerNeedsNoResolve($providers[$tag]));
                }
            }

            foreach ((array) ($match['domains'] ?? []) as $domain) {
                $domain = trim((string) $domain);
                if ($domain !== '') {
                    $rules[] = self::line('DOMAIN', $domain, $target);
                }
            }

            foreach ((array) ($match['domain_suffixes'] ?? []) as $suffix) {
                $suffix = trim((string) $suffix);
                if ($suffix !== '') {
                    $rules[] = self::line('DOMAIN-SUFFIX', $suffix, $target);
                }
            }

            foreach ((array) ($match['ip_cidrs'] ?? []) as $cidr) {
                $cidr = trim((string) $cidr);
                if ($cidr !== '') {
                    $rules[] = self::line('IP-CIDR', $cidr, $target, true);
                }
            }

            foreach ((array) ($match['ports'] ?? []) as $port) {
                $port = trim((string) $port);
                if ($port !== '') {
                    $rules[] = self::line('DST-PORT', $port, $target);
                }
            }

            foreach ((array) ($match['source_cidrs'] ?? []) as $cidr) {
                $cidr = trim((string) $cidr);
                if ($cidr !== '') {
                    // SRC-* types hard-code source matching and no-resolve.
                    $rules[] = self::line('SRC-IP-CIDR', $cidr, $target);
                }
            }

            foreach ((array) ($match['source_ports'] ?? []) as $port) {
                $port = trim((string) $port);
                if ($port !== '') {
                    $rules[] = self::line('SRC-PORT', $port, $target);
                }
            }

            foreach ((array) ($match['networks'] ?? []) as $network) {
                $network = strtolower(trim((string) $network));
                if (in_array($network, ['tcp', 'udp'], true)) {
                    $rules[] = self::line('NETWORK', $network, $target);
                }
            }
        }

        return $rules;
    }

    /**
     * Group route templates into named mihomo sub-rules so a listener can bind
     * one with `rule: <name>`.
     *
     * @param iterable<array{name?: string, disabled?: bool, match?: array, action?: array}> $routes
     * @param array<string, array<string, mixed>> $providers
     * @return array<string, list<string>>
     */
    public static function subRules(iterable $routes, array $providers = []): array
    {
        $subRules = [];

        foreach ($routes as $index => $route) {
            if (!empty($route['disabled'])) {
                continue;
            }

            $name = self::subRuleName($route['name'] ?? null, $index);
            $lines = self::rules([$route], $providers);
            if ($lines !== []) {
                $subRules[$name] = $lines;
            }
        }

        return $subRules;
    }

    /**
     * @param array<string, mixed> $action
     */
    public static function target(array $action): ?string
    {
        $type = (string) ($action['type'] ?? '');

        if (isset(self::ACTION_TARGETS[$type])) {
            return self::ACTION_TARGETS[$type];
        }

        if ($type === 'route') {
            $target = trim((string) ($action['target'] ?? ''));

            return $target === '' ? null : $target;
        }

        return null;
    }

    /**
     * Only ipcidr providers resolve destinations by default, so only they need
     * an explicit `no-resolve`. Classical payloads keep the kernel default to
     * avoid changing their semantics.
     *
     * @param array<string, mixed> $provider
     */
    private static function providerNeedsNoResolve(array $provider): bool
    {
        return ($provider['behavior'] ?? '') === 'ipcidr';
    }

    private static function line(string $type, string $payload, string $target, bool $noResolve = false): string
    {
        $line = $type . ',' . $payload . ',' . $target;
        if ($noResolve && in_array($type, self::NO_RESOLVE_TYPES, true)) {
            $line .= ',no-resolve';
        }

        return $line;
    }

    private static function subRuleName(?string $name, int $index): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return 'rule-' . ($index + 1);
        }

        $slug = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'rule-' . ($index + 1) : $slug;
    }

    /**
     * Accept the admin UI's "1d / 12h / 30m / 60s" shorthand and raw seconds.
     */
    public static function intervalToSeconds(mixed $interval): ?int
    {
        if ($interval === null || $interval === '') {
            return null;
        }

        if (is_int($interval) || (is_string($interval) && ctype_digit($interval))) {
            return (int) $interval;
        }

        if (!is_string($interval)) {
            return null;
        }

        if (!preg_match('/^(\d+)(s|m|h|d)?$/', trim($interval), $matches)) {
            return null;
        }

        $value = (int) $matches[1];

        return match ($matches[2] ?? 's') {
            'd' => $value * 86400,
            'h' => $value * 3600,
            'm' => $value * 60,
            default => $value,
        };
    }

    /**
     * mihomo `header` values must be lists of strings.
     *
     * @return array<string, list<string>>|null
     */
    private static function normalizeHeader(mixed $header): ?array
    {
        if (!is_array($header) || $header === []) {
            return null;
        }

        $normalized = [];
        foreach ($header as $name => $values) {
            if (is_string($values)) {
                $values = [$values];
            }
            if (!is_array($values)) {
                continue;
            }
            $normalized[(string) $name] = array_values(array_map('strval', $values));
        }

        return $normalized === [] ? null : $normalized;
    }
}
