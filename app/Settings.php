<?php
/**
 * 平台配置读写。支付密钥、实名密钥这类敏感字段加密存库，
 * 非敏感的直接明文，方便用 SQL 排查。
 *
 * 表不存在（还没安装）时全部返回默认值，页面照常能打开。
 */
declare(strict_types=1);

namespace App;

final class Settings
{
    private const SECRET = [
        'pay.epay.key',
        'pay.wechat.apiv3_key',
        'pay.wechat.private_key',
        'pay.alipay.private_key',
        'pay.alipay.public_key',
        'mail.pass',
        'sms.aliyun.secret',
        'sms.tencent.secret',
        'update.push_key',
    ];

    /** @var array<string,string>|null 原始密文缓存 */
    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function store(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            foreach (Db::all('SELECT `k`, `v` FROM `settings`') as $row) {
                self::$cache[(string) $row['k']] = (string) ($row['v'] ?? '');
            }
        } catch (\Throwable $e) {
            self::$cache = [];
        }
        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        $all = self::store();
        if (!array_key_exists($key, $all)) {
            return $default;
        }
        $raw = $all[$key];
        return in_array($key, self::SECRET, true) ? Crypto::decrypt($raw) : $raw;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== '';
    }

    public static function set(string $key, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            try {
                Db::exec('DELETE FROM `settings` WHERE `k` = ?', [$key]);
            } catch (\Throwable $e) {
                // 更新密钥删除失败必须阻止事务提交，不能报告已撤销。
                if (str_starts_with($key, 'update.')) {
                    throw new \RuntimeException('更新配置删除失败。', 0, $e);
                }
            }
            unset(self::$cache[$key]);
            return;
        }
        $stored = in_array($key, self::SECRET, true) ? Crypto::encrypt($value) : $value;
        try {
            Db::q(
                'INSERT INTO `settings` (`k`,`v`,`updated_at`) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE `v` = VALUES(`v`), `updated_at` = VALUES(`updated_at`)',
                [$key, $stored, now()]
            );
            self::store();
            self::$cache[$key] = $stored;
        } catch (\Throwable $e) {
            // 写不进去不抛，后台会显示保存失败（调用方判断返回值）
            throw new \RuntimeException('配置写入失败：' . $e->getMessage());
        }
    }

    /** 支付渠道配置，结构与旧版一致，方便沿用已有配置项 */
    public static function pay(): array
    {
        return [
            'provider' => self::get('pay.provider', (string) Config::get('pay.provider', 'auto')),
            'wechat'   => [
                'enabled'    => self::get('pay.wechat.enabled') === '1',
                'appid'      => self::get('pay.wechat.appid'),
                'mchid'      => self::get('pay.wechat.mchid'),
                'serial'     => self::get('pay.wechat.serial_no'),
                'apiv3Key'   => self::get('pay.wechat.apiv3_key'),
                'privateKey' => self::get('pay.wechat.private_key'),
                'h5Enabled'  => self::get('pay.wechat.h5_enabled') === '1',
            ],
            'alipay'   => [
                'enabled'    => self::get('pay.alipay.enabled') === '1',
                'appId'      => self::get('pay.alipay.app_id'),
                'mode'       => self::get('pay.alipay.mode', 'face_to_face'),
                'privateKey' => self::get('pay.alipay.private_key'),
                'publicKey'  => self::get('pay.alipay.public_key'),
            ],
            'epay'     => [
                'enabled' => self::get('pay.epay.enabled') === '1',
                'apiUrl'  => self::get('pay.epay.api_url'),
                'pid'     => self::get('pay.epay.pid'),
                'key'     => self::get('pay.epay.key'),
                'type'    => self::get('pay.epay.type', 'alipay'),
            ],
            'offline'  => [
                'enabled'     => self::get('pay.offline.enabled') === '1',
                'bankName'    => self::get('pay.offline.bank_name'),
                'accountName' => self::get('pay.offline.account_name'),
                'accountNo'   => self::get('pay.offline.account_no'),
                'note'        => self::get('pay.offline.note'),
            ],
        ];
    }

    /**
     * 前台可选的支付方式。易支付一个渠道能出微信和支付宝两种，
     * 展开成两条，客户看到的是「微信 / 支付宝」而不是「聚合支付」。
     * @return array<int,array{code:string,name:string,hint:string}>
     */
    public static function channels(): array
    {
        $cfg = self::pay();
        $out = [];

        if ($cfg['epay']['enabled'] && $cfg['epay']['pid'] !== '' && $cfg['epay']['key'] !== '') {
            $out[] = ['code' => 'epay_wxpay', 'name' => '微信支付', 'hint' => '扫码付款'];
            $out[] = ['code' => 'epay_alipay', 'name' => '支付宝', 'hint' => '扫码或跳转'];
        }
        /* 官方直连（微信 V3 / 支付宝 RSA2）回调还没在 PHP 版实现，
           配置里即使开着也不放到前台，免得客户点了报错。实现后把这两段放回来。 */
        if ($cfg['offline']['enabled']) {
            $out[] = ['code' => 'offline', 'name' => '对公转账', 'hint' => '到账后开通'];
        }
        return $out;
    }

    /** 授权中心是否配好 */
    public static function vendorReady(): bool
    {
        $k = (string) Config::get('licensing.api_key', '');
        $s = (string) Config::get('licensing.api_secret', '');
        return strlen($k) >= 16 && strlen($s) >= 16;
    }
}
