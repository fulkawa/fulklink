<?php
/**
 * 【开源版专用】后台。
 *
 * 只有概览与配置两页：这个站是自己用的短链工具，不需要管订单、授权、
 * 实名审核、云端资源和发版。商业版那些方法一个都不留——连带引用它们的
 * 类（Rsa / License / Verify / Cloud\Client）也不在包里。
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Mailer;
use App\Request;
use App\Response;
use App\Settings;
use App\Sms;
use App\View;

final class AdminController
{
    /** 开源版可改的配置项。左边是表单字段名，右边是 settings 的键 */
    private const FIELDS = [
        'mail_host'    => 'mail.host',
        'mail_port'    => 'mail.port',
        'mail_secure'  => 'mail.secure',
        'mail_user'    => 'mail.user',
        'mail_pass'    => 'mail.pass',
        'mail_from'    => 'mail.from',
        'mail_name'    => 'mail.name',
        'sms_provider' => 'sms.provider',
        'sms_key'      => 'sms.aliyun.key',
        'sms_secret'   => 'sms.aliyun.secret',
        'sms_sign'     => 'sms.aliyun.sign',
        'sms_template' => 'sms.aliyun.template',
        'sms_region'   => 'sms.aliyun.region',
        'allowed_hosts' => 'link.allowed_hosts',
    ];

    /** 这些留空表示「不修改」，不是「清空」 */
    private const KEEP_IF_EMPTY = ['mail_pass', 'sms_key', 'sms_secret'];

    public function home(array $params = []): Response
    {
        if (($r = $this->guard()) !== null) {
            return $r;
        }

        $counts = [
            'users'  => (int) Db::val('SELECT COUNT(*) FROM `users`', [], 0),
            'links'  => (int) Db::val('SELECT COUNT(*) FROM `links`', [], 0),
            'clicks' => (int) Db::val('SELECT COUNT(*) FROM `clicks`', [], 0),
            'today'  => (int) Db::val('SELECT COUNT(*) FROM `clicks` WHERE `day` = ?', [date('Y-m-d')], 0),
        ];

        return Response::html(View::render('admin/home', [
            'title'  => '后台',
            'nav'    => '',
            'active' => 'home',
            'counts' => $counts,
            'recent' => Db::all('SELECT * FROM `links` ORDER BY `id` DESC LIMIT 8'),
            'mailOk' => Mailer::ready(),
            'smsOk'  => Sms::ready(),
            'events' => Db::all('SELECT * FROM `events` ORDER BY `id` DESC LIMIT 10'),
        ]));
    }

    public function settings(array $params = []): Response
    {
        if (($r = $this->guard()) !== null) {
            return $r;
        }
        $req = new Request();
        $vals = [];
        foreach (self::FIELDS as $field => $key) {
            $vals[$field] = Settings::get($key);
        }

        return Response::html(View::render('admin/settings', [
            'title'    => '设置',
            'nav'      => '',
            'active'   => 'settings',
            'v'        => $vals,
            'notice'   => (string) $req->query('msg', ''),
            'error'    => (string) $req->query('err', ''),
            'mailOk'   => Mailer::ready(),
            'smsOk'    => Sms::ready(),
            'smsDebug' => Sms::isDebugMode(),
        ]));
    }

    public function saveSettings(array $params = []): Response
    {
        if (($r = $this->guard()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back('/admin/settings', '页面过期了，重试一次。', true);
        }

        $saved = 0;
        foreach (self::FIELDS as $field => $key) {
            $raw = $req->input($field, null);
            if ($raw === null) {
                continue;
            }
            $value = trim((string) $raw);
            if ($value === '' && in_array($field, self::KEEP_IF_EMPTY, true)) {
                continue;
            }
            try {
                Settings::set($key, $value);
                $saved++;
            } catch (\Throwable $e) {
                return $this->back('/admin/settings', '保存失败：' . $e->getMessage(), true);
            }
        }
        Settings::flush();
        Db::log('admin.settings', '更新配置 ' . $saved . ' 项', ['by' => Auth::id()]);

        return $this->back('/admin/settings', '已保存 ' . $saved . ' 项。');
    }

    /** 发一封测试信，把 SMTP 的真实返回摆出来 */
    public function mailTest(array $params = []): Response
    {
        if (($r = $this->guard()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back('/admin/settings', '页面过期了。', true);
        }
        $to = $req->str('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->back('/admin/settings', '收件邮箱填错了。', true);
        }
        try {
            Mailer::send(
                $to,
                '这是一封测试信',
                Mailer::shell((string) cfg('app.name'), 'SMTP 配置能用', [
                    '收到这封信，说明发信配置是对的。',
                    '注册验证码、找回密码都会走这条通道。',
                ], '', '发信时间：' . now())
            );
        } catch (\Throwable $e) {
            return $this->back('/admin/settings', '发信失败：' . $e->getMessage(), true);
        }

        return $this->back('/admin/settings', '测试信已发到 ' . $to . '，没收到先看垃圾箱。');
    }

    private function guard(): ?Response
    {
        $user = Auth::user();
        if ($user === null) {
            return Response::redirect(path('/login') . '?next=' . rawurlencode('/admin'), 302);
        }
        if ((string) $user['role'] !== 'admin') {
            return new Response(View::render('error', [
                'title'  => '没有权限',
                'code'   => 403,
                'reason' => '这个页面只给管理员用。',
            ]), 403);
        }
        return null;
    }

    private function back(string $path, string $message, bool $isError = false): Response
    {
        $key = $isError ? 'err' : 'msg';
        return Response::redirect(path($path) . '?' . $key . '=' . rawurlencode($message), 302);
    }
}
