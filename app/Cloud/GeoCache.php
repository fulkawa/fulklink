<?php
/**
 * 【开源版专用】IP 缓存桩。
 *
 * 商业版的缓存文件由云端快照灌入；开源版没有快照，只有「查不到」。
 * 返回结构与商业版保持一致，调用方（LinkStats）不用改。
 */
declare(strict_types=1);

namespace App\Cloud;

final class GeoCache
{
    public static function resolve(string $ip): array
    {
        return [
            'ip'       => $ip,
            'country'  => '',
            'province' => '',
            'city'     => '',
            'isp'      => '',
            'source'   => 'unknown',
        ];
    }

    public static function fromLocal(string $ip): ?array
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
