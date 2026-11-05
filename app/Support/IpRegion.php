<?php

namespace App\Support;

use Ip2Region as Ip2RegionSearcher;

/**
 * Thin wrapper around the bundled ip2region database.
 *
 * The panel ships ip2region.xdb (vendor/zoujingli/ip2region), which resolves a
 * public IP to "国家|区域|省份|城市|ISP". Only the country field is used here.
 */
class IpRegion
{
    private static ?Ip2RegionSearcher $searcher = null;

    /**
     * Country name for a public address, or null when the address is invalid,
     * private/reserved, or the lookup fails.
     */
    public static function country(string $ip): ?string
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        // ip2region only covers public space; keep loopback/RFC1918 out.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        try {
            self::$searcher ??= new Ip2RegionSearcher();
            $region = (string) (self::$searcher->memorySearch($ip)['region'] ?? '');
        } catch (\Throwable) {
            return null;
        }

        $country = trim((string) explode('|', $region)[0]);

        return ($country === '' || $country === '0') ? null : $country;
    }

    /**
     * true/false for a known public address, null when it cannot be decided.
     */
    public static function isChina(string $ip): ?bool
    {
        $country = self::country($ip);

        return $country === null ? null : $country === '中国';
    }
}
