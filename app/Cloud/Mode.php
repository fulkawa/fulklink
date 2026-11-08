<?php
/**
 * 运行角色。
 *
 *   cloud  —— 官网自己。签发授权、下发资源、收心跳，同时也是展示站
 *   client —— 客户买走的站点。连回云端拿能力包和资源，连不上就降级
 *
 * 同一套代码两种角色，靠 .env 的 APP_MODE 切换。这样打包时不用改代码，
 * 也不用维护两份分支 —— 两份分支迟早会漂移。
 */
declare(strict_types=1);

namespace App\Cloud;

use App\Config;

final class Mode
{
    public const CLOUD = 'cloud';
    public const CLIENT = 'client';

    public static function current(): string
    {
        $mode = strtolower(trim((string) Config::get('app.mode', self::CLOUD)));
        return $mode === self::CLIENT ? self::CLIENT : self::CLOUD;
    }

    public static function isCloud(): bool
    {
        return self::current() === self::CLOUD;
    }

    public static function isClient(): bool
    {
        return self::current() === self::CLIENT;
    }

    /** 云端地址。client 模式下取 CLOUD_URL，cloud 模式下取本站地址 */
    public static function endpoint(): string
    {
        if (self::isCloud()) {
            $own = rtrim((string) Config::get('app.url', ''), '/');
            return $own !== '' ? $own : 'https://lp.fulkteam.cn';
        }
        return rtrim((string) Config::get('cloud.url', ''), '/');
    }

    public static function timeout(): int
    {
        return max(1, (int) Config::get('cloud.timeout', 4));
    }

    public static function ttl(): int
    {
        return max(300, (int) Config::get('cloud.ttl', 86400));
    }

    public static function graceHours(): int
    {
        return max(0, (int) Config::get('cloud.grace_hours', 72));
    }
}
