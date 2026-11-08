<?php
/**
 * 请求对象。所有输入只从这里取，避免业务代码直接摸 $_GET / $_POST。
 */
declare(strict_types=1);

namespace App;

final class Request
{
    private ?array $jsonCache = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /** 去掉查询串、去掉子目录前缀、去掉尾部斜杠 */
    public function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = rtrim(dirname($script), '/');
        if ($base !== '' && $base !== '/' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    public function query(string $key, $default = null)
    {
        return array_key_exists($key, $_GET) ? $_GET[$key] : $default;
    }

    public function post(string $key, $default = null)
    {
        return array_key_exists($key, $_POST) ? $_POST[$key] : $default;
    }

    /** 表单优先，其次 JSON body，最后查询串 */
    public function input(string $key, $default = null)
    {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        return array_key_exists($key, $_GET) ? $_GET[$key] : $default;
    }

    /** 取字符串并去空白 */
    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        if (is_array($v)) {
            return $default;
        }
        return trim((string) $v);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key, null);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $this->jsonCache = [];
        }
        $data = json_decode($raw, true);
        return $this->jsonCache = is_array($data) ? $data : [];
    }

    /** 原始 body，支付回调验签要用原样字节 */
    public function rawBody(): string
    {
        $raw = file_get_contents('php://input');
        return $raw === false ? '' : $raw;
    }

    public function ip(): string
    {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (empty($_SERVER[$k])) {
                continue;
            }
            $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }

    public function ua(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if ($name === 'Content-Type') {
            return (string) ($_SERVER['CONTENT_TYPE'] ?? $default);
        }
        return $default;
    }

    public function host(): string
    {
        $h = (string) ($_SERVER['HTTP_HOST'] ?? '');
        return strtolower(preg_replace('/:\d+$/', '', $h) ?: '');
    }

    public function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function fullUrl(): string
    {
        return ($this->isHttps() ? 'https://' : 'http://') . $this->host() . (string) ($_SERVER['REQUEST_URI'] ?? '/');
    }
}
