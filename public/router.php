<?php
/**
 * 仅用于 `php -S` 本地预览：静态文件直接返回，其余交给 index.php。
 * Nginx / Apache 部署时不会用到这个文件。
 */
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
