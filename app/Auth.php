<?php
/**
 * 账号与会话。
 *
 * 不用 PHP 自带的 session：它的存储路径、GC 概率、并发锁在便宜主机上都不受控，
 * 加密包里也不好排查。这里把会话放 MySQL 自己的 sessions 表，能查、能踢、能统计。
 *
 * CSRF 用独立 cookie 而不是会话：注册页、登录页在没登录时也要有 token，
 * 给游客建会话等于给攻击者一个写库的入口。
 */
declare(strict_types=1);

namespace App;

final class Auth
{
    private const CSRF_COOKIE = 'lp_csrf';
    private const CSRF_TTL = 7200;         // 2 小时
    private const FAIL_WINDOW = 900;       // 15 分钟
    private const FAIL_LIMIT = 8;          // 同一身份 15 分钟内失败 8 次先停一会儿

    private static ?array $user = null;
    private static bool $loaded = false;

    // ---------------- 会话 ----------------

    /** 当前登录用户（含 identity_level），未登录返回 null */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $sid = (string) ($_COOKIE[(string) Config::get('session.cookie', 'lp_sid')] ?? '');
        if ($sid === '' || !preg_match('/^[a-f0-9]{64}$/', $sid)) {
            return self::$user = null;
        }

        try {
            $row = Db::one(
                'SELECT u.*, s.`sid`, s.`csrf_token`
                   FROM `sessions` s JOIN `users` u ON u.`id` = s.`user_id`
                  WHERE s.`sid` = ? AND s.`expires_at` > ? LIMIT 1',
                [$sid, now()]
            );
        } catch (\Throwable $e) {
            return self::$user = null;
        }
        if ($row === null || (string) $row['status'] !== 'active') {
            self::clearCookie();
            return self::$user = null;
        }
        return self::$user = $row;
    }

    public static function id(): int
    {
        $u = self::user();
        return $u === null ? 0 : (int) $u['id'];
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && (string) $u['role'] === 'admin';
    }

    /** 登录成功后建会话。旧会话不删，多设备各自有效 */
    public static function login(array $user): void
    {
        $sid = bin2hex(random_bytes(32));
        $ttl = (int) Config::get('session.ttl', 2592000);
        $req = new Request();

        Db::insert('sessions', [
            'sid'        => $sid,
            'user_id'    => (int) $user['id'],
            'role'       => (string) $user['role'],
            'ip'         => $req->ip(),
            'ua'         => mb_substr($req->ua(), 0, 250),
            'csrf_token' => bin2hex(random_bytes(16)),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            'created_at' => now(),
        ]);

        Db::exec(
            'UPDATE `users` SET `last_login_at` = ?, `last_login_ip` = ? WHERE `id` = ?',
            [now(), $req->ip(), (int) $user['id']]
        );

        self::setCookie($sid, time() + $ttl);

        // 顺手清过期会话，五分之一概率执行，避免每次登录都扫表
        if (random_int(1, 5) === 1) {
            try {
                Db::exec('DELETE FROM `sessions` WHERE `expires_at` < ?', [now()]);
            } catch (\Throwable $e) {
            }
        }

        self::$user = $user;
        self::$loaded = true;
        Db::log('auth.login', '登录 ' . self::identOf($user), ['user_id' => (int) $user['id']]);
    }

    public static function logout(): void
    {
        $sid = (string) ($_COOKIE[(string) Config::get('session.cookie', 'lp_sid')] ?? '');
        if ($sid !== '') {
            try {
                Db::exec('DELETE FROM `sessions` WHERE `sid` = ?', [$sid]);
            } catch (\Throwable $e) {
            }
        }
        self::clearCookie();
        self::$user = null;
        self::$loaded = true;
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        $secure = (bool) Config::get('session.secure', false);
        @setcookie((string) Config::get('session.cookie', 'lp_sid'), $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        @setcookie((string) Config::get('session.cookie', 'lp_sid'), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // ---------------- 注册与登录 ----------------

    /** 邮箱小写；手机号只留数字 */
    public static function normalizeIdent(string $ident): string
    {
        $ident = trim($ident);
        return strpos($ident, '@') !== false ? strtolower($ident) : Sms::normalize($ident);
    }

    public static function findByIdent(string $ident): ?array
    {
        $ident = self::normalizeIdent($ident);
        if ($ident === '') {
            return null;
        }
        if (strpos($ident, '@') !== false) {
            return Db::one('SELECT * FROM `users` WHERE `email` = ? LIMIT 1', [$ident]);
        }
        return Db::one('SELECT * FROM `users` WHERE `phone` = ? LIMIT 1', [$ident]);
    }

    /**
     * 注册。调用前验证码必须已经校验通过。
     *
     * @return array{ok:bool,message:string,user:?array}
     */
    public static function register(string $ident, string $password, string $ip = ''): array
    {
        $ident = self::normalizeIdent($ident);
        $isEmail = strpos($ident, '@') !== false;

        if ($isEmail && !filter_var($ident, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => '邮箱格式不对。', 'user' => null];
        }
        if (!$isEmail && !Sms::validPhone($ident)) {
            return ['ok' => false, 'message' => '手机号格式不对。', 'user' => null];
        }
        $check = self::passwordProblem($password);
        if ($check !== '') {
            return ['ok' => false, 'message' => $check, 'user' => null];
        }
        if (self::findByIdent($ident) !== null) {
            return ['ok' => false, 'message' => '这个' . ($isEmail ? '邮箱' : '手机号') . '已经注册过了，直接登录。', 'user' => null];
        }

        $row = [
            'email'         => $isEmail ? $ident : null,
            'phone'         => $isEmail ? null : $ident,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'name'          => '',
            'role'          => 'user',
            'plan_code'     => 'trial',
            'identity_level' => 'none',
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        try {
            $id = Db::insert('users', $row);
        } catch (\PDOException $e) {
            // 唯一索引兜底：并发注册同一账号时第二个会撞键
            if ((string) $e->getCode() === '23000') {
                return ['ok' => false, 'message' => '这个账号刚刚被注册了，换一个或直接登录。', 'user' => null];
            }
            throw $e;
        }

        $user = Db::one('SELECT * FROM `users` WHERE `id` = ?', [$id]);
        Db::log('auth.register', '注册 ' . $ident, ['user_id' => $id, 'channel' => $isEmail ? 'email' : 'sms'], 'info');
        return ['ok' => true, 'message' => '', 'user' => $user];
    }

    /**
     * 校验密码。成功返回用户行，失败返回错误消息。
     *
     * @return array{ok:bool,message:string,user:?array}
     */
    public static function attempt(string $ident, string $password): array
    {
        $ident = self::normalizeIdent($ident);
        if ($ident === '' || $password === '') {
            return ['ok' => false, 'message' => '账号和密码都要填。', 'user' => null];
        }

        if (self::tooManyFails($ident)) {
            return ['ok' => false, 'message' => '这个账号连续登录失败太多次，等 15 分钟再试，或者找客服。', 'user' => null];
        }

        $user = self::findByIdent($ident);
        // 用户不存在也走一次 password_verify，让耗时接近，避免被用来枚举账号
        $hash = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $ok = password_verify($password, (string) $hash);

        if (!$ok || $user === null) {
            self::recordFail($ident);
            return ['ok' => false, 'message' => '账号或密码不对。', 'user' => null];
        }
        if ((string) $user['status'] !== 'active') {
            return ['ok' => false, 'message' => '这个账号被停用了，联系客服处理。', 'user' => null];
        }

        // 老哈希升级：PHP 换了默认算法时自动跟上
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Db::exec('UPDATE `users` SET `password_hash` = ? WHERE `id` = ?', [
                password_hash($password, PASSWORD_DEFAULT), (int) $user['id'],
            ]);
        }
        return ['ok' => true, 'message' => '', 'user' => $user];
    }

    /** 密码强度：只拦最短长度和全数字，不做复杂度强迫症 */
    public static function passwordProblem(string $password): string
    {
        if (strlen($password) < 8) {
            return '密码至少 8 位。';
        }
        if (strlen($password) > 72) {
            return '密码太长了，72 位以内。';
        }
        if (preg_match('/^\d+$/', $password)) {
            return '密码不能全是数字。';
        }
        return '';
    }

    public static function changePassword(int $userId, string $newPassword): void
    {
        Db::exec('UPDATE `users` SET `password_hash` = ?, `updated_at` = ? WHERE `id` = ?', [
            password_hash($newPassword, PASSWORD_DEFAULT), now(), $userId,
        ]);
        // 改密后踢掉所有其它设备
        Db::exec('DELETE FROM `sessions` WHERE `user_id` = ?', [$userId]);
    }

    /** 固定长度的限流键，避免长标识被截断后写入与查询对不上 */
    private static function failKey(string $ident): string
    {
        return 'authfail:' . hash('sha256', self::normalizeIdent($ident));
    }

    private static function recordFail(string $ident): void
    {
        try {
            Db::insert('events', [
                'level'      => 'warn',
                'kind'       => 'auth.fail',
                'message'    => self::failKey($ident),
                'meta'       => json_encode(['ip' => (new Request())->ip()], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function tooManyFails(string $ident): bool
    {
        try {
            $n = (int) Db::val(
                'SELECT COUNT(*) FROM `events` WHERE `kind` = ? AND `message` = ? AND `created_at` >= ?',
                ['auth.fail', self::failKey($ident), date('Y-m-d H:i:s', time() - self::FAIL_WINDOW)],
                0
            );
        } catch (\Throwable $e) {
            return false;
        }
        return $n >= self::FAIL_LIMIT;
    }

    // ---------------- CSRF ----------------

    /** 取当前 token，没有就发一个 cookie */
    public static function csrf(): string
    {
        $cur = (string) ($_COOKIE[self::CSRF_COOKIE] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $cur)) {
            return $cur;
        }
        $token = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            @setcookie(self::CSRF_COOKIE, $token, [
                'expires'  => time() + self::CSRF_TTL,
                'path'     => '/',
                'secure'   => (bool) Config::get('session.secure', false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::CSRF_COOKIE] = $token;
        return $token;
    }

    /** 表单隐藏域用 */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::csrf()) . '">';
    }

    public static function checkCsrf(?string $token): bool
    {
        $cur = (string) ($_COOKIE[self::CSRF_COOKIE] ?? '');
        return $cur !== '' && $token !== null && $token !== '' && hash_equals($cur, $token);
    }

    // ---------------- 辅助 ----------------

    /** 展示用的账号标识（有邮箱显示邮箱，否则显示脱敏手机号） */
    public static function identOf(array $user): string
    {
        $email = (string) ($user['email'] ?? '');
        if ($email !== '') {
            return $email;
        }
        $phone = (string) ($user['phone'] ?? '');
        return $phone === '' ? '用户#' . (int) ($user['id'] ?? 0) : mask_middle($phone, 3, 4);
    }

    /** 该用户属于「邮箱注册」还是「手机注册」，个人中心改绑时用 */
    public static function channelOf(array $user): string
    {
        return (string) ($user['email'] ?? '') !== '' ? 'email' : 'sms';
    }
}
