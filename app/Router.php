<?php
/**
 * 路由。先注册静态路径，动态段用 {name} 占位，最后注册通配短链跳转。
 */
declare(strict_types=1);

namespace App;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:array}> */
    private array $routes = [];
    private $notFound = null;

    public function add(string $method, string $path, array $handler): void
    {
        $this->routes[] = [strtoupper($method), $path, $handler];
    }

    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** GET/POST 都接受，用于回调地址这类不确定方法的入口 */
    public function any(string $path, array $handler): void
    {
        $this->add('*', $path, $handler);
    }

    public function setNotFound(callable $fn): void
    {
        $this->notFound = $fn;
    }

    public function dispatch(string $method, string $path): ?Response
    {
        $method = strtoupper($method);
        $pathMatched  = false;
        $pathMatched2 = null;

        foreach ($this->routes as [$m, $p, $h]) {
            if (!preg_match(self::compile($p), $path, $matched)) {
                continue;
            }
            $pathMatched = true;
            if ($m !== '*' && $m !== $method) {
                $pathMatched2 = true;
                continue;
            }
            $params = [];
            foreach ($matched as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = urldecode((string) $v);
                }
            }
            [$class, $action] = $h;
            $controller = new $class();
            $response = $controller->{$action}($params);
            return $response instanceof Response ? $response : new Response((string) $response);
        }

        // 路径存在但方法不对：给 405，别错报成 404
        if ($pathMatched && $pathMatched2) {
            return Response::html('<h1>405</h1><p>这个地址不接受 ' . e($method) . ' 请求。</p>', 405);
        }
        if ($this->notFound !== null) {
            return new Response((string) call_user_func($this->notFound, $path), 404);
        }
        return null;
    }

    /** /a/{id}/b → #^/a/(?P<id>[^/]+)/b$# */
    private static function compile(string $path): string
    {
        $tokens = preg_split('#(\{[a-zA-Z_][a-zA-Z0-9_]*\})#', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($tokens as $token) {
            if (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$#', $token, $m)) {
                $regex .= '(?P<' . $m[1] . '>[^/]+)';
            } else {
                $regex .= preg_quote($token, '#');
            }
        }
        return '#^' . $regex . '$#';
    }
}
