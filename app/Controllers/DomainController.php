<?php
/**
 * 域名管理。只有工作区所有者能进——成员改域名等于改整个团队的出口，权限太大。
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Cloud\Gate;
use App\Domains;
use App\Request;
use App\Response;
use App\Shorten;
use App\View;
use App\Workspace;

final class DomainController
{
    public function index(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $user = Auth::user();
        if (!Workspace::isOwner($user)) {
            return $this->back('/links', '域名只有工作区所有者能管理。', true);
        }
        $ownerId = Workspace::ownerId($user);
        $req = new Request();

        return Response::html(View::render('domains/index', [
            'title'   => '域名',
            'nav'     => '',
            'user'    => $user,
            'domains' => Domains::listFor($ownerId),
            'primary' => Shorten::defaultHost(),
            'siteUrl' => url(''),
            'used'    => Workspace::used($ownerId),
            'limit'   => Workspace::limit('domains'),
            'canAdd'  => Gate::allows('domains.custom'),
            'unlimited' => Gate::allows('domains.unlimited'),
            'reason'  => Gate::reason('domains.custom'),
            'msg'     => $req->str('msg'),
            'err'     => $req->str('err'),
            'banner'  => Gate::banner(),
        ]));
    }

    public function add(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back('/domains', '页面过期了，重试一次。', true);
        }
        $user = Auth::user();
        if (!Workspace::isOwner($user)) {
            return $this->back('/links', '域名只有工作区所有者能管理。', true);
        }

        $res = Domains::add(Workspace::ownerId($user), $req->str('host'));
        if (!$res['ok']) {
            return $this->back('/domains', $res['message'], true);
        }
        return $this->back('/domains', '加好了。按页面上的 TXT 记录去解析，生效后点「验证」。');
    }

    public function verify(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back('/domains', '页面过期了，重试一次。', true);
        }
        $user = Auth::user();
        if (!Workspace::isOwner($user)) {
            return $this->back('/links', '域名只有工作区所有者能管理。', true);
        }

        $res = Domains::verify(Workspace::ownerId($user), (int) ($params['id'] ?? 0));
        if (!$res['ok']) {
            $extra = $res['records'] === []
                ? '（服务器没查到任何 TXT，可能还没生效，也可能解析还没加）'
                : '（当前查到：' . implode(' / ', array_slice($res['records'], 0, 3)) . '）';
            return $this->back('/domains', $res['message'] . $extra, true);
        }
        return $this->back('/domains', $res['message']);
    }

    public function remove(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back('/domains', '页面过期了，重试一次。', true);
        }
        $user = Auth::user();
        if (!Workspace::isOwner($user)) {
            return $this->back('/links', '域名只有工作区所有者能管理。', true);
        }
        $res = Domains::remove(Workspace::ownerId($user), (int) ($params['id'] ?? 0));
        return $this->back('/domains', $res['message'], !$res['ok']);
    }

    private function need(): ?Response
    {
        if (Auth::user() === null) {
            $next = (new Request())->path();
            return Response::redirect(path('/login') . '?next=' . rawurlencode($next), 302);
        }
        return null;
    }

    private function back(string $path, string $message, bool $isError = false): Response
    {
        $key = $isError ? 'err' : 'msg';
        return Response::redirect(path($path) . '?' . $key . '=' . rawurlencode($message), 302);
    }
}
