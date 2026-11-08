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

    // ---------------- 用户管理 ----------------

    private const PAGE_SIZE = 20;

    public function users(array $params = []): Response
    {
        if (($r = $this->guard()) !== null) {
            return $r;
        }
        $req = new Request();
        $q = trim((string) $req->query('q', ''));
        $page = max(1, (int) $req->query('page', '1'));

        $where = '';
        $args = [];
        if ($q !== '') {
            $where = 'WHERE `u`.`email` LIKE ? OR `u`.`phone` LIKE ? OR `u`.`name` LIKE ?';
            $like = '%' . $q . '%';
            $args = [$like, $like, $like];
        }

        $total = (int) Db::val("SELECT COUNT(*) FROM `users` `u` $where", $args, 0);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PAGE_SIZE;

        $rows = Db::all(
            "SELECT `u`.*, COALESCE(`l`.`cnt`, 0) AS `link_count`
             FROM `users` `u`
             LEFT JOIN (SELECT `user_id`, COUNT(*) AS `cnt` FROM `links` GROUP BY `user_id`) `l`
               ON `l`.`user_id` = `u`.`id`
             $where
             ORDER BY `u`.`id` ASC
             LIMIT " . self::PAGE_SIZE . " OFFSET $offset",
            $args
        );

        return Response::html(View::render('admin/users', [
            'title'  => '用户',
            'nav'    => '',
            'active' => 'users',
            'rows'   => $rows,
            'q'      => $q,
            'page'   => $page,
            'pages'  => $pages,
            'total'  => $total,
            'meId'   => Auth::id(),
            'notice' => (string) $req->query('msg', ''),
            'error'  => (string) $req->query('err', ''),
        ]));
    }

    /** 用户操作：停用 / 启用 / 设管理员 / 取消管理员 / 重置密码。自己不能动自己 */
    public function userAction(array $params = []): Response
    {
        if (($r = $this->guard()) !== null) {
            return $r;
        }
        $req = new Request();
        $back = '/admin/users';
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back($back, '页面过期了，重试一次。', true);
        }

        $id = (int) $req->input('id', 0);
        $act = (string) $req->input('act', '');
        if ($id === Auth::id()) {
            return $this->back($back, '不能对自己做这个操作。', true);
        }
        $target = Db::one('SELECT * FROM `users` WHERE `id` = ?', [$id]);
        if ($target === null) {
            return $this->back($back, '用户不存在。', true);
        }

        switch ($act) {
            case 'disable':
                Db::exec("UPDATE `users` SET `status` = 'disabled', `updated_at` = ? WHERE `id` = ?", [now(), $id]);
                Db::exec('DELETE FROM `sessions` WHERE `user_id` = ?', [$id]);
                Db::log('admin.user', '停用用户 #' . $id . ' ' . Auth::identOf($target), ['by' => Auth::id()]);
                $msg = '已停用 ' . Auth::identOf($target) . '，对方所有登录已下线。';
                break;
            case 'enable':
                Db::exec("UPDATE `users` SET `status` = 'active', `updated_at` = ? WHERE `id` = ?", [now(), $id]);
                Db::log('admin.user', '启用用户 #' . $id . ' ' . Auth::identOf($target), ['by' => Auth::id()]);
                $msg = '已启用 ' . Auth::identOf($target) . '。';
                break;
            case 'promote':
                Db::exec("UPDATE `users` SET `role` = 'admin', `updated_at` = ? WHERE `id` = ?", [now(), $id]);
                Db::log('admin.user', '把 #' . $id . ' ' . Auth::identOf($target) . ' 设为管理员', ['by' => Auth::id()]);
                $msg = Auth::identOf($target) . ' 现在是管理员了。';
                break;
            case 'demote':
                Db::exec("UPDATE `users` SET `role` = 'user', `updated_at` = ? WHERE `id` = ?", [now(), $id]);
                Db::exec('DELETE FROM `sessions` WHERE `user_id` = ?', [$id]);
                Db::log('admin.user', '把 #' . $id . ' ' . Auth::identOf($target) . ' 降为普通用户', ['by' => Auth::id()]);
                $msg = Auth::identOf($target) . ' 已降为普通用户。';
                break;
            case 'resetpass':
                $pass = (string) $req->input('password', '');
                if (($problem = Auth::passwordProblem($pass)) !== '') {
                    return $this->back($back, '新密码不行：' . $problem, true);
                }
                Auth::changePassword($id, $pass);
                Db::log('admin.user', '重置了 #' . $id . ' ' . Auth::identOf($target) . ' 的密码', ['by' => Auth::id()]);
                $msg = '已重置 ' . Auth::identOf($target) . ' 的密码，对方所有登录已下线。';
                break;
            default:
                return $this->back($back, '未知操作。', true);
        }

        return $this->back($back, $msg);
    }

    // ---------------- 链接管理 ----------------

    public function links(array $params = []): Response
    {
        if (($r = $this->guard()) !== null) {
            return $r;
        }
        $req = new Request();
        $q = trim((string) $req->query('q', ''));
        $status = (string) $req->query('status', '');
        $page = max(1, (int) $req->query('page', '1'));

        $where = [];
        $args = [];
        if ($q !== '') {
            $where[] = '(`l`.`slug` LIKE ? OR `l`.`target_url` LIKE ? OR `l`.`title` LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like);
        }
        if ($status === 'active' || $status === 'disabled') {
            $where[] = '`l`.`status` = ?';
            $args[] = $status;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $total = (int) Db::val("SELECT COUNT(*) FROM `links` `l` $whereSql", $args, 0);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PAGE_SIZE;

        $rows = Db::all(
            "SELECT `l`.*, COALESCE(NULLIF(`u`.`email`, ''), NULLIF(`u`.`phone`, ''), CONCAT('用户#', `u`.`id`)) AS `owner`
             FROM `links` `l` LEFT JOIN `users` `u` ON `u`.`id` = `l`.`user_id`
             $whereSql
             ORDER BY `l`.`id` DESC
             LIMIT " . self::PAGE_SIZE . " OFFSET $offset",
            $args
        );

        return Response::html(View::render('admin/links', [
            'title'  => '短链',
            'nav'    => '',
            'active' => 'links',
            'rows'   => $rows,
            'q'      => $q,
            'status' => $status,
            'page'   => $page,
            'pages'  => $pages,
            'total'  => $total,
            'notice' => (string) $req->query('msg', ''),
            'error'  => (string) $req->query('err', ''),
        ]));
    }

    /** 链接操作：停用 / 启用 / 删除 */
    public function linkAction(array $params = []): Response
    {
        if (($r = $this->guard()) !== null) {
            return $r;
        }
        $req = new Request();
        $back = '/admin/links';
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back($back, '页面过期了，重试一次。', true);
        }

        $id = (int) $req->input('id', 0);
        $act = (string) $req->input('act', '');
        $link = Db::one('SELECT * FROM `links` WHERE `id` = ?', [$id]);
        if ($link === null) {
            return $this->back($back, '短链不存在。', true);
        }

        switch ($act) {
            case 'disable':
                Db::exec("UPDATE `links` SET `status` = 'disabled', `updated_at` = ? WHERE `id` = ?", [now(), $id]);
                Db::log('admin.link', '停用短链 #' . $id . ' /' . $link['slug'], ['by' => Auth::id()]);
                $msg = '已停用 /' . $link['slug'] . '，访问会显示已下线。';
                break;
            case 'enable':
                Db::exec("UPDATE `links` SET `status` = 'active', `updated_at` = ? WHERE `id` = ?", [now(), $id]);
                Db::log('admin.link', '启用短链 #' . $id . ' /' . $link['slug'], ['by' => Auth::id()]);
                $msg = '已启用 /' . $link['slug'] . '。';
                break;
            case 'delete':
                Db::exec('DELETE FROM `clicks` WHERE `link_id` = ?', [$id]);
                Db::exec('DELETE FROM `links` WHERE `id` = ?', [$id]);
                Db::log('admin.link', '删除短链 #' . $id . ' /' . $link['slug'], ['by' => Auth::id()]);
                $msg = '已删除 /' . $link['slug'] . ' 及其点击记录，不可恢复。';
                break;
            default:
                return $this->back($back, '未知操作。', true);
        }

        return $this->back($back, $msg);
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
