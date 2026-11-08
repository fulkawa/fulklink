<?php
/**
 * 敏感字段加解密（支付密钥、证件号）。
 *
 * 密文格式与旧版一致：v1: + base64(iv 12 | tag 16 | ciphertext)，
 * 所以从旧库导过来的密钥不用重新填，直接能解开。
 * 主密钥优先读 data/.settings.key，没有就用 .env 的 APP_KEY 派生一份并落盘。
 */
declare(strict_types=1);

namespace App;

final class Crypto
{
    private const PREFIX = 'v1:';

    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $file = BASE_PATH . '/data/.settings.key';

        if (is_file($file)) {
            $hex = trim((string) @file_get_contents($file));
            if (strlen($hex) === 64 && ctype_xdigit($hex)) {
                return self::$key = (string) hex2bin($hex);
            }
        }

        $src = (string) Config::get('app.key', '');
        if ($src !== '' && preg_match('/^[0-9a-fA-F]{64}$/', $src) === 1) {
            $bin = (string) hex2bin($src);
        } else {
            $bin = hash('sha256', $src === '' ? 'fulklink-no-key' : $src, true);
        }

        // 落盘：备份数据库时必须连这个文件一起拷，否则密钥解不开
        if (is_dir(dirname($file)) && is_writable(dirname($file))) {
            @file_put_contents($file, bin2hex($bin));
            @chmod($file, 0600);
        }
        return self::$key = $bin;
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('加密失败：OpenSSL 不可用');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    /** 解不开就返回空串，绝不让页面崩 */
    public static function decrypt(string $stored): string
    {
        $stored = (string) $stored;
        if ($stored === '' || strpos($stored, self::PREFIX) !== 0) {
            return $stored;
        }
        $buf = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($buf === false || strlen($buf) < 29) {
            return '';
        }
        $pt = openssl_decrypt(
            substr($buf, 28),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr($buf, 0, 12),
            substr($buf, 12, 16)
        );
        return $pt === false ? '' : $pt;
    }
}
