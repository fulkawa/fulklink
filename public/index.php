<?php
/**
 * 唯一入口。所有请求都从这里进，public 是站点根目录。
 */
declare(strict_types=1);

/* 更新期间不加载新旧混合的 PHP 文件。锁在请求结束时由 PHP 自动释放。 */
$updateDir = dirname(__DIR__) . '/data/updates';
if (!is_dir($updateDir) && is_writable(dirname($updateDir))) {
    @mkdir($updateDir, 0700, true);
}
if (is_dir($updateDir)) {
    $runtimeLock = @fopen($updateDir . '/runtime.lock', 'c');
    if ($runtimeLock === false || !flock($runtimeLock, LOCK_SH | LOCK_NB)) {
        if (is_resource($runtimeLock)) { fclose($runtimeLock); }
        http_response_code(503);
        header('Retry-After: 5');
        header('Content-Type: text/plain; charset=utf-8');
        exit('站点正在更新或更新锁不可写，请稍后重试。');
    }
    $GLOBALS['fulklink_runtime_lock'] = $runtimeLock;
    // 被中断的更新保留恢复入口，其余请求暂停，避免运行半套代码。
    $stateFile = $updateDir . '/state.json';
    if (is_file($stateFile)) {
        $state = json_decode((string) @file_get_contents($stateFile), true);
        $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        // 与路由相同地归一化子目录与尾斜杠；不能提前加载可能处于半更新状态的 Request 类。
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim(dirname($script), '/');
        if ($basePath !== '' && $basePath !== '/' && $basePath !== '.'
            && ($requestPath === $basePath || str_starts_with($requestPath, $basePath . '/'))) {
            $requestPath = substr($requestPath, strlen($basePath));
        }
        $requestPath = '/' . ltrim($requestPath, '/');
        if ($requestPath !== '/') { $requestPath = rtrim($requestPath, '/'); }
        $recoveryRoute = in_array($requestPath, ['/login', '/logout', '/admin/update', '/admin/update/rollback'], true);
        if ((!is_array($state) || !in_array($state['status'] ?? '', ['idle', 'installed', 'rolled_back'], true)) && !$recoveryRoute) {
            http_response_code(503);
            header('Retry-After: 60');
            header('Content-Type: text/plain; charset=utf-8');
            exit('上次更新尚未完成。请管理员登录 /admin/update 恢复程序文件，或从站点备份人工恢复。');
        }
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Request;
use App\Response;
use App\Router;

$request = new Request();
$router  = new Router();

require BASE_PATH . '/app/routes.php';

$response = $router->dispatch($request->method(), $request->path());

if (!$response instanceof Response) {
    $response = Response::html(
        App\View::render('error', [
            'title'  => '页面不存在',
            'code'   => 404,
            'reason' => '这个地址没有对应的页面，可能是链接过期或输错了。',
        ]),
        404
    );
}

$response->send();
