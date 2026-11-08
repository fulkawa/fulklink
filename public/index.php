<?php
/**
 * 唯一入口。所有请求都从这里进，public 是站点根目录。
 */
declare(strict_types=1);

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
