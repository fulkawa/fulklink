<?php
/**
 * 【开源版专用】授权桩。
 *
 * 开源版没有授权这个概念：能力固定锁在免费档，不连云端、不读授权码、
 * 不接受任何形式的升级。所有调用点（Gate / Workspace / 后台）接口签名
 * 与商业版完全一致，源码里不会出现一个「加密授权」相关的类。
 *
 * 这个文件只在构建开源包时替换进产物，主仓库不受影响。
 */
declare(strict_types=1);

namespace App\Cloud;

use App\Config;
use App\Plans;

final class Entitlement
{
    private static ?array $memo = null;

    public static function current(bool $force = false): array
    {
        if (self::$memo !== null && !$force) {
            return self::$memo;
        }

        self::$memo = [
            'valid'      => true,
            'state'      => 'open',
            'plan'       => Plans::FREE,
            'features'   => Features::forPlan(Plans::FREE),
            'quota'      => Features::quota(Plans::FREE),
            'expires_at' => '',
            'fetched_at' => time(),
            'grace_left' => 0,
            'message'    => '',
        ];

        return self::$memo;
    }

    public static function fetch(): ?array
    {
        return self::current(true);
    }

    public static function has(string $feature): bool
    {
        return in_array($feature, self::current()['features'], true);
    }

    public static function plan(): string
    {
        return Plans::FREE;
    }

    /** 0 表示不限 */
    public static function quota(string $key): int
    {
        return (int) (self::current()['quota'][$key] ?? 0);
    }

    public static function isDegraded(): bool
    {
        return false;
    }

    public static function stateLabel(): string
    {
        return '开源版';
    }

    public static function licenseKey(): string
    {
        return '';
    }

    public static function saveLicenseKey(string $key): void
    {
        // 开源版不接受授权码，留空
    }

    public static function host(): string
    {
        $url = (string) Config::get('app.url', '');
        return strtolower(trim((string) parse_url($url, PHP_URL_HOST)));
    }

    public static function flush(): void
    {
        self::$memo = null;
    }
}
