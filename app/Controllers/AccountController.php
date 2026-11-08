<?php
/**
 * 【开源版专用】个人中心。
 *
 * 商业版的个人中心承担授权、订单、实名、人脸核身四件事；开源版没有这些，
 * 只保留账号信息与改密。路由表里也只注册了这两条。
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Request;
use App\Response;
use App\View;

final class AccountController
{
    public function home(array $params = []): Response
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->login();
        }

        $req = new Request();

        return Response::html(View::render('account/home', [
            'title'  => '账号',
            'nav'    => '',
            'user'   => $user,
            'notice' => (string) $req->query('msg', ''),
            'error'  => (string) $req->query('err', ''),
        ]));
    }

    public function changePassword(array $params = []): Response
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->login();
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return Response::redirect(path('/account') . '?msg=' . rawurlencode('页面过期了，重试一次。'), 302);
        }

        $old = (string) $req->post('old_password', '');
        $new = (string) $req->post('new_password', '');
        $new2 = (string) $req->post('new_password2', '');

        if (!password_verify($old, (string) $user['password_hash'])) {
            return Response::redirect(path('/account') . '?msg=' . rawurlencode('原密码不对。'), 302);
        }
        if ($new !== $new2) {
            return Response::redirect(path('/account') . '?msg=' . rawurlencode('两次新密码不一样。'), 302);
        }
        $problem = Auth::passwordProblem($new);
        if ($problem !== '') {
            return Response::redirect(path('/account') . '?msg=' . rawurlencode($problem), 302);
        }

        Auth::changePassword((int) $user['id'], $new);

        return Response::redirect(path('/login') . '?msg=' . rawurlencode('密码改好了，重新登录一次。'), 302);
    }

    private function login(): Response
    {
        $next = (string) ($_SERVER['REQUEST_URI'] ?? '/account');
        return Response::redirect(path('/login') . '?next=' . rawurlencode(AuthController::safeNext($next, '/account')), 302);
    }
}
