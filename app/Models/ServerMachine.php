<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * App\Models\ServerMachine
 *
 * @property int $id
 * @property string $name 机器名称
 * @property string $token 认证 Token
 * @property string|null $notes 备注
 * @property bool $is_active 是否启用
 * @property int|null $last_seen_at 最后心跳时间
 * @property array|null $load_status 负载状态
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Server> $servers 关联的节点
 */
class ServerMachine extends Model
{
    /**
     * The only proxy kernel this panel drives. Agents built for the retired
     * Xray / sing-box generations still report their old names; those are
     * folded into the canonical mihomo value so stored state stays uniform.
     */
    public const KERNEL_MIHOMO = 'mihomo';

    /**
     * Legacy kernel names reported by pre-mihomo agents.
     */
    public const KERNEL_ALIASES = [
        'xray' => self::KERNEL_MIHOMO,
        'singbox' => self::KERNEL_MIHOMO,
        'sing-box' => self::KERNEL_MIHOMO,
        'sing_box' => self::KERNEL_MIHOMO,
        'clash' => self::KERNEL_MIHOMO,
        'clashmeta' => self::KERNEL_MIHOMO,
        'clash-meta' => self::KERNEL_MIHOMO,
        'clash.meta' => self::KERNEL_MIHOMO,
        'meta' => self::KERNEL_MIHOMO,
    ];

    public static function normalizeKernelType(?string $type): ?string
    {
        if ($type === null || trim($type) === '') {
            return null;
        }

        $normalized = strtolower(trim($type));

        return self::KERNEL_ALIASES[$normalized] ?? $normalized;
    }

    public static function isValidKernelType(?string $type): bool
    {
        return self::normalizeKernelType($type) === self::KERNEL_MIHOMO;
    }

    protected $table = 'v2_server_machine';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'integer',
        'load_status' => 'array',
        'capabilities' => 'array',
		'update_requested_at' => 'integer',
		'update_started_at' => 'integer',
		'update_completed_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    protected $hidden = ['token'];

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(
            Server::class,
            'v2_server_machine_binding',
            'machine_id',
            'server_id'
        )->withPivot([
            'state',
            'desired_config_version',
            'applied_config_version',
            'last_error',
            'last_seen_at',
        ])->withTimestamps();
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(ServerMachineBinding::class, 'machine_id');
    }

    public function loadHistory(): HasMany
    {
        return $this->hasMany(ServerMachineLoadHistory::class, 'machine_id');
    }

    /**
     * 生成新的随机 Token
     */
    public static function generateToken(): string
    {
        return Str::random(32);
    }

    /**
     * 更新最后心跳时间
     */
    public function updateHeartbeat(): bool
    {
        return $this->forceFill(['last_seen_at' => now()->timestamp])->save();
    }
}
