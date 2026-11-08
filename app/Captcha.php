<?php
/**
 * 一次性验证码。
 *
 * 几条硬规则，都是被刷过之后才加的：
 *   1. 库里只存 HMAC 摘要，不存明文 —— 库被人看到也拿不到码。
 *   2. 一分钟一条、每小时 5 条、同 IP 每小时 20 条。
 *   3. 校验失败累计 5 次就作废，防止拿 6 位数字穷举。
 *   4. 只认最新一条，重新发码等于让旧码失效。
 *   5. 10 分钟过期，用掉即作废。
 */
declare(strict_types=1);

namespace App;

final class Captcha
{
    public const TTL = 600;          // 有效期 10 分钟
    public const MAX_ATTEMPTS = 5;   // 错 5 次作废
    private const RESEND_GAP = 60;   // 同目标重发间隔
    private const PER_TARGET_HOUR = 5;
    private const PER_IP_HOUR = 20;

    public const PURPOSES = ['register', 'login', 'reset', 'bind'];

    /**
     * 生成并写库。返回明文码，调用方负责发出去。
     *
     * @throws \RuntimeException 频控命中或写库失败
     */
    public static function issue(string $target, string $channel, string $purpose, string $ip = ''): string
    {
        $target = self::normalize($target);
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \RuntimeException('验证码用途不对');
        }
        self::guardRate($target, $purpose, $ip);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now  = time();

        Db::insert('verification_codes', [
            'target'     => $target,
            'channel'    => $channel === 'sms' ? 'sms' : 'email',
            'purpose'    => $purpose,
            'code_hash'  => self::hash($code),
            'attempts'   => 0,
            'ip'         => mb_substr($ip, 0, 45),
            'expires_at' => date('Y-m-d H:i:s', $now + self::TTL),
            'created_at' => date('Y-m-d H:i:s', $now),
        ]);

        return $code;
    }

    /**
     * 校验并消费。
     *
     * @return array{ok:bool,message:string}
     */
    public static function verify(string $target, string $purpose, string $code): array
    {
        $target = self::normalize($target);
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return ['ok' => false, 'message' => '验证码是 6 位数字。'];
        }

        $row = Db::one(
            'SELECT * FROM `verification_codes`
              WHERE `target` = ? AND `purpose` = ? AND `used_at` IS NULL
              ORDER BY `id` DESC LIMIT 1',
            [$target, $purpose]
        );
        if ($row === null) {
            return ['ok' => false, 'message' => '没有有效的验证码，先点「获取验证码」。'];
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return ['ok' => false, 'message' => '验证码过期了，重新获取一个。'];
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'message' => '这个验证码错太多次，已经作废，重新获取。'];
        }

        if (!hash_equals((string) $row['code_hash'], self::hash($code))) {
            Db::exec('UPDATE `verification_codes` SET `attempts` = `attempts` + 1 WHERE `id` = ?', [(int) $row['id']]);
            $left = self::MAX_ATTEMPTS - ((int) $row['attempts'] + 1);
            return ['ok' => false, 'message' => $left > 0 ? '验证码不对，还能试 ' . $left . ' 次。' : '验证码不对，这次作废了，重新获取。'];
        }

        // 用掉即作废。并发下只有一次 UPDATE 能命中，靠 affected rows 判断
        $n = Db::exec(
            'UPDATE `verification_codes` SET `used_at` = ? WHERE `id` = ? AND `used_at` IS NULL',
            [now(), (int) $row['id']]
        );
        if ($n === 0) {
            return ['ok' => false, 'message' => '这个验证码刚刚被用掉了，重新获取。'];
        }
        return ['ok' => true, 'message' => ''];
    }

    private static function guardRate(string $target, string $purpose, string $ip): void
    {
        $last = Db::val(
            'SELECT `created_at` FROM `verification_codes`
              WHERE `target` = ? AND `purpose` = ? ORDER BY `id` DESC LIMIT 1',
            [$target, $purpose]
        );
        if ($last !== null && $last !== false) {
            $gap = time() - (int) strtotime((string) $last);
            if ($gap >= 0 && $gap < self::RESEND_GAP) {
                throw new \RuntimeException('刚发过，' . (self::RESEND_GAP - $gap) . ' 秒后再点。');
            }
        }

        $hourAgo = date('Y-m-d H:i:s', time() - 3600);
        $byTarget = (int) Db::val(
            'SELECT COUNT(*) FROM `verification_codes` WHERE `target` = ? AND `created_at` >= ?',
            [$target, $hourAgo],
            0
        );
        if ($byTarget >= self::PER_TARGET_HOUR) {
            throw new \RuntimeException('这个号码这一小时发得太多了，歇一会儿再试。');
        }

        if ($ip !== '') {
            $byIp = (int) Db::val(
                'SELECT COUNT(*) FROM `verification_codes` WHERE `ip` = ? AND `created_at` >= ?',
                [$ip, $hourAgo],
                0
            );
            if ($byIp >= self::PER_IP_HOUR) {
                throw new \RuntimeException('当前网络请求太频繁，稍后再试。');
            }
        }
    }

    /**
     * 发送失败时把刚发的这条删掉。
     * 不删的话用户会被 60 秒频控挡住 —— 明明是服务端没发出去，却罚用户等。
     */
    public static function discard(string $target, string $purpose, string $code): void
    {
        try {
            Db::exec(
                'DELETE FROM `verification_codes`
                  WHERE `target` = ? AND `purpose` = ? AND `code_hash` = ? AND `used_at` IS NULL',
                [self::normalize($target), $purpose, self::hash($code)]
            );
        } catch (\Throwable $e) {
            // 删不掉就让它自然过期
        }
    }

    /** 顺手清掉过期记录，登录时调用即可，不用定时任务 */
    public static function gc(): void
    {
        try {
            Db::exec('DELETE FROM `verification_codes` WHERE `created_at` < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        } catch (\Throwable $e) {
            // 清理失败不影响业务
        }
    }

    private static function hash(string $code): string
    {
        $key = (string) Config::get('app.key', 'fulklink');
        return hash_hmac('sha256', $code, $key);
    }

    /** 邮箱小写、手机号只留数字，避免同一个目标写出多条记录 */
    private static function normalize(string $target): string
    {
        $target = trim($target);
        return strpos($target, '@') !== false ? strtolower($target) : Sms::normalize($target);
    }
}
