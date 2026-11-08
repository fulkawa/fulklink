<?php
/**
 * SMTP 发信，零依赖（只用 PHP 自带的 socket，不装 composer 包）。
 *
 * 为什么不用 PHPMailer：加密包里的第三方库要跟着加密，出问题排查成本高；
 * 我们的需求只有「发一封纯 HTML 通知信」，两百行足够。
 *
 * 配置优先读 settings 表（后台可改），读不到再用 .env。这样客户装完在后台
 * 填一下就能用，不用去服务器上改文件。
 */
declare(strict_types=1);

namespace App;

final class Mailer
{
    /** @return array{host:string,port:int,user:string,pass:string,from:string,fromName:string,secure:string} */
    public static function config(): array
    {
        $pick = static function (string $key, string $fallback): string {
            $v = Settings::get($key);
            return $v !== '' ? $v : $fallback;
        };

        $host = $pick('mail.host', (string) Config::get('mail.host', ''));
        $port = (int) $pick('mail.port', (string) Config::get('mail.port', '465'));
        $user = $pick('mail.user', (string) Config::get('mail.user', ''));
        $pass = $pick('mail.pass', (string) Config::get('mail.pass', ''));
        $from = $pick('mail.from', (string) Config::get('mail.from', ''));
        $name = $pick('mail.name', (string) Config::get('mail.name', (string) Config::get('app.name', 'FulkLink')));

        // secure 支持 ssl / tls / none。没填就按端口猜：465 走 ssl，其余先明文再 STARTTLS
        $secure = strtolower($pick('mail.secure', ''));
        if ($secure === '') {
            $secure = $port === 465 ? 'ssl' : ($port === 25 ? 'none' : 'tls');
        }

        return [
            'host'     => $host,
            'port'     => $port > 0 ? $port : 465,
            'user'     => $user,
            'pass'     => $pass,
            'from'     => $from !== '' ? $from : $user,
            'fromName' => $name,
            'secure'   => in_array($secure, ['ssl', 'tls', 'none'], true) ? $secure : 'ssl',
        ];
    }

    public static function ready(): bool
    {
        $c = self::config();
        return $c['host'] !== '' && $c['from'] !== '' && ($c['user'] === '' || $c['pass'] !== '');
    }

    /**
     * 发一封 HTML 邮件。失败抛 RuntimeException，消息面向站长而不是开发者。
     */
    public static function send(string $to, string $subject, string $html, string $text = ''): void
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('收件邮箱格式不对：' . $to);
        }
        $cfg = self::config();
        if ($cfg['host'] === '') {
            throw new \RuntimeException('还没配置 SMTP 服务器，后台「设置 → 邮件」里填一下。');
        }
        if ($cfg['from'] === '') {
            throw new \RuntimeException('还没配置发件邮箱。');
        }

        $fp = self::connect($cfg);
        try {
            self::handshake($fp, $cfg);
            self::command($fp, 'MAIL FROM:<' . $cfg['from'] . '>', [250]);
            self::command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($fp, 'DATA', [354]);
            fwrite($fp, self::compose($cfg, $to, $subject, $html, $text) . "\r\n.\r\n");
            self::expect($fp, [250]);
            self::command($fp, 'QUIT', [221], false);
        } finally {
            @fclose($fp);
        }
    }

    /** @return resource */
    private static function connect(array $cfg)
    {
        $scheme = $cfg['secure'] === 'ssl' ? 'ssl' : 'tcp';
        $ctx = stream_context_create([
            'ssl' => [
                // 邮件服务器用自签证书的不少，这里不校验证书链。
                // 风险有限：连接目标是站长自己填的服务器，不是用户可控输入。
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $scheme . '://' . $cfg['host'] . ':' . $cfg['port'],
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($fp === false) {
            throw new \RuntimeException(sprintf(
                '连不上 SMTP %s:%d（%s）。检查地址端口，以及服务器有没有封 25/465/587 出站。',
                $cfg['host'],
                $cfg['port'],
                $errstr !== '' ? $errstr : '错误码 ' . $errno
            ));
        }
        stream_set_timeout($fp, 15);
        return $fp;
    }

    private static function handshake($fp, array $cfg): void
    {
        self::expect($fp, [220]);
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'localhost';
        self::command($fp, 'EHLO ' . $host, [250]);

        if ($cfg['secure'] === 'tls') {
            self::command($fp, 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('SMTP 的 STARTTLS 握手失败，把加密方式改成 ssl 或 none 再试。');
            }
            self::command($fp, 'EHLO ' . $host, [250]);
        }

        if ($cfg['user'] === '') {
            return;
        }

        // 先试 AUTH LOGIN，不行再试 AUTH PLAIN —— 各家服务器支持的不一样
        fwrite($fp, "AUTH LOGIN\r\n");
        $line = self::readLine($fp);
        if (strpos($line, '334') === 0) {
            self::command($fp, base64_encode($cfg['user']), [334]);
            $resp = self::command($fp, base64_encode($cfg['pass']), [235]);
            if ($resp === '') {
                throw new \RuntimeException('SMTP 登录被拒绝，检查账号密码（很多邮箱要用「授权码」而不是登录密码）。');
            }
            return;
        }

        $plain = base64_encode("\0" . $cfg['user'] . "\0" . $cfg['pass']);
        $resp = self::command($fp, 'AUTH PLAIN ' . $plain, [235], false);
        if ($resp === '') {
            throw new \RuntimeException('SMTP 登录被拒绝，检查账号密码，或确认服务器允许发信。');
        }
    }

    /** 发命令并校验返回码；$required=true 时失败会抛异常 */
    private static function command($fp, string $line, array $okCodes, bool $required = true): string
    {
        fwrite($fp, $line . "\r\n");
        return self::expect($fp, $okCodes, $required);
    }

    private static function expect($fp, array $okCodes, bool $required = true): string
    {
        $line = self::readLine($fp);
        $code = (int) substr($line, 0, 3);
        if (in_array($code, $okCodes, true)) {
            return $line;
        }
        if (!$required) {
            return '';
        }
        throw new \RuntimeException('SMTP 返回 ' . trim($line) . '（期望 ' . implode('/', $okCodes) . '）');
    }

    /** 多行响应：250-xxx 继续，250 xxx 结束 */
    private static function readLine($fp): string
    {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ($out === '') {
            throw new \RuntimeException('SMTP 没有应答，可能是端口被防火墙拦了。');
        }
        return $out;
    }

    private static function compose(array $cfg, string $to, string $subject, string $html, string $text): string
    {
        $boundary = 'lp' . bin2hex(random_bytes(8));
        $fromName = self::encodeHeader($cfg['fromName']);
        $subjectEnc = self::encodeHeader($subject);

        $plain = $text !== '' ? $text : trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromName . ' <' . $cfg['from'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subjectEnc,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr((string) strrchr($cfg['from'], '@'), 1) . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: FulkLink',
        ];

        $body = [];
        $body[] = '--' . $boundary;
        $body[] = 'Content-Type: text/plain; charset=UTF-8';
        $body[] = 'Content-Transfer-Encoding: base64';
        $body[] = '';
        $body[] = chunk_split(base64_encode($plain), 76, "\r\n");
        $body[] = '--' . $boundary;
        $body[] = 'Content-Type: text/html; charset=UTF-8';
        $body[] = 'Content-Transfer-Encoding: base64';
        $body[] = '';
        $body[] = chunk_split(base64_encode($html), 76, "\r\n");
        $body[] = '--' . $boundary . '--';

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);
    }

    /** 中文标题必须 RFC2047 编码，否则一堆邮箱显示乱码 */
    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[\x80-\xFF]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    /**
     * 统一邮件外壳。客户站点发验证码、官网发授权码都用它，样式一致。
     *
     * @param array<int,string> $lines 正文段落（纯文本，内部会转义）
     */
    public static function shell(string $siteName, string $title, array $lines, string $code = '', string $footNote = ''): string
    {
        $site = e($siteName);
        $body = '';
        foreach ($lines as $line) {
            $body .= '<p style="margin:0 0 14px;font-size:14px;line-height:1.75;color:#3c4450">' . e($line) . '</p>';
        }
        $codeBox = '';
        if ($code !== '') {
            $codeBox = '<div style="margin:22px 0;padding:16px 20px;background:#f4f6fa;border-radius:8px;'
                . 'font:600 26px/1.2 ui-monospace,Menlo,Consolas,monospace;letter-spacing:6px;color:#1b2330;text-align:center">'
                . e($code) . '</div>';
        }
        $foot = $footNote !== ''
            ? '<p style="margin:22px 0 0;font-size:12px;line-height:1.7;color:#8b95a5">' . e($footNote) . '</p>'
            : '';

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . e($title) . '</title></head>'
            . '<body style="margin:0;padding:28px 12px;background:#eef1f6">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;margin:0 auto;'
            . 'background:#fff;border-radius:10px;border:1px solid #e3e8f0">'
            . '<tr><td style="padding:28px 30px 8px;font:600 15px/1.4 system-ui,-apple-system,\'Segoe UI\',sans-serif;color:#1b2330">'
            . $site . '</td></tr>'
            . '<tr><td style="padding:8px 30px 0"><div style="font:600 19px/1.4 system-ui,sans-serif;color:#1b2330;margin-bottom:16px">'
            . e($title) . '</div>' . $body . $codeBox . $foot . '</td></tr>'
            . '<tr><td style="padding:22px 30px 28px;border-top:1px solid #eef1f6;font:12px/1.6 system-ui,sans-serif;color:#8b95a5">'
            . '这封信由系统自动发出，不用回复。'
            . '</td></tr></table></body></html>';
    }
}
