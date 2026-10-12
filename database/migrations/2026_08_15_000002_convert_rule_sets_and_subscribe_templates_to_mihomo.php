<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert stored sing-box / Xray rule-set sources into mihomo rule-providers
 * and drop the subscription templates for kernels this build no longer ships.
 *
 * The original definitions are preserved under `settings.legacy` so an operator
 * can still see what a converted provider came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convertRuleSets();
        $this->pruneSubscribeTemplates();
    }

    public function down(): void
    {
        // Converted rule providers keep their legacy payload, so no destructive
        // rollback is needed.
    }

    private function convertRuleSets(): void
    {
        if (!Schema::hasTable('v2_rule_set')) {
            return;
        }

        DB::table('v2_rule_set')->orderBy('id')->each(function ($row) {
            $settings = json_decode($row->settings ?? '[]', true);
            if (!is_array($settings) || isset($settings['mihomo'])) {
                return;
            }

            $singbox = is_array($settings['singbox'] ?? null) ? $settings['singbox'] : [];
            $xray = is_array($settings['xray'] ?? null) ? $settings['xray'] : [];

            $isBinary = ($singbox['format'] ?? 'source') === 'binary';
            $remote = ($singbox['type'] ?? 'remote') === 'remote';

            $mihomo = array_filter([
                'type' => $remote ? 'http' : 'file',
                'behavior' => $isBinary ? 'domain' : 'classical',
                'format' => $isBinary ? 'mrs' : 'yaml',
                'url' => $remote ? ($singbox['url'] ?? null) : null,
                'path' => $remote ? null : ($singbox['path'] ?? null),
                'interval' => $singbox['update_interval'] ?? null,
                'proxy' => $singbox['download_detour'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            DB::table('v2_rule_set')->where('id', $row->id)->update([
                'settings' => json_encode([
                    'mihomo' => $mihomo,
                    'legacy' => [
                        'singbox' => $singbox ?: null,
                        'xray' => $xray ?: null,
                    ],
                ]),
                'updated_at' => now(),
            ]);
        });
    }

    private function pruneSubscribeTemplates(): void
    {
        if (!Schema::hasTable('v2_subscribe_templates')) {
            return;
        }

        // Only the mihomo/Clash profile survives the kernel switch.
        DB::table('v2_subscribe_templates')
            ->whereIn('name', ['singbox', 'sing-box', 'stash', 'surge', 'surfboard', 'loon', 'quantumultx', 'shadowrocket', 'general'])
            ->delete();
    }
};
