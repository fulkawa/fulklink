<?php
/**
 * User-Agent 解析。不引第三方库：跳转是热路径，一个正则就要跑一次，
 * 引一个几 MB 的库不划算，判别规则也用不到那么细。
 *
 * 只回答三个问题：什么设备、什么系统、什么浏览器；外加「是不是爬虫」。
 */
declare(strict_types=1);

namespace App;

final class Ua
{
    private const BOTS = [
        'bot', 'spider', 'crawl', 'slurp', 'curl', 'wget', 'python-requests',
        'httpclient', 'headlesschrome', 'phantomjs', 'scrapy', 'feedfetcher',
        'facebookexternalhit', 'whatsapp', 'telegrambot', 'go-http-client',
        'okhttp', 'java/', 'apache-httpclient', 'lighthouse', 'pingdom', 'uptime',
    ];

    /** @return array{device:string,os:string,browser:string,bot:bool} */
    public static function parse(string $ua): array
    {
        return [
            'device'  => self::device($ua),
            'os'      => self::os($ua),
            'browser' => self::browser($ua),
            'bot'     => self::isBot($ua),
        ];
    }

    public static function isBot(string $ua): bool
    {
        if ($ua === '') {
            return true;      // 没有 UA 基本是脚本
        }
        $l = strtolower($ua);
        foreach (self::BOTS as $needle) {
            if (strpos($l, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function device(string $ua): string
    {
        if (self::isBot($ua)) {
            return 'bot';
        }
        $l = strtolower($ua);
        if (preg_match('/ipad|tablet|playbook|silk|kindle/', $l)) {
            return 'tablet';
        }
        if (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone|blackberry|opera mini/', $l)) {
            return 'mobile';
        }
        if (preg_match('/android/', $l)) {
            return 'tablet';
        }
        return 'desktop';
    }

    public static function os(string $ua): string
    {
        $l = strtolower($ua);
        $map = [
            'windows nt 10'  => 'Windows 10/11',
            'windows nt 6.3' => 'Windows 8.1',
            'windows nt 6.1' => 'Windows 7',
            'windows'        => 'Windows',
            'iphone os'      => 'iOS',
            'ipad'           => 'iPadOS',
            'mac os x'       => 'macOS',
            'android'        => 'Android',
            'harmonyos'      => 'HarmonyOS',
            'cros'           => 'ChromeOS',
            'linux'          => 'Linux',
        ];
        foreach ($map as $needle => $label) {
            if (strpos($l, $needle) !== false) {
                return $label;
            }
        }
        return 'unknown';
    }

    public static function browser(string $ua): string
    {
        $l = strtolower($ua);
        // 顺序有讲究：套壳浏览器的 UA 里同时带着 Chrome/Safari
        $map = [
            'micromessenger' => '微信',
            'qqbrowser'      => 'QQ 浏览器',
            'ucbrowser'      => 'UC',
            'alipayclient'   => '支付宝',
            'dingtalk'       => '钉钉',
            'weibo'          => '微博',
            'baiduboxapp'    => '百度',
            'douyin'         => '抖音',
            'edg/'           => 'Edge',
            'edga/'          => 'Edge',
            'opr/'           => 'Opera',
            'firefox'        => 'Firefox',
            'chrome'         => 'Chrome',
            'safari'         => 'Safari',
            'msie'           => 'IE',
            'trident'        => 'IE',
        ];
        foreach ($map as $needle => $label) {
            if (strpos($l, $needle) !== false) {
                return $label;
            }
        }
        return 'unknown';
    }

    /** 来源归到渠道。referer 只留 host，不存完整 URL */
    public static function sourceOf(string $refererUrl): string
    {
        $host = strtolower((string) parse_url($refererUrl, PHP_URL_HOST));
        if ($host === '') {
            return 'direct';
        }
        $map = [
            'baidu.'     => 'baidu',
            'google.'    => 'google',
            'bing.'      => 'bing',
            'sogou.'     => 'sogou',
            'so.com'     => '360',
            'sm.cn'      => 'shenma',
            'weixin'     => 'weixin',
            'wechat'     => 'weixin',
            'qq.com'     => 'qq',
            'weibo.'     => 'weibo',
            'zhihu.'     => 'zhihu',
            'douyin.'    => 'douyin',
            'toutiao.'   => 'toutiao',
            'xiaohongshu' => 'xhs',
            'bilibili.'  => 'bilibili',
            'facebook.'  => 'facebook',
            'twitter.'   => 'twitter',
            'x.com'      => 'twitter',
            't.co'       => 'twitter',
            'linkedin.'  => 'linkedin',
            'telegram'   => 'telegram',
        ];
        foreach ($map as $needle => $label) {
            if (strpos($host, $needle) !== false) {
                return $label;
            }
        }
        return 'other';
    }

    public static function sourceLabel(string $code): string
    {
        return [
            'direct'   => '直接访问',
            'baidu'    => '百度',
            'google'   => 'Google',
            'bing'     => 'Bing',
            'sogou'    => '搜狗',
            '360'      => '360 搜索',
            'shenma'   => '神马',
            'weixin'   => '微信',
            'qq'       => 'QQ',
            'weibo'    => '微博',
            'zhihu'    => '知乎',
            'douyin'   => '抖音',
            'toutiao'  => '今日头条',
            'xhs'      => '小红书',
            'bilibili' => 'B 站',
            'facebook' => 'Facebook',
            'twitter'  => 'X',
            'linkedin' => 'LinkedIn',
            'telegram' => 'Telegram',
            'other'    => '其它网站',
        ][$code] ?? $code;
    }

    public static function deviceLabel(string $code): string
    {
        return ['desktop' => '电脑', 'mobile' => '手机', 'tablet' => '平板', 'bot' => '爬虫', 'unknown' => '未知'][$code] ?? $code;
    }
}
