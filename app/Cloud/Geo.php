<?php
/**
 * 【开源版专用】IP 归属地桩。
 *
 * 商业版的 IP 库放在云端，客户端只拿查询结果和缓存差分；开源版没有云端，
 * 一律返回「未知」。统计页的地区一栏会显示空，不影响其他数字。
 */
declare(strict_types=1);

namespace App\Cloud;

final class Geo
{
    public static function ipToLong(string $ip): ?int
    {
        $long = ip2long($ip);
        return $long === false ? null : $long;
    }

    public static function longToIp(int $long): string
    {
        return long2ip($long);
    }

    public static function lookup(string $ip): ?array
    {
        return null;
    }

    public static function recordUnknown(string $ip, int $limit = 200): void
    {
        // 不记录待补齐样本：开源版没有运营后台去补
    }

    public static function resolveUnknown(int $id, string $province, string $city, string $isp = '', string $country = '中国'): bool
    {
        return false;
    }

    public static function ignoreUnknown(int $id): void
    {
    }

    public static function import(array $rows, string $src = 'import'): int
    {
        return 0;
    }

    public static function insertBlock(int $start, int $end, string $country, string $province, string $city, string $isp, string $src = 'manual'): int
    {
        return 0;
    }

    public static function stats(): array
    {
        return ['blocks' => 0, 'unknowns' => 0, 'version' => ''];
    }

    public static function unknowns(int $page = 1, int $per = 20): array
    {
        return [];
    }
}
