<?php
/**
 * 安装向导入口。
 *
 * 站点根目录指向 public/ 时，浏览器访问 /install.php 就进这个文件；
 * 真正的安装逻辑在 ../install/index.php，这里只做一次转发。
 *
 * 装完把本文件和 ../install/ 目录一起删掉。
 */
declare(strict_types=1);

$installer = dirname(__DIR__) . '/install/index.php';

if (!is_file($installer)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>安装程序不在</title>'
        . '<div style="font:14px/1.8 system-ui;padding:50px;max-width:560px;margin:0 auto">'
        . '<h1 style="font-size:18px">安装程序已经被删掉了</h1>'
        . '<p style="color:#666">这说明站点装过了。如果还没装完，把 install/ 目录重新上传后再访问本页。</p>'
        . '</div>';
    exit;
}

require $installer;
