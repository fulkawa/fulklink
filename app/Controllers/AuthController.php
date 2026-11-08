<?php
/**
 * 注册、登录、登出、发验证码。
 *
 * 注册允许两种方式，前端用 tab 切换，后端只认 target 里有没有 @：
 *   有 @  → 邮箱注册，验证码走 SMTP
 *   没有  → 手机注册，验证码走短信
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Captcha;
use App\Mailer;
use App\Request;
use App\Response;
use App\Sms;
use App\View;

final class AuthController
{
    public function registerForm(array $params = []): Response
    {
        if (Auth::user() !== null) {
            return Response::redirect(path(self::home()), 302);
        }
        return $this->renderRegister([], '');
    }

    public function register(array $params = []): Response
    {
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->renderRegister($this->formValues($req), '页面放了太久，重新提交一次。');
        }

        $mode = $req->str('mode') === 'phone' ? 'phone' : 'email';
        $target = $mode === 'phone' ? Sms::normalize($req->str('phone')) : strtolower($req->str('email'));
        $code = $req->str('code');
        $password = (string) $req->post('password', '');
        $password2 = (string) $req->post('password2', '');
        $agree = $req->str('agree');

        $old = $this->formValues($req);

        if ($target === '') {
            return $this->renderRegister($old, $mode === 'phone' ? '填一下手机号。' : '填一下邮箱。');
        }
        if ($agree !== '1') {
            return $this->renderRegister($old, '要先同意服务条款和隐私说明。');
        }
        if ($password !== $password2) {
            return $this->renderRegister($old, '两次输入的密码不一样。');
        }
        if (Auth::findByIdent($target) !== null) {
            return $this->renderRegister($old, '这个' . ($mode === 'phone' ? '手机号' : '邮箱') . '已经注册过了，直接登录。');
        }

        $check = Captcha::verify($target, 'register', $code);
        if (!$check['ok']) {
            return $this->renderRegister($old, $check['message']);
        }

        $res = Auth::register($target, $password, $req->ip());
        if (!$res['ok']) {
            return $this->renderRegister($old, $res['message']);
        }

        Auth::login($res['user']);
        // 客户站注册完直接进短链工作台，官网则去控制台看授权
        $to = self::home();
        return Response::redirect(path($to) . ($to === '/account' ? '?welcome=1' : ''), 302);
    }

    public function loginForm(array $params = []): Response
    {
        if (Auth::user() !== null) {
            return Response::redirect(path(self::home()), 302);
        }
        return $this->renderLogin('', '', (string) (new Request())->query('next', ''));
    }

    public function login(array $params = []): Response
    {
        $req = new Request();
        $next = (string) $req->post('next', '');
        $ident = $req->str('ident');

        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->renderLogin($ident, '页面放了太久，重新提交一次。', $next);
        }

        $res = Auth::attempt($ident, (string) $req->post('password', ''));
        if (!$res['ok']) {
            return $this->renderLogin($ident, $res['message'], $next);
        }

        Auth::login($res['user']);
        return Response::redirect(self::safeNext($next), 302);
    }

    public function logout(array $params = []): Response
    {
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return Response::redirect(path('/'), 302);
        }
        Auth::logout();
        return Response::redirect(path('/'), 302);
    }

    /**
     * 忘记密码。一个页面搞定：账号 + 验证码 + 新密码。
     * 验证码本身就是「账号归属」凭证，不需要再签一次性 reset token。
     * 重置成功沿用 changePassword：改 hash 的同时踢掉所有已登录会话。
     */
    public function forgotForm(array $params = []): Response
    {
        return Response::html(View::render('auth/forgot', [
            'title' => '忘记密码',
            'nav'   => '',
            'ident' => '',
            'error' => '',
        ]));
    }

    public function forgot(array $params = []): Response
    {
        $req = new Request();
        $ident = Auth::normalizeIdent($req->str('ident'));
        $password = (string) $req->post('password', '');
        $password2 = (string) $req->post('password2', '');
        $code = $req->str('code');

        $back = function (string $msg) use ($ident): Response {
            return Response::html(View::render('auth/forgot', [
                'title' => '忘记密码',
                'nav'   => '',
                'ident' => $ident,
                'error' => $msg,
            ]));
        };

        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $back('页面放了太久，重新提交一次。');
        }
        if ($ident === '') {
            return $back('填一下注册时的邮箱或手机号。');
        }
        if ($password !== $password2) {
            return $back('两次输入的新密码不一样。');
        }
        if (($problem = Auth::passwordProblem($password)) !== '') {
            return $back($problem);
        }

        $user = Auth::findByIdent($ident);
        // 用户不存在也走一次验证码校验，耗时与存在时接近，不让接口被拿来探测账号
        $check = Captcha::verify($ident, 'reset', $code);
        if ($user === null || ($user['status'] ?? '') !== 'active') {
            return $back($user === null ? '账号或验证码不对。' : '这个账号被停用了，联系站点管理员。');
        }
        if (!$check['ok']) {
            return $back($check['message']);
        }

        Auth::changePassword((int) $user['id'], $password);
        \App\Db::log('auth.reset', '密码重置 ' . $ident, ['user_id' => (int) $user['id'], 'ip' => $req->ip()], 'warn');

        return Response::redirect(path('/login?msg=') . rawurlencode('密码已经改好了，用新密码登录。'), 302);
    }


    /**
     * 发验证码。前端用 fetch 调，返回 JSON。
     * 也接受普通表单提交（没有 JS 时降级），此时跳回来源页。
     */
    public function sendCode(array $params = []): Response
    {
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return Response::json(['ok' => false, 'message' => '页面过期了，刷新一下再试。'], 419);
        }

        $target = trim($req->str('target'));
        $purpose = $req->str('purpose', 'register');
        if (!in_array($purpose, Captcha::PURPOSES, true)) {
            $purpose = 'register';
        }
        $isEmail = strpos($target, '@') !== false;

        if ($target === '') {
            return Response::json(['ok' => false, 'message' => '先填' . ($isEmail ? '邮箱' : '手机号') . '。'], 400);
        }
        if ($isEmail && !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['ok' => false, 'message' => '邮箱格式不对。'], 400);
        }
        if (!$isEmail && !Sms::validPhone(Sms::normalize($target))) {
            return Response::json(['ok' => false, 'message' => '手机号填 11 位。'], 400);
        }
        if ($purpose === 'register' && Auth::findByIdent($target) !== null) {
            return Response::json(['ok' => false, 'message' => '这个账号已经注册过了，直接登录。'], 409);
        }
        if ($purpose === 'reset') {
            $user = Auth::findByIdent($target);
            // 不存在的账号直接拒绝，省一条短信/邮件；账号枚举风险已被注册接口的同款提示覆盖
            if ($user === null) {
                return Response::json(['ok' => false, 'message' => '这个账号没注册过，先去注册。'], 404);
            }
            if (($user['status'] ?? '') !== 'active') {
                return Response::json(['ok' => false, 'message' => '这个账号被停用了，联系站点管理员。'], 403);
            }
        }

        // 先把「能不能发」确认掉，再生成验证码。
        // 反过来的话，配置缺失时码已经入库、频控已经计数，用户还白等 60 秒。
        if ($isEmail && !Mailer::ready()) {
            return Response::json([
                'ok' => false,
                'message' => '邮件服务还没配好，先用手机号注册，或联系客服。',
            ], 503);
        }
        if (!$isEmail && !Sms::ready()) {
            return Response::json([
                'ok' => false,
                'message' => '短信服务还没配置完整，联系客服处理。',
            ], 503);
        }

        try {
            $code = Captcha::issue($target, $isEmail ? 'email' : 'sms', $purpose, $req->ip());
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()], 429);
        }

        $siteName = (string) cfg('app.name', 'FulkLink');

        try {
            if ($isEmail) {
                if ($purpose === 'reset') {
                    Mailer::send(
                        $target,
                        '重置密码验证码 ' . $code,
                        Mailer::shell($siteName, '验证码', [
                            '你正在重置 ' . $siteName . ' 账号的登录密码，验证码是：',
                            '10 分钟内有效，只能用一次。',
                        ], $code, '不是你本人操作就忽略这封信，密码不会改变。')
                    );
                } else {
                    Mailer::send(
                        $target,
                        '注册验证码 ' . $code,
                        Mailer::shell($siteName, '验证码', [
                            '你正在注册 ' . $siteName . ' 账号，验证码是：',
                            '10 分钟内有效，只能用来注册一次。',
                        ], $code, '不是你本人操作就忽略这封信，账号不会被创建。')
                    );
                }
            } else {
                Sms::send($target, $code);
            }
        } catch (\RuntimeException $e) {
            // 没发出去就把码作废，用户可以立刻重试
            Captcha::discard($target, $purpose, $code);
            return Response::json(['ok' => false, 'message' => $e->getMessage()], 502);
        }

        $msg = $isEmail ? '验证码已发到邮箱，10 分钟内有效。' : '验证码已发送，10 分钟内有效。';
        if (!$isEmail && Sms::isDebugMode()) {
            $msg .= '（当前是调试模式，验证码写在服务器 data/sms.log）';
        }
        return Response::json(['ok' => true, 'message' => $msg]);
    }

    // ---------------- 内部 ----------------

    private function renderRegister(array $old, string $error): Response
    {
        return Response::html(View::render('auth/register', [
            'title' => '注册',
            'nav'   => '',
            'old'   => $old + ['mode' => 'email', 'email' => '', 'phone' => '', 'code' => ''],
            'error' => $error,
        ]));
    }

    private function renderLogin(string $ident, string $error, string $next = ''): Response
    {
        return Response::html(View::render('auth/login', [
            'title' => '登录',
            'nav'   => '',
            'ident' => $ident,
            'error' => $error,
            'next'  => self::safeNext($next, ''),
        ]));
    }

    private function formValues(Request $req): array
    {
        return [
            'mode'  => $req->str('mode') === 'phone' ? 'phone' : 'email',
            'email' => $req->str('email'),
            'phone' => $req->str('phone'),
            'code'  => '',
        ];
    }

    /** 登录后的落脚页。客户站进短链，官网进控制台 */
    private static function home(): string
    {
        return \App\Cloud\Mode::isClient() ? '/links' : '/account';
    }

    /** 只允许跳回站内路径，挡开放重定向 */
    public static function safeNext(string $next, ?string $default = null): string
    {
        if ($default === null) {
            $default = self::home();
        }
        $next = trim($next);
        if ($next === '' || $next[0] !== '/' || strpos($next, '//') === 0 || strpos($next, '\\') !== false) {
            return path($default);
        }
        return $next;
    }
}
