<?php
/**
 * 【开源版专用】风险域名缓存桩。
 *
 * 风险库（钓鱼、赌博、短链滥用的域名）是商业版的云端资产，
 * 开源版拿不到，因此永不拦截。返回 null 与商业版「未命中」同义。
 */
declare(strict_types=1);

namespace App\Cloud;

final class BlockCache
{
    public static function hit(string $host): ?array
    {
        return null;
    }

    public static function sync(bool $force = false): bool
    {
        return false;
    }

    public static function version(): string
    {
        return '';
    }

    public static function count(): int
    {
        return 0;
    }
}
