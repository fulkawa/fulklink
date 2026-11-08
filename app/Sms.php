<?php
/**
 * 短信验证码发送。
 *
 * 当前支持：
 *   aliyun  阿里云短信（Dysmsapi 2017-05-25，RPC 风格 HMAC-SHA1 签名）
 *   log     不发，把验证码写进 data/sms.log —— 没买短信服务时的调试模式
 *
 * 加新服务商只要在 send() 里加一个分支，Config 读同一个 sms.* 命名空间。
 */
declare(strict_types=1);

namespace App;

final class Sms
{
    /** @return array{provider:string,key:string,secret:string,sign:string,template:string,region:string} */
    public static function config(): array
    {
        $pick = static function (string $key, string $fallback = ''): string {
            $v = Settings::get($key);
            return $v !== '' ? $v : $fallback;
        };

        $provider = strtolower($pick('sms.provider', 'log'));
        return [
            'provider' => in_array($provider, ['aliyun', 'log'], true) ? $provider : 'log',
            'key'      => $pick('sms.aliyun.key'),
            'secret'   => $pick('sms.aliyun.secret'),
            'sign'     => $pick('sms.aliyun.sign'),
            'template' => $pick('sms.aliyun.template'),
            'region'   => $pick('sms.aliyun.region', 'cn-hangzhou'),
        ];
    }

    /** 配置齐了没有。log 模式永远算「可用」，但界面要提示这是调试模式 */
    public static function ready(): bool
    {
        $c = self::config();
        if ($c['provider'] === 'log') {
            return true;
        }
        return $c['key'] !== '' && $c['secret'] !== '' && $c['sign'] !== '' && $c['template'] !== '';
    }

    public static function isDebugMode(): bool
    {
        return self::config()['provider'] === 'log';
    }

    /** 中国大陆手机号。不接国际号，规则简单点，错了提示也明确 */
    public static function validPhone(string $phone): bool
    {
        return (bool) preg_match('/^1[3-9]\d{9}$/', preg_replace('/\D/', '', $phone) ?? '');
    }

    public static function normalize(string $phone): string
    {
        $p = preg_replace('/\D/', '', $phone) ?? '';
        // 去掉 86 前缀
        if (strlen($p) === 13 && strpos($p, '86') === 0) {
            $p = substr($p, 2);
        }
        return $p;
    }

    /** 发验证码。失败抛 RuntimeException */
    public static function send(string $phone, string $code): void
    {
        $phone = self::normalize($phone);
        if (!self::validPhone($phone)) {
            throw new \RuntimeException('手机号格式不对，填 11 位手机号。');
        }
        $c = self::config();

        if ($c['provider'] === 'log') {
            self::writeLog($phone, $code);
            return;
        }

        if (!self::ready()) {
            throw new \RuntimeException('短信服务没配置完整，后台「设置 → 短信」里填 AccessKey、签名和模板编号。');
        }

        self::sendAliyun($phone, $code, $c);
    }

    private static function sendAliyun(string $phone, string $code, array $c): void
    {
        $params = [
            'AccessKeyId'      => $c['key'],
            'Action'           => 'SendSms',
            'Format'           => 'JSON',
            'PhoneNumbers'     => $phone,
            'RegionId'         => $c['region'] !== '' ? $c['region'] : 'cn-hangzhou',
            'SignName'         => $c['sign'],
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => bin2hex(random_bytes(12)),
            'SignatureVersion' => '1.0',
            'TemplateCode'     => $c['template'],
            'TemplateParam'    => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'Version'          => '2017-05-25',
        ];
        $params['Signature'] = self::signAliyun($params, $c['secret']);

        $url = 'https://dysmsapi.aliyuncs.com/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $res = Http::send($url, '', ['Accept: application/json'], 15);

        if ($res['error'] !== '') {
            Db::log('sms.fail', '短信接口连不上：' . $res['error'], ['phone' => mask_middle($phone, 3, 4)], 'error');
            throw new \RuntimeException('连不上短信服务：' . $res['error']);
        }

        $json = json_decode($res['body'], true);
        $ok = is_array($json) && strtoupper((string) ($json['Code'] ?? '')) === 'OK';
        if (!$ok) {
            $msg = is_array($json) ? (string) ($json['Message'] ?? $res['body']) : $res['body'];
            $code2 = is_array($json) ? (string) ($json['Code'] ?? '') : '';
            Db::log('sms.fail', '短信发送失败 ' . $code2, [
                'phone' => mask_middle($phone, 3, 4), 'message' => mb_substr($msg, 0, 200),
            ], 'error');
            throw new \RuntimeException(self::explain($code2, $msg));
        }

        Db::log('sms.ok', '验证码已发送', ['phone' => mask_middle($phone, 3, 4)]);
    }

    /** 把阿里云错误码翻成人话，站长看得懂才改得动 */
    private static function explain(string $code, string $raw): string
    {
        $map = [
            'isv.BUSINESS_LIMIT_CONTROL' => '这个号码发得太频繁，等一分钟再试。',
            'isv.DAY_LIMIT_CONTROL'      => '这个号码今天收的验证码太多了，明天再试。',
            'isv.SMS_SIGNATURE_ILLEGAL'  => '短信签名没通过审核，去阿里云控制台换一个已审核的签名。',
            'isv.SMS_TEMPLATE_ILLEGAL'   => '短信模板没通过审核，或者模板编号填错了。',
            'isv.MOBILE_NUMBER_ILLEGAL'  => '这个手机号在运营商那边无效。',
            'isv.AMOUNT_NOT_ENOUGH'      => '短信余额不足，去阿里云充值。',
            'SignatureDoesNotMatch'      => 'AccessKey 或 Secret 填错了。',
            'InvalidAccessKeyId.NotFound' => 'AccessKey 不存在，检查是不是复制少了字符。',
        ];
        if (isset($map[$code])) {
            return $map[$code];
        }
        return '短信没发出去（' . ($code !== '' ? $code : '未知错误') . '）：' . $raw;
    }

    /** 阿里云 RPC 签名：percentEncode → 排序拼接 → HMAC-SHA1(secret + "&") */
    private static function signAliyun(array $params, string $secret): string
    {
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = self::percentEncode((string) $k) . '=' . self::percentEncode((string) $v);
        }
        $canonical = implode('&', $pairs);
        $stringToSign = 'GET&%2F&' . self::percentEncode($canonical);
        return base64_encode(hash_hmac('sha1', $stringToSign, $secret . '&', true));
    }

    private static function percentEncode(string $value): string
    {
        $out = urlencode($value);
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], $out);
    }

    /** 调试模式：写文件而不是发出去，方便没买短信时把流程跑完 */
    private static function writeLog(string $phone, string $code): void
    {
        $file = BASE_PATH . '/data/sms.log';
        $line = sprintf("[%s] %s code=%s ip=%s\n", now(), $phone, $code, (string) ($_SERVER['REMOTE_ADDR'] ?? '-'));
        @file_put_contents($file, $line, FILE_APPEND);
        @chmod($file, 0600);
        Db::log('sms.log', '短信服务未配置，验证码写入 data/sms.log', ['phone' => mask_middle($phone, 3, 4)], 'warn');
    }
}
