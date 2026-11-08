<?php
/**
 * 【开源版专用】路由表。
 *
 * 只注册开源版真实存在的入口。商业版的路由（下单、支付、授权签发与验证、
 * 云端接口、团队与开放 API、实名认证、审计、云端运营）一条都不出现——
 * 不是「藏起来」，是控制器文件根本不在包里。
 *
 * 顺序仍然有意义：具体路径在前，动态段在后，/{slug} 最后兜底。
 */
declare(strict_types=1);

/** @var App\Router $router */

use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DomainController;
use App\Controllers\GoController;
use App\Controllers\LinkController;
use App\Controllers\PublicController;

/* 入口。client 模式下直接分流到登录或工作台 */
$router->get('/', [PublicController::class, 'home']);
$router->get('/docs', [PublicController::class, 'docs']);
$router->get('/legal/{doc}', [PublicController::class, 'legal']);
$router->get('/health', [PublicController::class, 'health']);

/* 账号 */
$router->get('/register', [AuthController::class, 'registerForm']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/forgot', [AuthController::class, 'forgotForm']);
$router->post('/forgot', [AuthController::class, 'forgot']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->post('/captcha/send', [AuthController::class, 'sendCode']);

/* 个人中心。开源版只有账号信息与改密 */
$router->get('/account', [AccountController::class, 'home']);
$router->post('/account/password', [AccountController::class, 'changePassword']);

/* 后台。只有 role=admin 能进，判定在控制器里 */
$router->get('/admin', [AdminController::class, 'home']);
$router->get('/admin/users', [AdminController::class, 'users']);
$router->post('/admin/users/action', [AdminController::class, 'userAction']);
$router->get('/admin/links', [AdminController::class, 'links']);
$router->post('/admin/links/action', [AdminController::class, 'linkAction']);
$router->get('/admin/settings', [AdminController::class, 'settings']);
$router->post('/admin/settings', [AdminController::class, 'saveSettings']);
$router->post('/admin/mail-test', [AdminController::class, 'mailTest']);

/* 短链工作台。顺序上必须排在动态段之前 */
$router->get('/links', [LinkController::class, 'index']);
$router->get('/links/new', [LinkController::class, 'create']);
$router->post('/links/new', [LinkController::class, 'store']);
$router->get('/links/export', [LinkController::class, 'export']);
$router->post('/links/bulk', [LinkController::class, 'bulk']);
$router->get('/links/{id}', [LinkController::class, 'show']);
$router->get('/links/{id}/edit', [LinkController::class, 'edit']);
$router->post('/links/{id}/edit', [LinkController::class, 'update']);
$router->get('/links/{id}/qr', [LinkController::class, 'qr']);
$router->post('/links/{id}/delete', [LinkController::class, 'delete']);
$router->post('/links/{id}/toggle', [LinkController::class, 'toggle']);

/* 域名。开源版受能力限制，页面会提示需要购买商业版 */
$router->get('/domains', [DomainController::class, 'index']);
$router->post('/domains/new', [DomainController::class, 'add']);
$router->post('/domains/{id}/verify', [DomainController::class, 'verify']);
$router->post('/domains/{id}/delete', [DomainController::class, 'remove']);

/* 跳转。POST 是输密码，GET 是跳 */
$router->get('/r/{slug}', [GoController::class, 'r']);
$router->post('/r/{slug}', [GoController::class, 'unlock']);

/*
 * 自定义域名下的根路径短链：https://link.example.com/abc
 * 必须最后注册——它匹配任何单段路径。控制器里会先确认请求的 Host
 * 是一条验证过的自定义域名，否则直接 404，不会把站内页面吞掉。
 */
$router->get('/{slug}', [GoController::class, 'root']);

/* 兜底 404 */
$router->setNotFound(static function (string $path): string {
    return App\View::render('error', [
        'title'  => '页面不存在',
        'code'   => 404,
        'reason' => '这个地址没有对应的页面：' . $path,
    ]);
});
