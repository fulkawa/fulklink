<?php
/**
 * 客户自有域名。
 *
 * 这里只管三件事：登记域名、验证归属、按域名发短链。
 * 域名解析和证书是客户自己的事——我们不碰他的 DNS，也不替他签证书。
 *
 * 验证用 TXT：比 CNAME 稳，不会和客户已有的 A 记录打架。
 * 记录名 _fulklink.短链域名，值 fulklink-verify=随机串。
 *
 * 能不能访问靠客户在自己的 nginx/宝塔里把这个域名指到本站，
 * 这一条写在页面上，不写清楚客户会以为是程序没生效。
 */
declare(strict_types=1);

namespace App;

use App\Cloud\Gate;

final class Domains
{
    public const TXT_PREFIX = '_fulklink.';
    public const TXT_VALUE = 'fulklink-verify=';

    public static function listFor(int $ownerId): array
    {
        $rows = Db::all(
            'SELECT * FROM `user_domains` WHERE `user_id` = ? ORDER BY `id` ASC',
            [$ownerId]
        );
        foreach ($rows as &$row) {
            $row['links'] = (int) Db::val(
                'SELECT COUNT(*) FROM `links` WHERE `user_id` = ? AND `domain` = ?',
                [$ownerId, (string) $row['host']],
                0
            );
        }
        unset($row);
        return $rows;
    }

    public static function find(int $ownerId, int $id): ?array
    {
        return Db::one('SELECT * FROM `user_domains` WHERE `id` = ? AND `user_id` = ? LIMIT 1', [$id, $ownerId]);
    }

    /** @return array{ok:bool,message:string,id:int} */
    public static function add(int $ownerId, string $input): array
    {
        if (!Gate::allows('domains.custom')) {
            return ['ok' => false, 'message' => Gate::reason('domains.custom'), 'id' => 0];
        }

        $host = self::normalize($input);
        if ($host === '') {
            return ['ok' => false, 'message' => '域名要写成 link.example.com 这种形式，不带 http:// 和路径。', 'id' => 0];
        }
        if (($problem = self::hostProblem($host)) !== '') {
            return ['ok' => false, 'message' => $problem, 'id' => 0];
        }

        $limit = Workspace::limit('domains');
        if ($limit > 0) {
            $used = (int) Db::val('SELECT COUNT(*) FROM `user_domains` WHERE `user_id` = ?', [$ownerId], 0);
            if ($used >= $limit) {
                return [
                    'ok'      => false,
                    'message' => '域名数到上限了（' . $limit . ' 个）。删掉一个，或升级版本。',
                    'id'      => 0,
                ];
            }
        }

        $busy = Db::one(
            'SELECT `id`,`user_id`,`verified`,`created_at` FROM `user_domains` WHERE `host` = ? LIMIT 1',
            [$host]
        );
        if ($busy !== null) {
            if ((int) $busy['verified'] === 1) {
                return [
                    'ok'      => false,
                    'message' => (int) $busy['user_id'] === $ownerId
                        ? '这个域名已经加过了，直接去验证。'
                        : '这个域名已经被别的账号绑定。如果是你的，先让对方解绑。',
                    'id'      => 0,
                ];
            }
            if ((int) $busy['user_id'] === $ownerId) {
                return ['ok' => false, 'message' => '这个域名已经加过了，直接去验证。', 'id' => 0];
            }

            /*
             * uk_host 让一个域名只能有一条记录。早先只要有人抢先登记（哪怕从未
             * 验证）就永久占位，别人再也加不了 —— 无需 DNS 归属即可DoS。
             * 未验证且超过 24 小时没动静的占位允许回收，不做 schema 变更。
             */
            $created = strtotime((string) ($busy['created_at'] ?? ''));
            if ($created !== false && time() - $created < 86400) {
                return [
                    'ok'      => false,
                    'message' => '这个域名有账号正在验证中，请稍后再试；如果是你的域名，请联系客服处理。',
                    'id'      => 0,
                ];
            }
            Db::exec('DELETE FROM `user_domains` WHERE `id` = ? AND `verified` = 0', [(int) $busy['id']]);
            Db::log('domain.reclaim', '回收过期的未验证域名占位 ' . $host, [
                'previous_owner' => (int) $busy['user_id'],
            ], 'warn');
        }

        $token = bin2hex(random_bytes(16));
        try {
            $id = Db::insert('user_domains', [
                'user_id'      => $ownerId,
                'host'         => $host,
                'verify_token' => $token,
                'verified'     => 0,
                'created_at'   => now(),
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return ['ok' => false, 'message' => '这个域名刚刚被绑走了，换一个。', 'id' => 0];
            }
            throw $e;
        }

        Workspace::audit($ownerId, 'domain.add', 0, $host);
        Db::log('domain.add', '登记域名 ' . $host, ['owner' => $ownerId]);
        return ['ok' => true, 'message' => '', 'id' => $id];
    }

    /**
     * 查一次 DNS。成功就置 verified=1。
     *
     * @return array{ok:bool,message:string,records:array}
     */
    public static function verify(int $ownerId, int $id): array
    {
        $row = self::find($ownerId, $id);
        if ($row === null) {
            return ['ok' => false, 'message' => '找不到这个域名。', 'records' => []];
        }
        $host = (string) $row['host'];
        $token = (string) $row['verify_token'];
        if ($token === '') {
            // 老数据（V5 之前登记的）没有 token，补一个
            $token = bin2hex(random_bytes(16));
            Db::exec('UPDATE `user_domains` SET `verify_token` = ? WHERE `id` = ?', [$token, $id]);
        }

        $name = self::TXT_PREFIX . $host;
        $values = self::txtRecords($name);
        $found = false;
        foreach ($values as $v) {
            if (strpos($v, self::TXT_VALUE . $token) !== false) {
                $found = true;
                break;
            }
        }

        Db::exec('UPDATE `user_domains` SET `last_check_at` = ? WHERE `id` = ?', [now(), $id]);

        if (!$found) {
            return [
                'ok'      => false,
                'message' => '没查到 TXT 记录。DNS 生效可能要几分钟到几小时，稍后再点一次。',
                'records' => $values,
            ];
        }

        Db::exec(
            'UPDATE `user_domains` SET `verified` = 1, `verified_at` = ? WHERE `id` = ?',
            [now(), $id]
        );
        Workspace::audit($ownerId, 'domain.verify', 0, $host);
        Db::log('domain.verify', '域名验证通过 ' . $host, ['owner' => $ownerId]);

        return ['ok' => true, 'message' => '验证通过。现在可以在建链接时选这个域名了。', 'records' => $values];
    }

    /** @return array{ok:bool,message:string} */
    public static function remove(int $ownerId, int $id): array
    {
        $row = self::find($ownerId, $id);
        if ($row === null) {
            return ['ok' => false, 'message' => '找不到这个域名。'];
        }
        $host = (string) $row['host'];
        $used = (int) Db::val(
            'SELECT COUNT(*) FROM `links` WHERE `user_id` = ? AND `domain` = ?',
            [$ownerId, $host],
            0
        );
        if ($used > 0) {
            return [
                'ok'      => false,
                'message' => '还有 ' . $used . ' 条链接在用这个域名。先把它改成主域名或别的域名，再删。',
            ];
        }
        Db::exec('DELETE FROM `user_domains` WHERE `id` = ? AND `user_id` = ?', [$id, $ownerId]);
        Workspace::audit($ownerId, 'domain.remove', 0, $host);
        return ['ok' => true, 'message' => '已删除 ' . $host . '。'];
    }

    /** 这个 host 是不是某个账号验证过的域名。跳转时判断根路径短链用 */
    public static function verifiedHost(string $host): ?array
    {
        $host = self::normalize($host);
        if ($host === '') {
            return null;
        }
        return Db::one(
            'SELECT * FROM `user_domains` WHERE `host` = ? AND `verified` = 1 LIMIT 1',
            [$host]
        );
    }

    public static function txName(string $host): string
    {
        return self::TXT_PREFIX . $host;
    }

    public static function txValue(array $row): string
    {
        return self::TXT_VALUE . (string) ($row['verify_token'] ?? '');
    }

    /** 客户接入要做的两件事，页面上原样展示 */
    public static function accessHint(string $host, string $siteUrl): array
    {
        $ip = '';
        $siteHost = (string) parse_url($siteUrl, PHP_URL_HOST);
        if ($siteHost !== '') {
            $resolved = @gethostbyname($siteHost);
            $ip = filter_var($resolved, FILTER_VALIDATE_IP) ? $resolved : '';
        }
        return [
            'a' => ['host' => $host, 'value' => $ip !== '' ? $ip : '（本站服务器 IP）'],
            'cname' => ['host' => $host, 'value' => $siteHost],
        ];
    }

    // ---------------- 内部 ----------------

    public static function normalize(string $input): string
    {
        $host = strtolower(trim($input));
        $host = (string) preg_replace('#^[a-z]+://#i', '', $host);
        $host = explode('/', $host)[0];
        $host = explode('?', $host)[0];
        $host = rtrim($host, '.');
        return $host;
    }

    private static function hostProblem(string $host): string
    {
        if (mb_strlen($host) > 190) {
            return '域名太长了。';
        }
        if (strpos($host, '.') === false) {
            return '域名要带后缀，比如 link.example.com。';
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $host)) {
            return '域名格式不对。';
        }
        if (strpos($host, '*.') !== false || strpos($host, ' ') !== false) {
            return '不支持通配符，写具体的域名。';
        }

        $selfHost = strtolower((string) parse_url((string) Config::get('app.url', ''), PHP_URL_HOST));
        if ($selfHost !== '' && $host === $selfHost) {
            return '这是本站正在用的域名，不用再加。直接建链接就是短链。';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return '请填域名，不要填 IP。';
        }
        return '';
    }

    private static function txtRecords(string $name): array
    {
        if (!function_exists('dns_get_record')) {
            return [];
        }
        $records = @dns_get_record($name, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }
        $out = [];
        foreach ($records as $r) {
            if (isset($r['txt']) && $r['txt'] !== '') {
                $out[] = (string) $r['txt'];
                continue;
            }
            if (isset($r['entries']) && is_array($r['entries'])) {
                $out[] = implode('', array_map('strval', $r['entries']));
            }
        }
        return $out;
    }
}
