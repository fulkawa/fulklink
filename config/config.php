<?php
/**
 * 站点配置。所有可变项从 .env 读取，这里只给默认值。
 * 客户部署时只需要改 .env，不用动这个文件。
 */
declare(strict_types=1);

$env = static function (string $key, $default = null) {
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return $v;
};

$bool = static function (string $key, bool $default = false): bool {
    if (array_key_exists($key, $_ENV)) {
        $v = (string) $_ENV[$key];
    } else {
        $raw = getenv($key);
        $v = $raw === false ? '' : (string) $raw;
    }
    if ($v === '') {
        return $default;
    }
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
};

return [
    'app' => [
        /* 可信代理 CIDR 列表，逗号分隔。留空 = 只信 REMOTE_ADDR；
           仅当来源 IP 命中这些网段时才解析 X-Forwarded-For / X-Forwarded-Proto。 */
        'trusted_proxies' => $env('APP_TRUSTED_PROXIES', ''),
        'name'      => $env('SITE_NAME', 'FulkLink 福链'),
        'url'       => rtrim((string) $env('SITE_URL', 'http://localhost'), '/'),
        'tagline'   => $env('SITE_TAGLINE', '短链、二维码、跳转记录'),
        /* SHOW_LANDING=1：客户站未登录时先展示落地页（按授权档位渲染），默认关闭直接进登录 */
        'landing'   => $env('SHOW_LANDING', '') === '1',
        /* DEMO_BANNER=1：落地页显示价格/购买/演示账号（仅演示站）。客户站即使开落地页也绝不出现销售内容 */
        'demo_banner' => $env('DEMO_BANNER', '') === '1',
        'env'       => $env('APP_ENV', 'production'),
        'debug'     => $bool('APP_DEBUG', false),
        'timezone'  => $env('APP_TIMEZONE', 'Asia/Shanghai'),
        'key'       => $env('APP_KEY', ''),
        /* 版本跟着根目录 VERSION 文件走，云端更新覆盖包之后 /health 才会报新版本 */
        'version'   => (static function (): string {
            $file = dirname(__DIR__) . '/VERSION';
            if (is_file($file)) {
                $v = trim((string) @file_get_contents($file));
                if ($v !== '' && preg_match('/^[0-9][0-9A-Za-z.\-]*$/', $v)) {
                    return $v;
                }
            }
            return '1.0.0';
        })(),
        /* cloud=官网兼云端，client=客户站连回云端。同一套代码两种角色 */
        'mode'      => $env('APP_MODE', 'cloud'),
    ],

    'db' => [
        'host'    => $env('DB_HOST', '127.0.0.1'),
        'port'    => (int) $env('DB_PORT', '3306'),
        'name'    => $env('DB_NAME', 'fulklink'),
        'user'    => $env('DB_USER', 'fulklink'),
        'pass'    => $env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'cookie'      => 'lp_sid',
        'ttl'         => 60 * 60 * 24 * 30,
        'secure'      => $bool('COOKIE_SECURE', false),
        'per_page'    => 20,
    ],

    /* 授权中心。mode=live 走真实签发，mock 仅本地调试。 */
    'licensing' => [
        'mode'         => $env('LICENSING_MODE', 'live'),
        'base'         => rtrim((string) $env('VENDOR_BASE', 'https://fulkwl.cn/api/vendor/v1'), '/'),
        'api_key'      => $env('VENDOR_API_KEY', ''),
        'api_secret'   => $env('VENDOR_API_SECRET', ''),
        'product_code' => $env('VENDOR_PRODUCT_CODE', 'fulklink'),
        'timeout'      => 20,
    ],

    'pay' => [
        'provider' => $env('PAY_PROVIDER', 'auto'),
        'mock'     => $bool('ALLOW_MOCK_PAY', false),
    ],

    'mail' => [
        /* 默认关闭：仅当 SMTP 使用受控自签证书时才显式开启，避免关闭证书身份校验。 */
        'allow_self_signed' => $bool('MAIL_ALLOW_SELF_SIGNED', false),
        'host' => $env('SMTP_HOST', ''),
        'port' => (int) $env('SMTP_PORT', '465'),
        'user' => $env('SMTP_USER', ''),
        'pass' => $env('SMTP_PASS', ''),
        'from' => $env('SMTP_FROM', ''),
        'name' => $env('SMTP_NAME', 'FulkLink'),
    ],

    'site' => [
        /* 默认值一律留空：这两项会进开源包，写死等于把公司名和 QQ 发给每个客户。
           线上由 .env 的 COMPANY / SUPPORT_QQ 覆盖。 */
        'company'  => $env('COMPANY', ''),
        'icp'      => $env('ICP_BEIAN', ''),
        'support'  => $env('SUPPORT_QQ', ''),
        'email'    => $env('SUPPORT_EMAIL', 'fulkteam@fulkwl.cn'),
        'short'    => $env('SHORT_DOMAIN', ''),
    ],

    /* 免费版 / 各版本的能力边界。服务端强制，不靠界面隐藏。 */
    'edition' => [
        'force' => $env('EDITION_FORCE', ''), // 打包时注入，空表示按授权判定
    ],

    /*
     * 云端。官网自己就是云端（APP_MODE=cloud），客户站配成 client 连回来。
     * 客户站一旦连不上，按 ttl + grace_hours 降级，短链跳转不受影响。
     */
    'cloud' => [
        'url'         => rtrim((string) $env('CLOUD_URL', 'https://lp.fulkteam.cn'), '/'),
        'timeout'     => (int) $env('CLOUD_TIMEOUT', '4'),
        'ttl'         => (int) $env('CLOUD_TTL', '86400'),
        'grace_hours' => (int) $env('CLOUD_GRACE_HOURS', '72'),
        'heartbeat'   => (int) $env('CLOUD_HEARTBEAT', '21600'),
        /* 云端接口的日调用上限，防止被当成免费 API 刷 */
        'daily_limit' => (int) $env('CLOUD_DAILY_LIMIT', '20000'),
    ],
];
