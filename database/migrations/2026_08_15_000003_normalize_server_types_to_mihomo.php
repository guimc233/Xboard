<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rewrite node types that only existed for the retired Xray / sing-box kernels
 * into their mihomo equivalents, so stored rows validate and query uniformly.
 */
return new class extends Migration
{
    private const TYPE_ALIASES = [
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
        'naive' => 'http',
        'naiveproxy' => 'http',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('v2_server') || !Schema::hasColumn('v2_server', 'type')) {
            return;
        }

        foreach (self::TYPE_ALIASES as $legacy => $canonical) {
            DB::table('v2_server')
                ->whereRaw('LOWER(type) = ?', [$legacy])
                ->update(['type' => $canonical]);
        }
    }

    public function down(): void
    {
        // One-way normalization; the original kernel-specific names no longer
        // have a meaning in this build.
    }
};
