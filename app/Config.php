<?php
/**
 * 配置中心。.env 只在首次加载时解析，之后全部走内存。
 */
declare(strict_types=1);

namespace App;

final class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }
        $envFile = $basePath . '/.env';
        if (is_file($envFile)) {
            self::parseEnv($envFile);
        }
        self::$data = require $basePath . '/config/config.php';
        self::$loaded = true;
    }

    private static function parseEnv(string $file): void
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                $val = substr($val, 1, -1);
            }
            // 只写 $_ENV，不调 putenv：多数主机默认禁用它。
            // 真系统环境变量优先，方便容器 / 面板注入。
            if (!array_key_exists($key, $_ENV) || $_ENV[$key] === '') {
                $_ENV[$key] = $val;
            }
        }
    }

    /** 支持 a.b.c 点号取值 */
    public static function get(string $key, $default = null)
    {
        $cur = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public static function all(): array
    {
        return self::$data;
    }

    public static function set(string $key, $value): void
    {
        $parts = explode('.', $key);
        $ref = &self::$data;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $ref[$part] = $value;
                break;
            }
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
    }
}
