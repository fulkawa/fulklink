<?php
/**
 * 跳转入口。整条产品线最不能出错的一段：它一挂，客户发出去的链接全变死链。
 *
 * 所以这里刻意「什么都不检查」：
 *   - 不看授权、不看云端、不读远程资源；
 *   - 数据库查不到就 404，不抛异常；
 *   - 统计写失败也照跳（LinkStats::record 自己吞了异常）。
 *
 * 访问路径有两条：
 *   /r/{slug}            任何域名下都能用，主域名默认走这条
 *   /{slug}              只在「验证过的自定义域名」下启用，短地址更短
 *
 * 第二条要小心：主域名下的 /pricing、/docs 这些不能被当成 slug 吞掉。
 * 判断依据是请求的 Host 必须是一条 verified 的 user_domains 记录，否则一律 404。
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Domains;
use App\Request;
use App\Response;
use App\Shorten;
use App\Ua;
use App\View;
use App\LinkStats;

final class GoController
{
    /** /r/{slug} */
    public function r(array $params = []): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        $link = Shorten::bySlug($slug, '');
        if ($link === null) {
            // 主域名上没找到，再看是不是挂在自定义域名下的同名后缀
            $host = (new Request())->host();
            if ($host !== '' && $host !== Shorten::defaultHost()) {
                $link = Shorten::bySlug($slug, $host);
            }
        }
        if ($link === null) {
            return $this->notFound('这个短链不存在，或者已经被删除。');
        }
        return $this->dispatch($link);
    }

    /** /{slug}，只对验证过的自定义域名生效 */
    public function root(array $params = []): Response
    {
        $req = new Request();
        $host = $req->host();
        $slug = (string) ($params['slug'] ?? '');

        if ($host === '' || $host === Shorten::defaultHost()) {
            return $this->notFound('页面不存在。');
        }
        if (Domains::verifiedHost($host) === null) {
            return $this->notFound('这个域名还没在本站验证，短链不可用。');
        }

        $link = Shorten::bySlug($slug, $host);
        if ($link === null) {
            return $this->notFound('这个短链不存在，或者已经被删除。');
        }
        return $this->dispatch($link);
    }

    /** 提交访问密码 */
    public function unlock(array $params = []): Response
    {
        $req = new Request();
        $slug = (string) ($params['slug'] ?? '');
        $link = Shorten::bySlug($slug, '');
        if ($link === null) {
            $host = $req->host();
            if ($host !== '' && $host !== Shorten::defaultHost()) {
                $link = Shorten::bySlug($slug, $host);
            }
        }
        if ($link === null) {
            return $this->notFound('这个短链不存在。');
        }
        if (!Shorten::hasPassword($link)) {
            return $this->dispatch($link);
        }

        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->passwordPage($link, '页面过期了，重新输一次。');
        }

        if ($this->tooManyFails((int) $link['id'], $req->ip())) {
            return $this->passwordPage($link, '密码错太多次了，等 15 分钟再试。');
        }

        $password = (string) $req->post('password', '');
        if ($password === '' || !password_verify($password, (string) $link['password_hash'])) {
            $this->recordFail((int) $link['id'], $req->ip());
            return $this->passwordPage($link, '密码不对。');
        }

        Shorten::markUnlocked($link);

        // 解锁后回到同一个地址，浏览器再取就是跳转
        $base = (string) $link['domain'] !== '' && $req->host() === (string) $link['domain']
            ? '/' . $link['slug']
            : '/r/' . $link['slug'];
        return Response::redirect($base, 302)->withHeader('Cache-Control', 'no-store');
    }

    // ---------------- 内部 ----------------

    private function dispatch(array $link): Response
    {
        $state = Shorten::state($link);
        if ($state === 'disabled') {
            return $this->notice('链接已停用', '这条短链被创建者停用了，暂时不能访问。');
        }
        if ($state === 'expired') {
            return $this->notice('链接已过期', '这条短链的有效期到了。如果需要继续使用，请联系发链接的人。');
        }
        if ($state === 'capped') {
            return $this->notice('链接访问次数已满', '这条短链设置的访问上限已经用完。');
        }

        if (Shorten::hasPassword($link) && !Shorten::unlocked($link)) {
            return $this->passwordPage($link, '');
        }

        $target = (string) $link['target_url'];
        if (($blocked = Shorten::blockedReason($target)) !== '') {
            return $this->notice('链接已被拦截', $blocked . ' 如果这是你的链接，请检查目标地址。');
        }

        try {
            LinkStats::record($link, new Request(), Ua::sourceOf((new Request())->header('Referer')));
        } catch (\Throwable $e) {
            // 统计失败不影响跳转
        }

        return Response::redirect($target, 302)
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function passwordPage(array $link, string $error): Response
    {
        return new Response(View::render('go/password', [
            'title'   => '需要访问密码',
            'nav'     => '',
            'link'    => $link,
            'error'   => $error,
            'action'  => '/r/' . $link['slug'],
        ]), 200);
    }

    private function notice(string $title, string $message): Response
    {
        return new Response(View::render('go/notice', [
            'title'   => $title,
            'nav'     => '',
            'heading' => $title,
            'message' => $message,
        ]), 410);
    }

    private function notFound(string $message): Response
    {
        return new Response(View::render('go/notice', [
            'title'   => '链接不存在',
            'nav'     => '',
            'heading' => '找不到这个短链',
            'message' => $message,
            'home'    => true,
        ]), 404);
    }

    private function recordFail(int $linkId, string $ip): void
    {
        try {
            Db::insert('events', [
                'level'      => 'warn',
                'kind'       => 'link.pwfail',
                'message'    => $linkId . '|' . $ip,
                'meta'       => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private function tooManyFails(int $linkId, string $ip): bool
    {
        try {
            $n = (int) Db::val(
                'SELECT COUNT(*) FROM `events` WHERE `kind` = ? AND `message` = ? AND `created_at` >= ?',
                ['link.pwfail', $linkId . '|' . $ip, date('Y-m-d H:i:s', time() - 900)],
                0
            );
        } catch (\Throwable $e) {
            return false;
        }
        return $n >= 10;
    }
}
