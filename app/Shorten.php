<?php
/**
 * 短链本体。跳转、统计、域名、团队都围着这张表转，规则集中在这里，
 * 控制器只负责收参数和给页面。
 *
 * 两条不能破的底线：
 *   1. 跳转不查授权（Gate）。授权过期、云端连不上，客户已经发出去的链接必须还能打开。
 *   2. 创建与编辑不因为降级而停摆。降级只影响额度和高级功能，不影响「还能用」。
 */
declare(strict_types=1);

namespace App;

use App\Cloud\BlockCache;
use App\Cloud\Gate;

final class Shorten
{
    public const SLUG_MIN = 3;
    public const SLUG_MAX = 48;

    /** 这些后缀留给站内功能，短链不能占 */
    private const RESERVED = [
        'r', 's', 'go', 'api', 'admin', 'account', 'login', 'logout', 'register',
        'buy', 'pay', 'license', 'pricing', 'docs', 'faq', 'changelog', 'legal',
        'health', 'assets', 'install', 'captcha', 'robots', 'favicon', 'sitemap',
        'index', 'public', 'static', 'mail', 'smtp', 'ftp', 'www', 'cdn',
    ];

    // ---------------- 校验 ----------------

    public static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        return trim($slug, '-_');
    }

    /** 空串表示没问题 */
    public static function slugProblem(string $slug): string
    {
        if ($slug === '') {
            return '后缀不能为空。';
        }
        if (mb_strlen($slug) < self::SLUG_MIN) {
            return '后缀至少 ' . self::SLUG_MIN . ' 个字符。';
        }
        if (mb_strlen($slug) > self::SLUG_MAX) {
            return '后缀最长 ' . self::SLUG_MAX . ' 个字符。';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            return '后缀只能用字母、数字、短横线、下划线，且要以字母或数字开头。';
        }
        if (in_array($slug, self::RESERVED, true)) {
            return '「' . $slug . '」是站内保留字，换一个。';
        }
        if (preg_match('/^\d+$/', $slug)) {
            return '后缀不能全是数字，容易和编号混淆。';
        }
        return '';
    }

    /**
     * 目标地址校验。返回空串表示通过。
     * 没写协议就补 https://，其它协议一律拒绝（javascript: 是最常见的一种钓法）。
     */
    public static function targetProblem(string $url): string
    {
        if ($url === '') {
            return '目标地址不能为空。';
        }
        if (mb_strlen($url) > 2048) {
            return '目标地址太长了，2048 字符以内。';
        }

        $lower = strtolower($url);
        foreach (['javascript:', 'data:', 'vbscript:', 'file:', 'blob:'] as $bad) {
            if (strpos($lower, $bad) === 0) {
                return '不支持这种地址。只接受 http 和 https。';
            }
        }
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (!preg_match('#^https?://#i', $url)) {
            if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
                return '只接受 http 和 https 地址。';
            }
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '这个地址看不出域名，检查一下。';
        }
        $host = strtolower((string) $parts['host']);
        if (!preg_match('/^[a-z0-9.\-]+$/', $host) && strpos($host, '[') === false) {
            return '域名里有不合法字符。';
        }
        if (strpos($host, '.') === false && $host !== 'localhost' && strpos($host, '[') === false
            && !filter_var($host, FILTER_VALIDATE_IP)) {
            return '域名要写成 example.com 这种形式。';
        }

        // 企业版的白名单。没配就不限制，配了就只放行列表里的
        if (Gate::allows('target.whitelist')) {
            $allow = self::allowedHosts();
            if ($allow !== [] && !self::hostAllowed($host, $allow)) {
                return '目标域名不在白名单里：' . $host . '。到后台加进去再建。';
            }
        }

        // 指向自己的短链会形成循环，运行时会一直重定向
        $selfHost = strtolower((string) parse_url((string) Config::get('app.url', ''), PHP_URL_HOST));
        if ($selfHost !== '' && $host === $selfHost && preg_match('#^/(r|s)/#', (string) ($parts['path'] ?? ''))) {
            return '不能指向本站的短链，会绕成死循环。';
        }

        return '';
    }

    public static function allowedHosts(): array
    {
        $raw = str_replace(['，', ' ', "\n", "\r", "\t"], [',', '', '', '', ''], (string) Settings::get('link.allowed_hosts', ''));
        $out = [];
        foreach (explode(',', $raw) as $one) {
            $one = strtolower(trim($one));
            if ($one !== '') {
                $out[] = $one;
            }
        }
        return array_values(array_unique($out));
    }

    /** 支持 *.example.com 这种通配 */
    private static function hostAllowed(string $host, array $allow): bool
    {
        foreach ($allow as $rule) {
            if ($rule === $host) {
                return true;
            }
            if (strpos($rule, '*.') === 0) {
                $suffix = substr($rule, 1);           // .example.com
                if (substr($host, -strlen($suffix)) === $suffix) {
                    return true;
                }
            }
        }
        return false;
    }

    // ---------------- 生成与查找 ----------------

    public static function generateSlug(int $len = 6): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';   // 去掉 l/o/0/1，念出来不会混
        $max = strlen($alphabet) - 1;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $slug = '';
            for ($i = 0; $i < $len; $i++) {
                $slug .= $alphabet[random_int(0, $max)];
            }
            if (!self::slugTaken($slug, '') && self::slugProblem($slug) === '') {
                return $slug;
            }
            $len++;   // 撞了就加一位，比原地重试收敛快
        }
        return strtolower(bin2hex(random_bytes(6)));
    }

    public static function slugTaken(string $slug, string $domain, int $exceptId = 0): bool
    {
        $row = Db::one(
            'SELECT `id` FROM `links` WHERE `domain` = ? AND `slug` = ? AND `id` <> ? LIMIT 1',
            [$domain, $slug, $exceptId]
        );
        return $row !== null;
    }

    public static function find(int $ownerId, int $id): ?array
    {
        return Db::one('SELECT * FROM `links` WHERE `id` = ? AND `user_id` = ? LIMIT 1', [$id, $ownerId]);
    }

    public static function findAny(int $id): ?array
    {
        return Db::one('SELECT * FROM `links` WHERE `id` = ? LIMIT 1', [$id]);
    }

    /** 跳转入口用：域名 + 后缀唯一定位一条 */
    public static function bySlug(string $slug, string $domain): ?array
    {
        return Db::one(
            'SELECT * FROM `links` WHERE `slug` = ? AND `domain` = ? LIMIT 1',
            [self::normalizeSlug($slug), $domain]
        );
    }

    // ---------------- 写 ----------------

    /**
     * 新建。
     *
     * @param array $owner 所有者 user 行（不是成员）
     * @param array $in    slug/target/title/domain/group/note/password/expires_at/max_clicks
     * @return array{ok:bool,message:string,id:int,slug:string}
     */
    public static function create(array $owner, array $in): array
    {
        $ownerId = (int) $owner['id'];

        if (!Workspace::canAdd($ownerId, 'links')) {
            $limit = Workspace::limit('links');
            return ['ok' => false, 'message' => '链接数到上限了（' . $limit . ' 条）。删掉一些，或升级版本。', 'id' => 0, 'slug' => ''];
        }

        $target = trim((string) ($in['target'] ?? ''));
        if (($problem = self::targetProblem($target)) !== '') {
            return ['ok' => false, 'message' => $problem, 'id' => 0, 'slug' => ''];
        }
        $target = self::normalizeTarget($target);

        $domain = self::checkDomain($ownerId, (string) ($in['domain'] ?? ''));
        if ($domain === null) {
            return ['ok' => false, 'message' => '这个域名不在你的账号下，或者还没验证通过。', 'id' => 0, 'slug' => ''];
        }

        $slug = self::normalizeSlug((string) ($in['slug'] ?? ''));
        if ($slug === '') {
            $slug = self::generateSlug();
        } else {
            if (($problem = self::slugProblem($slug)) !== '') {
                return ['ok' => false, 'message' => $problem, 'id' => 0, 'slug' => ''];
            }
            if (self::slugTaken($slug, $domain)) {
                return ['ok' => false, 'message' => '「' . $slug . '」已经被占了，换一个。', 'id' => 0, 'slug' => ''];
            }
        }

        $password = (string) ($in['password'] ?? '');
        if ($password !== '' && !Gate::allows('links.password')) {
            return ['ok' => false, 'message' => Gate::reason('links.password'), 'id' => 0, 'slug' => ''];
        }

        $expires = self::parseExpires((string) ($in['expires_at'] ?? ''));
        if ($expires === false) {
            return ['ok' => false, 'message' => '有效期格式不对，写成 2027-01-31 这样。', 'id' => 0, 'slug' => ''];
        }
        if ($expires !== null && !Gate::allows('links.expire')) {
            return ['ok' => false, 'message' => Gate::reason('links.expire'), 'id' => 0, 'slug' => ''];
        }

        $group = self::normalizeGroup((string) ($in['group'] ?? ''));
        if ($group !== '' && !Gate::allows('links.group')) {
            // 与密码、有效期一样明确拒绝：静默清空会让人以为分组生效了
            return ['ok' => false, 'message' => Gate::reason('links.group'), 'id' => 0, 'slug' => ''];
        }

        $row = [
            'user_id'       => $ownerId,
            'created_by'    => Auth::id(),
            'slug'          => $slug,
            'domain'        => $domain,
            'target_url'    => $target,
            'title'         => mb_substr(trim((string) ($in['title'] ?? '')), 0, 191),
            'group_name'    => $group,
            'note'          => mb_substr(trim((string) ($in['note'] ?? '')), 0, 191),
            'status'        => 'active',
            'password_hash' => $password === '' ? '' : password_hash($password, PASSWORD_DEFAULT),
            'expires_at'    => $expires,
            'max_clicks'    => max(0, (int) ($in['max_clicks'] ?? 0)),
            'click_count'   => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        try {
            $id = Db::insert('links', $row);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return ['ok' => false, 'message' => '这个后缀刚刚被占了，再试一次。', 'id' => 0, 'slug' => ''];
            }
            throw $e;
        }

        Workspace::audit($ownerId, 'link.create', $id, $slug . ' → ' . mb_substr($target, 0, 120));
        Db::log('link.create', '新建短链 ' . $slug, ['id' => $id, 'owner' => $ownerId]);

        return ['ok' => true, 'message' => '', 'id' => $id, 'slug' => $slug];
    }

    /**
     * 编辑。只改传进来的字段，没传的保持原样。
     *
     * @return array{ok:bool,message:string}
     */
    public static function update(int $ownerId, int $id, array $in): array
    {
        $link = self::find($ownerId, $id);
        if ($link === null) {
            return ['ok' => false, 'message' => '找不到这条链接。'];
        }

        $set = ['updated_at' => now()];
        $detail = [];

        if (array_key_exists('target', $in)) {
            $target = trim((string) $in['target']);
            if (($problem = self::targetProblem($target)) !== '') {
                return ['ok' => false, 'message' => $problem];
            }
            $set['target_url'] = self::normalizeTarget($target);
            $detail[] = '目标改为 ' . mb_substr($set['target_url'], 0, 100);
        }

        if (array_key_exists('title', $in)) {
            $set['title'] = mb_substr(trim((string) $in['title']), 0, 191);
        }
        if (array_key_exists('note', $in)) {
            $set['note'] = mb_substr(trim((string) $in['note']), 0, 191);
        }

        if (array_key_exists('group', $in)) {
            $group = self::normalizeGroup((string) $in['group']);
            if ($group !== (string) $link['group_name'] && !Gate::allows('links.group')) {
                return ['ok' => false, 'message' => Gate::reason('links.group')];
            }
            $set['group_name'] = $group;
        }

        if (array_key_exists('domain', $in) || array_key_exists('slug', $in)) {
            $domain = self::checkDomain($ownerId, array_key_exists('domain', $in) ? (string) $in['domain'] : (string) $link['domain']);
            if ($domain === null) {
                return ['ok' => false, 'message' => '这个域名不在你的账号下，或者还没验证通过。'];
            }
            $slug = array_key_exists('slug', $in)
                ? self::normalizeSlug((string) $in['slug'])
                : (string) $link['slug'];
            if ($slug !== (string) $link['slug'] || $domain !== (string) $link['domain']) {
                if (($problem = self::slugProblem($slug)) !== '') {
                    return ['ok' => false, 'message' => $problem];
                }
                if (self::slugTaken($slug, $domain, $id)) {
                    return ['ok' => false, 'message' => '「' . $slug . '」已经被占了。'];
                }
                $set['slug'] = $slug;
                $set['domain'] = $domain;
                $detail[] = '地址改为 ' . ($domain === '' ? '' : $domain . '/') . $slug;
            }
        }

        if (array_key_exists('password', $in)) {
            $password = (string) $in['password'];
            if ($password !== '') {
                if (!Gate::allows('links.password')) {
                    return ['ok' => false, 'message' => Gate::reason('links.password')];
                }
                $set['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $detail[] = '设置了访问密码';
            } elseif (array_key_exists('clear_password', $in) && (string) $in['clear_password'] === '1') {
                $set['password_hash'] = '';
                $detail[] = '取消了访问密码';
            }
        }

        if (array_key_exists('expires_at', $in)) {
            $expires = self::parseExpires((string) $in['expires_at']);
            if ($expires === false) {
                return ['ok' => false, 'message' => '有效期格式不对。'];
            }
            if ($expires !== null && !Gate::allows('links.expire')) {
                return ['ok' => false, 'message' => Gate::reason('links.expire')];
            }
            $set['expires_at'] = $expires;
            $detail[] = '有效期改为 ' . ($expires ?? '长期');
        }

        if (array_key_exists('max_clicks', $in)) {
            $set['max_clicks'] = max(0, (int) $in['max_clicks']);
        }

        if (array_key_exists('status', $in)) {
            $status = (string) $in['status'] === 'disabled' ? 'disabled' : 'active';
            $set['status'] = $status;
            $detail[] = $status === 'disabled' ? '已停用' : '已启用';
        }

        Db::update('links', $set, '`id` = ? AND `user_id` = ?', [$id, $ownerId]);
        Workspace::audit($ownerId, 'link.update', $id, implode('；', $detail));
        return ['ok' => true, 'message' => ''];
    }

    public static function delete(int $ownerId, int $id): bool
    {
        $link = self::find($ownerId, $id);
        if ($link === null) {
            return false;
        }
        Db::exec('DELETE FROM `links` WHERE `id` = ? AND `user_id` = ?', [$id, $ownerId]);
        Workspace::audit($ownerId, 'link.delete', $id, (string) $link['slug']);
        Db::log('link.delete', '删除短链 ' . $link['slug'], ['id' => $id]);
        return true;
    }

    /** @return array{ok:int,skipped:int} */
    public static function bulk(int $ownerId, array $ids, string $action, string $group = ''): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0));
        if ($ids === []) {
            return ['ok' => 0, 'skipped' => 0];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$ownerId], $ids);

        switch ($action) {
            case 'enable':
            case 'disable':
                $status = $action === 'enable' ? 'active' : 'disabled';
                $n = Db::exec("UPDATE `links` SET `status` = ?, `updated_at` = ? WHERE `user_id` = ? AND `id` IN ($in)", array_merge([$status, now()], $params));
                Workspace::audit($ownerId, 'link.bulk.' . $action, 0, count($ids) . ' 条');
                return ['ok' => $n, 'skipped' => count($ids) - $n];

            case 'delete':
                $n = Db::exec("DELETE FROM `links` WHERE `user_id` = ? AND `id` IN ($in)", $params);
                Workspace::audit($ownerId, 'link.bulk.delete', 0, count($ids) . ' 条');
                return ['ok' => $n, 'skipped' => count($ids) - $n];

            case 'group':
                $group = self::normalizeGroup($group);
                $n = Db::exec("UPDATE `links` SET `group_name` = ?, `updated_at` = ? WHERE `user_id` = ? AND `id` IN ($in)", array_merge([$group, now()], $params));
                Workspace::audit($ownerId, 'link.bulk.group', 0, '移到「' . ($group === '' ? '未分组' : $group) . '」' . $n . ' 条');
                return ['ok' => $n, 'skipped' => count($ids) - $n];

            default:
                return ['ok' => 0, 'skipped' => count($ids)];
        }
    }

    // ---------------- 读 ----------------

    /**
     * @return array{rows:array,total:int,page:int,pages:int}
     */
    public static function listFor(int $ownerId, array $f, int $page = 1, int $per = 20): array
    {
        $where = ['`user_id` = ?'];
        $params = [$ownerId];

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $where[] = '(`slug` LIKE ? OR `title` LIKE ? OR `target_url` LIKE ? OR `note` LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $status = (string) ($f['status'] ?? '');
        if ($status === 'active' || $status === 'disabled') {
            $where[] = '`status` = ?';
            $params[] = $status;
        }

        if (array_key_exists('group', $f) && (string) $f['group'] !== '') {
            if ((string) $f['group'] === '__none') {
                $where[] = '`group_name` = ?';
                $params[] = '';
            } else {
                $where[] = '`group_name` = ?';
                $params[] = (string) $f['group'];
            }
        }

        if (array_key_exists('domain', $f) && (string) $f['domain'] !== '') {
            $where[] = '`domain` = ?';
            $params[] = (string) $f['domain'];
        }

        $sqlWhere = implode(' AND ', $where);
        $total = (int) Db::val('SELECT COUNT(*) FROM `links` WHERE ' . $sqlWhere, $params, 0);

        $per = max(1, min(100, $per));
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $per;

        $order = (string) ($f['sort'] ?? '') === 'clicks' ? '`click_count` DESC, `id` DESC' : '`id` DESC';

        $rows = Db::all(
            'SELECT * FROM `links` WHERE ' . $sqlWhere . ' ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . $offset,
            $params
        );
        foreach ($rows as &$row) {
            $row['state'] = self::state($row);
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function groups(int $ownerId): array
    {
        $rows = Db::all(
            "SELECT `group_name`, COUNT(*) AS n FROM `links`
              WHERE `user_id` = ? AND `group_name` <> '' GROUP BY `group_name` ORDER BY `group_name` ASC",
            [$ownerId]
        );
        return array_map(static fn ($r) => ['name' => (string) $r['group_name'], 'n' => (int) $r['n']], $rows);
    }

    public static function countFor(int $ownerId, string $status = ''): int
    {
        if ($status !== '') {
            return (int) Db::val('SELECT COUNT(*) FROM `links` WHERE `user_id` = ? AND `status` = ?', [$ownerId, $status], 0);
        }
        return (int) Db::val('SELECT COUNT(*) FROM `links` WHERE `user_id` = ?', [$ownerId], 0);
    }

    // ---------------- 状态与展示 ----------------

    /** active | disabled | expired | capped */
    public static function state(array $link): string
    {
        if ((string) $link['status'] !== 'active') {
            return 'disabled';
        }
        if (self::isExpired($link)) {
            return 'expired';
        }
        if (self::isCapped($link)) {
            return 'capped';
        }
        return 'active';
    }

    public static function isExpired(array $link): bool
    {
        $exp = $link['expires_at'] ?? null;
        if ($exp === null || $exp === '' || $exp === '0000-00-00 00:00:00') {
            return false;
        }
        $ts = strtotime((string) $exp);
        return $ts !== false && $ts < time();
    }

    public static function isCapped(array $link): bool
    {
        $max = (int) $link['max_clicks'];
        return $max > 0 && (int) $link['click_count'] >= $max;
    }

    public static function stateLabel(string $state): string
    {
        return [
            'active'   => '正常',
            'disabled' => '已停用',
            'expired'  => '已过期',
            'capped'   => '次数已满',
        ][$state] ?? $state;
    }

    /** 站点主域名（不带协议），短链展示用 */
    public static function defaultHost(): string
    {
        $host = (string) parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
        return strtolower($host !== '' ? $host : (new Request())->host());
    }

    public static function shortUrl(array $link): string
    {
        $host = (string) $link['domain'] !== '' ? (string) $link['domain'] : self::defaultHost();
        $host = preg_replace('#^https?://#i', '', $host) ?: self::defaultHost();
        return self::scheme() . '://' . $host . '/r/' . (string) $link['slug'];
    }

    /** 自定义域名下用的短地址（去掉 /r/） */
    public static function rootUrl(array $link): string
    {
        if ((string) $link['domain'] === '') {
            return self::shortUrl($link);
        }
        return self::scheme() . '://' . $link['domain'] . '/' . $link['slug'];
    }

    /** 跟随站点协议。装在 http 内网的客户站不能生成 https 链接，那会打不开 */
    private static function scheme(): string
    {
        $scheme = strtolower((string) parse_url((string) Config::get('app.url', ''), PHP_URL_SCHEME));
        return $scheme === 'http' ? 'http' : 'https';
    }

    // ---------------- 密码保护 ----------------

    public static function hasPassword(array $link): bool
    {
        return (string) ($link['password_hash'] ?? '') !== '';
    }

    public static function unlockCookie(int $id): string
    {
        return 'lp_u' . $id;
    }

    /** 解锁凭证绑在密码哈希上：改了密码，旧凭证自然失效 */
    public static function unlockValue(array $link): string
    {
        return hash_hmac('sha256', 'unlock:' . (int) $link['id'], (string) $link['password_hash']);
    }

    public static function unlocked(array $link): bool
    {
        if (!self::hasPassword($link)) {
            return true;
        }
        $name = self::unlockCookie((int) $link['id']);
        $got = (string) ($_COOKIE[$name] ?? '');
        return $got !== '' && hash_equals(self::unlockValue($link), $got);
    }

    public static function markUnlocked(array $link): void
    {
        if (headers_sent()) {
            return;
        }
        @setcookie(self::unlockCookie((int) $link['id']), self::unlockValue($link), [
            'expires'  => time() + 7200,
            'path'     => '/',
            'secure'   => (bool) Config::get('session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::unlockCookie((int) $link['id'])] = self::unlockValue($link);
    }

    // ---------------- 内部 ----------------

    /** 返回 '' 或域名；null 表示这个域名不能用 */
    private static function checkDomain(int $ownerId, string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?://#i', '', $domain);
        $domain = rtrim($domain, '/');
        if ($domain === '') {
            return '';
        }
        if (!Gate::allows('domains.custom')) {
            return null;
        }
        $row = Db::one(
            'SELECT `id` FROM `user_domains` WHERE `user_id` = ? AND `host` = ? AND `verified` = 1 LIMIT 1',
            [$ownerId, $domain]
        );
        return $row === null ? null : $domain;
    }

    private static function normalizeTarget(string $url): string
    {
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        return preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
    }

    private static function normalizeGroup(string $group): string
    {
        $group = trim($group);
        $group = (string) preg_replace('/\s+/u', ' ', $group);
        return mb_substr($group, 0, 48);
    }

    /** @return string|null|false  false=格式错 */
    private static function parseExpires(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // datetime-local 带 T，统一成空格
        $value = str_replace('T', ' ', $value);
        $ts = strtotime($value);
        if ($ts === false) {
            return false;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /** 目标域名是否在云端风险库里（pro 的 cloud.blocklist） */
    public static function blockedReason(string $targetUrl): string
    {
        if (!Gate::allows('cloud.blocklist')) {
            return '';
        }
        $host = strtolower((string) parse_url($targetUrl, PHP_URL_HOST));
        if ($host === '') {
            return '';
        }
        try {
            $hit = BlockCache::hit($host);
        } catch (\Throwable $e) {
            return '';
        }
        if ($hit === null) {
            return '';
        }
        $kind = [
            'phishing' => '钓鱼站',
            'malware'  => '挂马站',
            'scam'     => '诈骗站',
            'adult'    => '违规内容',
            'spam'     => '垃圾推广',
        ][(string) ($hit['kind'] ?? '')] ?? '风险站';
        return '目标域名在风险库里，被识别为' . $kind . '。';
    }
}
