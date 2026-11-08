<?php
/**
 * 全局辅助函数。业务代码里最常用的几个短名字都在这里。
 */
declare(strict_types=1);

use App\Config;

if (!function_exists('e')) {
    /** HTML 转义。模板里所有输出都过这个函数。 */
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('cfg')) {
    function cfg(string $key, $default = null)
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('now')) {
    /** 全站统一时间格式，与 MySQL DATETIME 对齐 */
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('path')) {
    /** 站内相对路径。不拼域名，换域名、用 IP 访问都不会跑偏 */
    function path(string $p = ''): string
    {
        if ($p === '') {
            return '/';
        }
        if (preg_match('#^https?://#i', $p)) {
            return $p;
        }
        return '/' . ltrim($p, '/');
    }
}

if (!function_exists('url')) {
    /** 绝对地址。邮件正文、支付回调这种必须在站外用到的场景才用它 */
    function url(string $p = ''): string
    {
        if (preg_match('#^https?://#i', $p)) {
            return $p;
        }
        $base = rtrim((string) Config::get('app.url', ''), '/');
        if ($p === '') {
            return $base . '/';
        }
        return $base . '/' . ltrim($p, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $p): string
    {
        $ver = (string) Config::get('app.version', '1');
        return path('/assets/' . ltrim($p, '/')) . '?v=' . rawurlencode($ver);
    }
}

if (!function_exists('fmt_date')) {
    function fmt_date(?string $datetime, bool $withTime = false): string
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '—';
        }
        return date($withTime ? 'Y-m-d H:i' : 'Y-m-d', $ts);
    }
}

if (!function_exists('money')) {
    /** 库内一律存分，展示时转元 */
    function money(int $cents): string
    {
        return '¥' . number_format($cents / 100, 2);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $to, int $status = 302): \App\Response
    {
        return \App\Response::redirect($to, $status);
    }
}

if (!function_exists('array_get')) {
    function array_get(array $arr, string $key, $default = null)
    {
        return array_key_exists($key, $arr) ? $arr[$key] : $default;
    }
}

if (!function_exists('mask_middle')) {
    /** 证件号 / 邮箱脱敏：前后各留几位，中间打星 */
    function mask_middle(string $value, int $head = 3, int $tail = 4): string
    {
        $len = mb_strlen($value);
        if ($len <= $head + $tail) {
            return str_repeat('*', max(1, $len));
        }
        return mb_substr($value, 0, $head) . str_repeat('*', max(3, $len - $head - $tail)) . mb_substr($value, -$tail);
    }
}
