<?php
/**
 * 引导文件：常量、自动加载、配置、时区、错误处理。
 * 所有入口（public/index.php、定时脚本、安装向导）都从这里开始。
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_START', microtime(true));

require BASE_PATH . '/app/helpers.php';

/* 类名即文件名，不做任何自动发现 —— 加密后的文件按名加载最稳 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (strpos($relative, '..') !== false) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Config::load(BASE_PATH);

date_default_timezone_set((string) App\Config::get('app.timezone', 'Asia/Shanghai'));

$debug = (bool) App\Config::get('app.debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
if (is_dir(BASE_PATH . '/data') && is_writable(BASE_PATH . '/data')) {
    ini_set('error_log', BASE_PATH . '/data/php-error.log');
}

/* 结构升级：版本号没变时只读一个文件，不连库。库连不上不能影响首页 */
if (is_file(BASE_PATH . '/.env')) {
    try {
        App\Migrator::ensure();
    } catch (\Throwable $e) {
        error_log('[migrate] ' . $e->getMessage());
    }
}

/* 客户站：按周期回一次心跳，放在响应之后跑。cloud 模式下这句立刻返回，不碰库 */
try {
    App\Cloud\Heartbeat::queue();
} catch (Throwable $e) {
    error_log('[heartbeat] ' . $e->getMessage());
}

/* 未捕获异常统一出口：不把堆栈吐给访客，只写日志 */
set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log('[uncaught] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if ($debug) {
        echo '<pre style="padding:20px;font:13px/1.6 monospace">';
        echo e($e->getMessage()) . "\n\n" . e($e->getFile()) . ':' . $e->getLine() . "\n\n";
        echo e($e->getTraceAsString());
        echo '</pre>';
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>500</title>'
            . '<div style="font:14px/1.7 system-ui;padding:60px;max-width:600px;margin:0 auto">'
            . '<h1 style="font-size:20px">服务出错了</h1>'
            . '<p style="color:#666">这次请求没有完成。已经记录到服务端日志，稍后再试或联系我们。</p>'
            . '</div>';
    }
});
