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
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $fallback = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
        // 默认只信 REMOTE_ADDR；只有它命中可信代理时才解析转发头
        if (!self::isTrustedProxy($remote)) {
            return $fallback;
        }
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            // 从右向左取第一个非可信 IP，避免客户端伪造的左侧值被采信
            foreach (array_reverse(explode(',', $xff)) as $one) {
                $candidate = trim($one);
                if (filter_var($candidate, FILTER_VALIDATE_IP) && !self::isTrustedProxy($candidate)) {
                    return $candidate;
                }
            }
        }
        return $fallback;
    }

    /** REMOTE_ADDR 是否落在配置的可信代理 CIDR 内（未配置时空列表，一律不信） */
    private static function isTrustedProxy(string $ip): bool
    {
        $raw = (string) Config::get('app.trusted_proxies', '');
        if ($raw === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (explode(',', $raw) as $cidr) {
            $cidr = trim($cidr);
            if ($cidr !== '' && self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** 支持 IPv4 / IPv6，CIDR 不带掩码时按精确 IP 处理 */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $maxBits = strlen($netBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $whole = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($whole > 0 && strncmp($ipBin, $netBin, $whole) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$whole]) & $mask) === (ord($netBin[$whole]) & $mask);
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
        // 转发头同样只在可信代理下采信，否则客户端可伪造 https
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!self::isTrustedProxy($remote)) {
            return false;
        }
        return (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function fullUrl(): string
    {
        return ($this->isHttps() ? 'https://' : 'http://') . $this->host() . (string) ($_SERVER['REQUEST_URI'] ?? '/');
    }
}
