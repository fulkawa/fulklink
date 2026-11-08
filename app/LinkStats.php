<?php
/**
 * 跳转记录与统计。
 *
 * 记录走「先自增计数、再插明细」两段：计数在 links 表上，
 * 列表页和配额只看一个整数，不用扫 clicks；明细只在统计页翻。
 *
 * 去重规则：同一链接、同一 IP、10 秒内只算一次。刷新页面不该把数刷上去，
 * 但同一个人隔几分钟再点一次是真实点击，要算。
 *
 * 爬虫照记但不计数（device=bot）：客户看的是人，不是 Googlebot。
 */
declare(strict_types=1);

namespace App;

use App\Cloud\Geo;
use App\Cloud\GeoCache;

final class LinkStats
{
    private const DEDUP_SECONDS = 10;

    /**
     * 记一次跳转。跳转主流程不能因为统计失败而失败。
     *
     * @return bool 是否计入有效点击
     */
    public static function record(array $link, Request $req, string $source = ''): bool
    {
        $linkId = (int) $link['id'];
        $ip = $req->ip();
        $ua = $req->ua();
        $parsed = Ua::parse($ua);
        $ipHash = self::ipHash($ip);

        try {
            // 爬虫和 10 秒内的重复刷新都不计数，但爬虫还是留一条，方便排查
            $counted = !$parsed['bot'];
            if ($counted) {
                $dup = Db::one(
                    'SELECT `id` FROM `clicks` WHERE `link_id` = ? AND `ip_hash` = ? AND `ts` >= ? LIMIT 1',
                    [$linkId, $ipHash, date('Y-m-d H:i:s', time() - self::DEDUP_SECONDS)]
                );
                if ($dup !== null) {
                    $counted = false;
                }
            }

            $region = self::regionOf($ip);

            Db::insert('clicks', [
                'link_id' => $linkId,
                'ts'      => now(),
                'day'     => date('Y-m-d'),
                'ip_hash' => $ipHash,
                'ua'      => mb_substr($ua, 0, 512),
                'referer' => mb_substr(self::refererHost($req->header('Referer')), 0, 512),
                'source'  => $source !== '' ? $source : Ua::sourceOf($req->header('Referer')),
                'device'  => $parsed['device'],
                'os'      => $parsed['os'],
                'browser' => $parsed['browser'],
                'region'  => mb_substr($region, 0, 64),
            ]);

            if ($counted) {
                Db::exec('UPDATE `links` SET `click_count` = `click_count` + 1 WHERE `id` = ?', [$linkId]);
            }
            return $counted;
        } catch (\Throwable $e) {
            Db::log('stats.record', '记录点击失败：' . $e->getMessage(), ['link_id' => $linkId], 'warn');
            return false;
        }
    }

    // ---------------- 单链接 ----------------

    public static function summary(int $linkId): array
    {
        $row = Db::one(
            "SELECT COUNT(*) AS total,
                    COUNT(DISTINCT `ip_hash`) AS uniq,
                    SUM(`day` = CURDATE()) AS today,
                    SUM(`ts` >= ?) AS last7,
                    MAX(`ts`) AS last_at
               FROM `clicks` WHERE `link_id` = ? AND `device` <> 'bot'",
            [date('Y-m-d H:i:s', strtotime('-7 days')), $linkId]
        ) ?? [];

        return [
            'total'   => (int) ($row['total'] ?? 0),
            'uniq'    => (int) ($row['uniq'] ?? 0),
            'today'   => (int) ($row['today'] ?? 0),
            'last7'   => (int) ($row['last7'] ?? 0),
            'last_at' => (string) ($row['last_at'] ?? ''),
        ];
    }

    /** 最近 N 天，缺的天补 0，前端直接画柱子 */
    public static function byDay(int $linkId, int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $rows = Db::all(
            "SELECT `day`, COUNT(*) AS n, COUNT(DISTINCT `ip_hash`) AS u
               FROM `clicks`
              WHERE `link_id` = ? AND `device` <> 'bot' AND `day` >= ?
              GROUP BY `day`",
            [$linkId, $from]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['day']] = ['n' => (int) $r['n'], 'u' => (int) $r['u']];
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $out[] = [
                'day'   => $day,
                'label' => date('m-d', strtotime($day)),
                'n'     => $map[$day]['n'] ?? 0,
                'u'     => $map[$day]['u'] ?? 0,
            ];
        }
        return $out;
    }

    public static function bySource(int $linkId, int $limit = 10): array
    {
        $rows = Db::all(
            "SELECT `source`, COUNT(*) AS n FROM `clicks`
              WHERE `link_id` = ? AND `device` <> 'bot'
              GROUP BY `source` ORDER BY n DESC LIMIT " . max(1, min(50, $limit)),
            [$linkId]
        );
        $out = [];
        foreach ($rows as $r) {
            $code = (string) $r['source'] !== '' ? (string) $r['source'] : 'direct';
            $out[] = ['code' => $code, 'label' => Ua::sourceLabel($code), 'n' => (int) $r['n']];
        }
        return $out;
    }

    public static function byDevice(int $linkId): array
    {
        $rows = Db::all(
            "SELECT `device`, COUNT(*) AS n FROM `clicks`
              WHERE `link_id` = ? AND `device` <> 'bot' GROUP BY `device` ORDER BY n DESC",
            [$linkId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['code' => (string) $r['device'], 'label' => Ua::deviceLabel((string) $r['device']), 'n' => (int) $r['n']];
        }
        return $out;
    }

    public static function byBrowser(int $linkId, int $limit = 8): array
    {
        return self::groupColumn($linkId, 'browser', $limit);
    }

    public static function byOs(int $linkId, int $limit = 8): array
    {
        return self::groupColumn($linkId, 'os', $limit);
    }

    public static function byRegion(int $linkId, int $limit = 10): array
    {
        return self::groupColumn($linkId, 'region', $limit, '未知');
    }

    private static function groupColumn(int $linkId, string $column, int $limit = 8, string $emptyLabel = '其它'): array
    {
        if (!in_array($column, ['browser', 'os', 'region'], true)) {
            return [];
        }
        $rows = Db::all(
            'SELECT `' . $column . '` AS v, COUNT(*) AS n FROM `clicks`
              WHERE `link_id` = ? AND `device` <> \'bot\'
              GROUP BY `' . $column . '` ORDER BY n DESC LIMIT ' . max(1, min(50, $limit)),
            [$linkId]
        );
        $out = [];
        foreach ($rows as $r) {
            $v = (string) $r['v'];
            $out[] = ['code' => $v, 'label' => $v === '' ? $emptyLabel : $v, 'n' => (int) $r['n']];
        }
        return $out;
    }

    /** 逐次记录。只有开通 click.log 才给看 */
    public static function recent(int $linkId, int $page = 1, int $per = 30): array
    {
        $per = max(1, min(100, $per));
        $total = (int) Db::val("SELECT COUNT(*) FROM `clicks` WHERE `link_id` = ? AND `device` <> 'bot'", [$linkId], 0);
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $per;

        $rows = Db::all(
            "SELECT `ts`, `source`, `referer`, `device`, `os`, `browser`, `region`
               FROM `clicks` WHERE `link_id` = ? AND `device` <> 'bot'
              ORDER BY `id` DESC LIMIT " . $per . ' OFFSET ' . $offset,
            [$linkId]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    // ---------------- 账号级 ----------------

    /** 当前工作区整体情况，列表页顶部四个数字用 */
    public static function overview(int $ownerId): array
    {
        $linkStats = Db::one(
            "SELECT COUNT(*) AS links,
                    SUM(`status` = 'active') AS active,
                    COALESCE(SUM(`click_count`), 0) AS clicks
               FROM `links` WHERE `user_id` = ?",
            [$ownerId]
        ) ?? [];

        $today = (int) Db::val(
            "SELECT COUNT(*) FROM `clicks` c JOIN `links` l ON l.`id` = c.`link_id`
              WHERE l.`user_id` = ? AND c.`day` = CURDATE() AND c.`device` <> 'bot'",
            [$ownerId],
            0
        );
        $last7 = (int) Db::val(
            "SELECT COUNT(*) FROM `clicks` c JOIN `links` l ON l.`id` = c.`link_id`
              WHERE l.`user_id` = ? AND c.`ts` >= ? AND c.`device` <> 'bot'",
            [$ownerId, date('Y-m-d H:i:s', strtotime('-7 days'))],
            0
        );

        return [
            'links'  => (int) ($linkStats['links'] ?? 0),
            'active' => (int) ($linkStats['active'] ?? 0),
            'clicks' => (int) ($linkStats['clicks'] ?? 0),
            'today'  => $today,
            'last7'  => $last7,
        ];
    }

    /** 账号级近 N 天曲线，首页用 */
    public static function ownerByDay(int $ownerId, int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $rows = Db::all(
            "SELECT c.`day`, COUNT(*) AS n
               FROM `clicks` c JOIN `links` l ON l.`id` = c.`link_id`
              WHERE l.`user_id` = ? AND c.`device` <> 'bot' AND c.`day` >= ?
              GROUP BY c.`day`",
            [$ownerId, $from]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['day']] = (int) $r['n'];
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $out[] = ['day' => $day, 'label' => date('m-d', strtotime($day)), 'n' => $map[$day] ?? 0];
        }
        return $out;
    }

    public static function topLinks(int $ownerId, int $limit = 5): array
    {
        return Db::all(
            'SELECT `id`, `slug`, `domain`, `title`, `click_count` FROM `links`
              WHERE `user_id` = ? ORDER BY `click_count` DESC, `id` DESC LIMIT ' . max(1, min(50, $limit)),
            [$ownerId]
        );
    }

    /** 导出。按链接或整个工作区，日期可选 */
    public static function exportCsv(int $ownerId, int $linkId = 0, string $from = '', string $to = ''): string
    {
        $where = ['l.`user_id` = ?'];
        $params = [$ownerId];
        if ($linkId > 0) {
            $where[] = 'c.`link_id` = ?';
            $params[] = $linkId;
        }
        if ($from !== '') {
            $where[] = 'c.`day` >= ?';
            $params[] = $from;
        }
        if ($to !== '') {
            $where[] = 'c.`day` <= ?';
            $params[] = $to;
        }

        $rows = Db::all(
            'SELECT c.`ts`, l.`slug`, l.`domain`, l.`title`, c.`source`, c.`device`, c.`os`, c.`browser`, c.`region`
               FROM `clicks` c JOIN `links` l ON l.`id` = c.`link_id`
              WHERE ' . implode(' AND ', $where) . ' AND c.`device` <> \'bot\'
              ORDER BY c.`id` DESC LIMIT 50000',
            $params
        );

        $fh = fopen('php://temp', 'r+');
        // BOM 让 Excel 认出 UTF-8，不加会看到一堆乱码
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['时间', '后缀', '域名', '标题', '来源', '设备', '系统', '浏览器', '地区']);
        foreach ($rows as $r) {
            fputcsv($fh, [
                (string) $r['ts'],
                (string) $r['slug'],
                (string) $r['domain'],
                (string) $r['title'],
                Ua::sourceLabel((string) ($r['source'] !== '' ? $r['source'] : 'direct')),
                Ua::deviceLabel((string) $r['device']),
                (string) $r['os'],
                (string) $r['browser'],
                (string) $r['region'],
            ]);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    // ---------------- 内部 ----------------

    /** IP 摘要每天换盐：当天能去重，跨天拼不出同一个人的轨迹 */
    public static function ipHash(string $ip): string
    {
        $salt = (string) Config::get('app.key', '');
        return substr(hash('sha256', $ip . '|' . date('Ymd') . '|' . $salt), 0, 32);
    }

    private static function refererHost(string $referer): string
    {
        if ($referer === '') {
            return '';
        }
        $host = (string) parse_url($referer, PHP_URL_HOST);
        return strtolower($host);
    }

    /**
     * 地区只查本地库。跳转是热路径，不能为了一个省份去等云端 4 秒超时。
     * 本地库没覆盖到的 IP 先留空，运营导库后新点击就有值了。
     */
    private static function regionOf(string $ip): string
    {
        try {
            $hit = GeoCache::fromLocal($ip);
        } catch (\Throwable $e) {
            return '';
        }
        if ($hit === null) {
            // 千分之二抽样记样本，避免热门链接把未知 IP 表写爆
            if (random_int(1, 500) === 1) {
                try {
                    Geo::recordUnknown($ip);
                } catch (\Throwable $e) {
                }
            }
            return '';
        }
        $province = (string) ($hit['province'] ?? '');
        $city = (string) ($hit['city'] ?? '');
        if ($city !== '' && $city !== $province) {
            return $province . ' ' . $city;
        }
        return $province !== '' ? $province : (string) ($hit['country'] ?? '');
    }
}
