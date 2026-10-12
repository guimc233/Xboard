<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The panel now drives mihomo only. Agents of the retired Xray / sing-box
 * generations may still report their old kernel names on the next heartbeat;
 * folding the historical rows keeps reporting uniform until they are upgraded.
 */
return new class extends Migration
{
    private const LEGACY_KERNELS = [
        'xray',
        'singbox',
        'sing-box',
        'sing_box',
        'clash',
        'clashmeta',
        'clash-meta',
        'clash.meta',
        'meta',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('v2_server_machine') || !Schema::hasColumn('v2_server_machine', 'kernel_type')) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count(self::LEGACY_KERNELS), '?'));

        DB::table('v2_server_machine')
            ->whereRaw("LOWER(kernel_type) IN ({$placeholders})", self::LEGACY_KERNELS)
            ->update(['kernel_type' => 'mihomo']);
    }

    public function down(): void
    {
        // One-way normalization: the original per-agent kernel name is not
        // recoverable and is no longer meaningful.
    }
};
