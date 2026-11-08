<?php
/**
 * FulkLink 安装向导。
 *
 * 这个文件独立运行，故意不加载 app/ 下的任何代码：
 * 安装时数据库还没建好，框架起不来，所以连接、建表、写配置全在这里自己做。
 *
 * 装完请把 install/ 目录和 public/install.php 一起删掉。
 */
declare(strict_types=1);

const FL_INSTALL_VERSION = '1.0.0';

$ROOT    = dirname(__DIR__);
$DATA    = $ROOT . '/data';
$LOCK    = $DATA . '/install.lock';
$ENVFILE = $ROOT . '/.env';
$EXAMPLE = $ROOT . '/.env.example';
$SCHEMA  = $ROOT . '/database/schema.sql';

if (!is_dir($DATA)) {
    @mkdir($DATA, 0755, true);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$sessionOk = session_status() === PHP_SESSION_ACTIVE;
if ($sessionOk) {
    if (empty($_SESSION['lp_install_token'])) {
        $_SESSION['lp_install_token'] = bin2hex(random_bytes(16));
    }
    $TOKEN = (string) $_SESSION['lp_install_token'];
} else {
    $TOKEN = '';
}

/* ---------------------------------------------------------------- 工具函数 */

function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lp_post(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** 解析已有 .env，用于把上次填过的值回填到表单 */
function lp_read_env(string $file): array
{
    $out = [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return $out;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
            $v = substr($v, 1, -1);
        }
        $out[trim($k)] = $v;
    }
    return $out;
}

function lp_quote(string $v): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

/** 以 .env.example 为模板生成 .env，模板里有而表单没提供的项保持原样 */
function lp_build_env(string $examplePath, array $map): string
{
    if (!is_file($examplePath)) {
        $out = "# FulkLink 配置（安装时生成）\n";
        foreach ($map as $k => $v) {
            $out .= $k . '=' . lp_quote((string) $v) . "\n";
        }
        return $out;
    }
    $tpl = (string) @file_get_contents($examplePath);
    foreach ($map as $k => $v) {
        $line = $k . '=' . lp_quote((string) $v);
        $pattern = '/^' . preg_quote($k, '/') . '=.*$/m';
        if (preg_match($pattern, $tpl) === 1) {
            $tpl = (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $tpl, 1);
        } else {
            $tpl .= "\n" . $line . "\n";
        }
    }
    return $tpl;
}

/** 按行切 SQL。本项目 schema 没有存储过程，按分号结尾切就够 */
function lp_split_sql(string $sql): array
{
    $stmts = [];
    $buf = '';
    foreach (preg_split("/\r\n|\n|\r/", $sql) ?: [] as $line) {
        $t = trim($line);
        if ($t === '' || strpos($t, '--') === 0 || strpos($t, '#') === 0 || strpos($t, '/*') === 0) {
            continue;
        }
        $buf .= $line . "\n";
        if (substr($t, -1) === ';') {
            $one = trim(rtrim(trim($buf), ';'));
            if ($one !== '') {
                $stmts[] = $one;
            }
            $buf = '';
        }
    }
    if (trim($buf) !== '') {
        $stmts[] = trim($buf);
    }
    return $stmts;
}

function lp_trim_err(string $msg): string
{
    $msg = preg_replace('/\s+/', ' ', $msg) ?? $msg;
    if (mb_strlen($msg) > 220) {
        $msg = mb_substr($msg, 0, 220) . '…';
    }
    return $msg;
}

/** 猜一个站点地址，用户一般不用改 */
function lp_guess_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[A-Za-z0-9\.\-\:\[\]]+$/', (string) $host)) {
        $host = 'localhost';
    }
    return ($https ? 'https' : 'http') . '://' . $host;
}

/* ---------------------------------------------------------------- 环境检查 */

function lp_checks(string $root, string $data, string $envFile, string $schema): array
{
    $rows = [];

    $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
    $rows[] = ['PHP 版本', PHP_VERSION, $phpOk ? 'ok' : 'fail', $phpOk ? '' : '需要 8.0 或以上，在面板里切换 PHP 版本'];

    $exts = [
        'pdo'       => 'PDO',
        'pdo_mysql' => 'PDO_MySQL',
        'mbstring'  => 'mbstring',
        'openssl'   => 'OpenSSL',
        'curl'      => 'cURL',
        'json'      => 'JSON',
        'session'   => 'Session',
    ];
    foreach ($exts as $ext => $label) {
        $ok = extension_loaded($ext);
        $rows[] = ['扩展 ' . $label, $ok ? '已启用' : '未启用', $ok ? 'ok' : 'fail', $ok ? '' : '在 PHP 设置的「安装扩展」里装上 ' . $ext];
    }

    $rootWritable = is_writable($root) || (is_file($envFile) && is_writable($envFile));
    $rows[] = ['配置可写', $rootWritable ? '可写' : '不可写', $rootWritable ? 'ok' : 'fail', $rootWritable ? '' : '把站点目录权限改成 755、属主 www，或手动创建 .env'];

    $dataWritable = is_dir($data) ? is_writable($data) : is_writable($root);
    $rows[] = ['data 目录', is_dir($data) ? ($dataWritable ? '可写' : '不可写') : '不存在', $dataWritable ? 'ok' : 'fail', $dataWritable ? '' : '把 data 目录权限改成 755、属主 www'];

    $schemaOk = is_file($schema) && is_readable($schema);
    $rows[] = ['结构文件', $schemaOk ? '已找到' : '缺失', $schemaOk ? 'ok' : 'fail', $schemaOk ? '' : '缺少 database/schema.sql，安装包不完整，重新上传'];

    $envTpl = is_file($root . '/.env.example');
    $rows[] = ['配置模板', $envTpl ? '已找到' : '缺失', $envTpl ? 'ok' : 'warn', $envTpl ? '' : '缺 .env.example，会改用内置模板生成配置，不影响安装'];

    return $rows;
}

/* ---------------------------------------------------------------- 安装动作 */

/**
 * 建库、建表、建管理员、写配置。
 * 任何一步不满足条件就抛 RuntimeException，消息直接给用户看。
 */
function lp_install(array $in, array $paths): array
{
    if (is_file($paths['lock'])) {
        throw new RuntimeException('这个站点已经装过了。要重装，先删掉 data/install.lock，再刷新本页。');
    }
    if (!is_file($paths['schema'])) {
        throw new RuntimeException('找不到 database/schema.sql，安装包不完整，请重新上传后再装。');
    }

    $host = $in['db_host'];
    $port = (int) $in['db_port'];
    $name = $in['db_name'];
    $user = $in['db_user'];
    $pass = $in['db_pass'];

    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    /* 先连服务器（不指定库），库不存在时才有机会建 */
    try {
        $server = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user, $pass, $opt);
    } catch (PDOException $e) {
        throw new RuntimeException('连不上 MySQL（' . $host . ':' . $port . '）。检查地址、端口、账号密码，以及这个账号是否允许从本机登录。数据库返回：' . lp_trim_err($e->getMessage()));
    }

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, $user, $pass, $opt);
    } catch (PDOException $e) {
        try {
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $e2) {
            throw new RuntimeException('数据库 `' . $name . '` 不存在，这个账号也没权限新建。请先在面板里建一个 utf8mb4 的库（名字和这里填的一致），再回来装。数据库返回：' . lp_trim_err($e->getMessage()));
        }
        try {
            $pdo = new PDO($dsn, $user, $pass, $opt);
        } catch (PDOException $e3) {
            throw new RuntimeException('库 `' . $name . '` 建出来了，但还是连不上。数据库返回：' . lp_trim_err($e3->getMessage()));
        }
    }

    try {
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (PDOException $e) {
        // 时区表没导入时可能失败，不影响使用
    }

    $mysqlVersion = '';
    try {
        $mysqlVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    } catch (PDOException $e) {
        $mysqlVersion = '未知';
    }

    /* 已有数据的库不碰，避免把客户生产数据覆盖掉 */
    try {
        $hasUsers = (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    } catch (PDOException $e) {
        $hasUsers = false;
    }
    if ($hasUsers) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn();
        if ($count > 0) {
            throw new RuntimeException('这个库里已经有 ' . $count . ' 个账号，装过 FulkLink 或别的系统。为了不覆盖数据，安装停在这里。换一个空库，或者备份后清空 users 表再来。');
        }
    }

    $sql = (string) @file_get_contents($paths['schema']);
    if (trim($sql) === '') {
        throw new RuntimeException('database/schema.sql 是空的，安装包可能不完整。');
    }
    $stmts = lp_split_sql($sql);
    $done = 0;
    foreach ($stmts as $stmt) {
        try {
            $pdo->exec($stmt);
            $done++;
        } catch (PDOException $e) {
            throw new RuntimeException('建表到第 ' . ($done + 1) . ' 条时失败：' . lp_trim_err($e->getMessage()) . '。已建好的表不会自动删，清空这个库再试。');
        }
    }

    $now  = date('Y-m-d H:i:s');
    $hash = password_hash($in['admin_pass'], PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('管理员密码加密失败，服务器 PHP 的 password_hash 不可用，换个 PHP 版本再试。');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO `users`
             (`email`,`password_hash`,`name`,`role`,`plan_code`,`plan_expires_at`,`identity_level`,
              `real_name`,`certified_at`,`status`,`last_login_at`,`last_login_ip`,`created_at`,`updated_at`)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $in['admin_email'], $hash, '管理员', 'admin', 'enterprise', null, 'none',
            '', null, 'active', null, '', $now, $now,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('管理员账号写不进去：' . lp_trim_err($e->getMessage()));
    }

    try {
        $ss = $pdo->prepare('INSERT INTO `settings` (`k`,`v`,`updated_at`) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE `v`=VALUES(`v`), `updated_at`=VALUES(`updated_at`)');
        $ss->execute(['site_name', $in['site_name'], $now]);
        $ss->execute(['support_qq', $in['support_qq'], $now]);
        $pdo->prepare('INSERT IGNORE INTO `schema_migrations` (`version`,`applied_at`) VALUES (?,?)')
            ->execute([FL_INSTALL_VERSION, $now]);
    } catch (PDOException $e) {
        // 默认配置写不进去不影响站点启动
    }

    /* 站点是 http 还是 https，直接决定登录 Cookie 要不要加 Secure */
    $isHttps = strpos($in['site_url'], 'https://') === 0;
    $envMap = [
        'SITE_NAME'     => $in['site_name'],
        'SITE_URL'      => $in['site_url'],
        'APP_KEY'       => bin2hex(random_bytes(32)),
        'DB_HOST'       => $host,
        'DB_PORT'       => (string) $port,
        'DB_NAME'       => $name,
        'DB_USER'       => $user,
        'DB_PASS'       => $pass,
        'COOKIE_SECURE' => $isHttps ? 'true' : 'false',
        'SUPPORT_QQ'    => $in['support_qq'],
    ];
    $envContent = lp_build_env($paths['example'], $envMap);

    $envWritten = false;
    $tmp = $paths['env'] . '.tmp';
    if (@file_put_contents($tmp, $envContent) !== false) {
        if (@rename($tmp, $paths['env'])) {
            $envWritten = true;
        } else {
            @unlink($tmp);
        }
    }
    if (!$envWritten && is_file($paths['env']) && is_writable($paths['env'])) {
        $envWritten = @file_put_contents($paths['env'], $envContent) !== false;
    }

    $lockBody = json_encode([
        'version'    => FL_INSTALL_VERSION,
        'installed'  => $now,
        'mysql'      => $mysqlVersion,
        'admin'      => $in['admin_email'],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $lockWritten = @file_put_contents($paths['lock'], (string) $lockBody) !== false;

    return [
        'mysql'        => $mysqlVersion,
        'tables'       => $done,
        'admin'        => $in['admin_email'],
        'url'          => $in['site_url'],
        'env_written'  => $envWritten,
        'env_content'  => $envContent,
        'lock_written' => $lockWritten,
        'lock_path'    => $paths['lock'],
    ];
}

/* ---------------------------------------------------------------- 表单与状态 */

$installed = is_file($LOCK);
$errors    = [];
$done      = null;

$form = [
    'db_host'     => '127.0.0.1',
    'db_port'     => '3306',
    'db_name'     => 'fulklink',
    'db_user'     => '',
    'db_pass'     => '',
    'site_name'   => 'FulkLink 福链',
    'site_url'    => lp_guess_url(),
    'support_qq'  => '3432511759',
    'admin_email' => '',
    'admin_pass'  => '',
    'admin_pass2' => '',
];

/* 有旧 .env 就带出上次的值，重装或补装时省得重填 */
if (is_file($ENVFILE)) {
    $old = lp_read_env($ENVFILE);
    $form['db_host']    = $old['DB_HOST']    ?? $form['db_host'];
    $form['db_port']    = $old['DB_PORT']    ?? $form['db_port'];
    $form['db_name']    = $old['DB_NAME']    ?? $form['db_name'];
    $form['db_user']    = $old['DB_USER']    ?? $form['db_user'];
    $form['db_pass']    = $old['DB_PASS']    ?? $form['db_pass'];
    $form['site_name']  = $old['SITE_NAME']  ?? $form['site_name'];
    $form['site_url']   = $old['SITE_URL']   ?? $form['site_url'];
    $form['support_qq'] = $old['SUPPORT_QQ'] ?? $form['support_qq'];
}

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $k) {
        $form[$k] = lp_post($k);
    }

    if ($sessionOk) {
        $sent = lp_post('token');
        if ($sent === '' || !hash_equals($TOKEN, $sent)) {
            $errors[] = '页面放太久过期了，刷新一下重新提交。';
        }
    }

    if ($form['db_host'] === '') {
        $errors[] = '数据库地址没填。';
    }
    if (!preg_match('/^\d+$/', $form['db_port']) || (int) $form['db_port'] < 1 || (int) $form['db_port'] > 65535) {
        $errors[] = '数据库端口填错了，一般是 3306。';
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $form['db_name'])) {
        $errors[] = '数据库名只能用字母、数字和下划线，别带横杠或中文。';
    }
    if ($form['db_user'] === '') {
        $errors[] = '数据库账号没填。';
    }
    if ($form['site_name'] === '' || mb_strlen($form['site_name']) > 60) {
        $errors[] = '站点名称要填，60 个字以内。';
    }
    $form['site_url'] = rtrim($form['site_url'], '/');
    if (!preg_match('#^https?://[^\s/]+#i', $form['site_url'])) {
        $errors[] = '站点地址要带 http:// 或 https://，比如 https://lp.example.com。';
    }
    if (!filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = '管理员邮箱格式不对。';
    }
    if (mb_strlen($form['admin_pass']) < 10 || !preg_match('/[A-Za-z]/', $form['admin_pass']) || !preg_match('/\d/', $form['admin_pass'])) {
        $errors[] = '管理员密码至少 10 位，并且要有字母和数字。';
    } elseif ($form['admin_pass'] !== $form['admin_pass2']) {
        $errors[] = '两次输入的密码不一样。';
    }

    if (!$errors) {
        try {
            $done = lp_install($form, [
                'lock'   => $LOCK,
                'env'    => $ENVFILE,
                'example' => $EXAMPLE,
                'schema' => $SCHEMA,
            ]);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            $errors[] = '安装中断：' . lp_trim_err($e->getMessage());
        }
    }
}

$checks     = lp_checks($ROOT, $DATA, $ENVFILE, $SCHEMA);
$hasBlocked = false;
foreach ($checks as $c) {
    if ($c[2] === 'fail') {
        $hasBlocked = true;
        break;
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>FulkLink 安装</title>
<style>
:root{
  --bg:#0a0c0f; --panel:#101318; --panel2:#151a21; --line:#232a34;
  --text:#e8ebf0; --dim:#8b95a5; --accent:#4d8dff; --ok:#3fb950; --warn:#d29922; --err:#f85149;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);
  font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;
  -webkit-font-smoothing:antialiased}
a{color:var(--accent);text-decoration:none}
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.92em}

.top{border-bottom:1px solid var(--line);background:var(--panel)}
.top-in{max-width:820px;margin:0 auto;padding:16px 22px;display:flex;align-items:center;gap:12px}
.mark{width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,#4d8dff,#7b5cff);
  display:grid;place-items:center;font:700 11px/1 system-ui;color:#fff}
.top-in b{font-weight:600;letter-spacing:.2px}
.top-in .ver{margin-left:auto;color:var(--dim);font-size:12.5px}

.wrap{max-width:820px;margin:0 auto;padding:26px 22px 60px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:26px}
.card + .card{margin-top:18px}
h1{font-size:20px;margin:0 0 6px;font-weight:600}
h2{font-size:14px;margin:0 0 14px;font-weight:600;color:var(--text);letter-spacing:.2px}
p{margin:0 0 12px}
.lead{color:var(--dim);font-size:13.5px;margin-bottom:20px}

.alert{border-radius:9px;padding:12px 14px;font-size:13.5px;margin-bottom:18px;border:1px solid}
.alert.err{background:rgba(248,81,73,.08);border-color:rgba(248,81,73,.35);color:#ffb3ae}
.alert.warn{background:rgba(210,153,34,.08);border-color:rgba(210,153,34,.35);color:#e3c07a}
.alert.ok{background:rgba(63,185,80,.08);border-color:rgba(63,185,80,.32);color:#9ae6a6}
.alert ul{margin:6px 0 0;padding-left:18px}
.alert li{margin:3px 0}

table.chk{width:100%;border-collapse:collapse;font-size:13.5px}
table.chk td{padding:7px 0;border-bottom:1px solid #1b212a;vertical-align:top}
table.chk tr:last-child td{border-bottom:0}
table.chk td:first-child{width:150px;color:var(--dim)}
.st{display:inline-block;min-width:52px;font-size:12px;padding:1px 8px;border-radius:20px;text-align:center;border:1px solid}
.st.ok{color:#7ee787;border-color:rgba(63,185,80,.4);background:rgba(63,185,80,.1)}
.st.warn{color:#e3b341;border-color:rgba(210,153,34,.4);background:rgba(210,153,34,.1)}
.st.fail{color:#ff7b72;border-color:rgba(248,81,73,.4);background:rgba(248,81,73,.1)}
.fix{color:var(--dim);font-size:12.5px;margin-top:2px}

fieldset{border:0;padding:0;margin:0 0 26px}
legend{font-size:14px;font-weight:600;padding:0;margin-bottom:4px}
.sub{color:var(--dim);font-size:12.5px;margin:0 0 14px}

.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 16px}
.grid .full{grid-column:1 / -1}
label{display:block;font-size:13px;color:var(--dim);margin-bottom:5px}
input[type=text],input[type=password],input[type=email],input[type=number]{
  width:100%;background:var(--panel2);border:1px solid var(--line);border-radius:8px;
  color:var(--text);padding:9px 11px;font:14px/1.5 inherit;outline:none}
input:focus{border-color:#39537d;box-shadow:0 0 0 3px rgba(77,141,255,.12)}
input:disabled{opacity:.55}
.hint{font-size:12px;color:#6f7987;margin-top:4px}

.btn{display:inline-block;border:0;border-radius:9px;background:var(--accent);color:#fff;
  padding:11px 24px;font:600 14px/1 inherit;cursor:pointer}
.btn:hover{background:#3c7bf0}
.btn.ghost{background:transparent;border:1px solid var(--line);color:var(--text);font-weight:500}
.foot{display:flex;align-items:center;gap:14px;margin-top:8px;padding-top:20px;border-top:1px solid var(--line)}
.foot .note{color:var(--dim);font-size:12.5px;margin-left:auto}

.kv{display:grid;grid-template-columns:110px 1fr;gap:8px 14px;font-size:13.5px;margin:16px 0 4px}
.kv dt{color:var(--dim)}
.kv dd{margin:0}
pre.env{background:#0c1015;border:1px solid var(--line);border-radius:9px;padding:14px;
  overflow:auto;font:12.5px/1.65 ui-monospace,Menlo,Consolas,monospace;color:#c8d1de;max-height:340px}
.page-foot{max-width:820px;margin:0 auto;padding:0 22px 40px;color:#5d6673;font-size:12px}
@media (max-width:640px){
  .grid{grid-template-columns:1fr}
  .card{padding:20px}
  .kv{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div class="top">
  <div class="top-in">
    <span class="mark">FL</span>
    <b>FulkLink 福链 · 安装</b>
    <span class="ver">v<?= h(FL_INSTALL_VERSION) ?></span>
  </div>
</div>

<div class="wrap">
<?php if ($installed): ?>

  <div class="card">
    <h1>这个站点已经装好了</h1>
    <p class="lead">检测到 <code>data/install.lock</code>，安装程序不再重复执行，免得把现有数据覆盖掉。</p>
    <div class="alert warn">
      如果确实要重装：先备份数据库和 <code>.env</code>，删掉 <code>data/install.lock</code>，再刷新本页。
    </div>
    <h2>接着做</h2>
    <div class="kv">
      <dt>站点首页</dt>
      <dd><a href="/">打开看看</a></dd>
      <dt>安装目录</dt>
      <dd>如果还在，把 <code>install/</code> 和 <code>public/install.php</code> 删掉</dd>
    </div>
    <div class="foot">
      <a class="btn ghost" href="/">进入站点</a>
      <span class="note">客服 QQ 3432511759</span>
    </div>
  </div>

<?php elseif ($done !== null): ?>

  <div class="card">
    <h1>装好了</h1>
    <p class="lead">数据库建好了，管理员账号也写进去了。</p>

    <div class="alert ok">安装完成。下面两件事记得做，做完就能正常对外。</div>

    <h2>装了什么</h2>
    <div class="kv">
      <dt>建表</dt>
      <dd><?= (int) $done['tables'] ?> 张</dd>
      <dt>MySQL</dt>
      <dd><?= h($done['mysql']) ?></dd>
      <dt>管理员</dt>
      <dd><?= h($done['admin']) ?></dd>
      <dt>站点地址</dt>
      <dd><a href="<?= h($done['url']) ?>" target="_blank" rel="noopener"><?= h($done['url']) ?></a></dd>
    </div>

    <div class="alert warn" style="margin-top:20px">
      <b>1. 删掉安装目录</b><br>
      这两个地方都删掉，留着等于把重装入口开在公网上：<br>
      <code><?= h($ROOT) ?>/install/</code><br>
      <code><?= h($ROOT) ?>/public/install.php</code>
    </div>

    <div class="alert warn">
      <b>2. 站点根目录指向 public/</b><br>
      根目录必须是 <code><?= h($ROOT) ?>/public</code>，不是项目根。指错了不只是打不开页面 ——
      <code>.env</code>、<code>app/</code> 会被人直接下载，数据库密码就漏了。<br>
      伪静态用 <code>try_files $uri $uri/ /index.php?$query_string</code>，不然除首页以外的地址会 404。
    </div>

    <?php if (!$done['lock_written']): ?>
      <div class="alert err">
        <b>安装锁没写进去。</b> 站点能用，但安装入口还开着，别人再访问这个页面会重装一遍。
        请立刻删掉 <code>install/</code> 和 <code>public/install.php</code>，或者手动建一个空的
        <code><?= h($done['lock_path']) ?></code>。
      </div>
    <?php endif; ?>

    <?php if (!$done['env_written']): ?>
      <div class="alert err">
        <b>配置没写进去。</b> 根目录没有写权限，<code>.env</code> 没生成。把下面这段复制成
        <code><?= h($ROOT) ?>/.env</code>，站点才能连上数据库。
      </div>
      <pre class="env"><?= h($done['env_content']) ?></pre>
    <?php endif; ?>

    <div class="foot">
      <a class="btn" href="/">打开站点首页</a>
      <span class="note">客服 QQ 3432511759</span>
    </div>
  </div>

<?php else: ?>

  <div class="card">
    <h1>装之前先看一眼</h1>
    <p class="lead">下面是服务器的检查结果。有一条标红就先处理掉，处理好刷新本页，后面的表单才能提交。</p>
    <table class="chk">
      <?php foreach ($checks as $c): ?>
        <tr>
          <td><?= h($c[0]) ?></td>
          <td>
            <span class="st <?= h($c[2]) ?>"><?= $c[2] === 'ok' ? '正常' : ($c[2] === 'warn' ? '注意' : '不通过') ?></span>
            <span style="margin-left:10px"><?= h($c[1]) ?></span>
            <?php if ($c[3] !== ''): ?><div class="fix"><?= h($c[3]) ?></div><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($errors): ?>
    <div class="card" style="border-color:rgba(248,81,73,.3)">
      <div class="alert err" style="margin:0">
        <b>没能装完，按下面这些改一下：</b>
        <ul>
          <?php foreach ($errors as $msg): ?>
            <li><?= h($msg) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <form class="card" method="post" action="">
    <input type="hidden" name="token" value="<?= h($TOKEN) ?>">

    <h1 style="margin-bottom:4px">填部署信息</h1>
    <p class="lead">数据库信息在宝塔的「数据库」页能看到。库不存在的话，这里会用你填的账号试着建一个，建不了再去面板里手动建。</p>

    <fieldset <?= $hasBlocked ? 'disabled' : '' ?>>
      <legend>数据库</legend>
      <p class="sub">MySQL 5.7 及以上，字符集 utf8mb4。</p>
      <div class="grid">
        <div>
          <label for="db_host">地址</label>
          <input type="text" id="db_host" name="db_host" value="<?= h($form['db_host']) ?>" placeholder="127.0.0.1">
        </div>
        <div>
          <label for="db_port">端口</label>
          <input type="text" id="db_port" name="db_port" value="<?= h($form['db_port']) ?>" placeholder="3306">
        </div>
        <div>
          <label for="db_name">库名</label>
          <input type="text" id="db_name" name="db_name" value="<?= h($form['db_name']) ?>" placeholder="fulklink">
          <div class="hint">字母、数字、下划线</div>
        </div>
        <div>
          <label for="db_user">账号</label>
          <input type="text" id="db_user" name="db_user" value="<?= h($form['db_user']) ?>" placeholder="fulklink">
        </div>
        <div class="full">
          <label for="db_pass">密码</label>
          <input type="password" id="db_pass" name="db_pass" value="<?= h($form['db_pass']) ?>" placeholder="数据库密码" autocomplete="new-password">
          <div class="hint">密码为空就不用填</div>
        </div>
      </div>
    </fieldset>

    <fieldset <?= $hasBlocked ? 'disabled' : '' ?>>
      <legend>站点</legend>
      <p class="sub">这两项写在页面标题和页脚，也用于生成回调和通知里的绝对地址。</p>
      <div class="grid">
        <div>
          <label for="site_name">站点名称</label>
          <input type="text" id="site_name" name="site_name" value="<?= h($form['site_name']) ?>">
        </div>
        <div>
          <label for="support_qq">客服 QQ</label>
          <input type="text" id="support_qq" name="support_qq" value="<?= h($form['support_qq']) ?>">
        </div>
        <div class="full">
          <label for="site_url">站点地址</label>
          <input type="text" id="site_url" name="site_url" value="<?= h($form['site_url']) ?>" placeholder="https://lp.example.com">
          <div class="hint">不带结尾斜杠。现在是 http 就先填 http，上了证书再改成 https，登录状态会跟着走。</div>
        </div>
      </div>
    </fieldset>

    <fieldset <?= $hasBlocked ? 'disabled' : '' ?>>
      <legend>管理员</legend>
      <p class="sub">这是第一个账号，权限最高。密码自己记好，程序不会存明文。</p>
      <div class="grid">
        <div class="full">
          <label for="admin_email">登录邮箱</label>
          <input type="email" id="admin_email" name="admin_email" value="<?= h($form['admin_email']) ?>" placeholder="you@example.com">
        </div>
        <div>
          <label for="admin_pass">密码</label>
          <input type="password" id="admin_pass" name="admin_pass" value="<?= h($form['admin_pass']) ?>" autocomplete="new-password">
          <div class="hint">至少 10 位，含字母和数字</div>
        </div>
        <div>
          <label for="admin_pass2">再输一次</label>
          <input type="password" id="admin_pass2" name="admin_pass2" value="<?= h($form['admin_pass2']) ?>" autocomplete="new-password">
        </div>
      </div>
    </fieldset>

    <div class="foot">
      <?php if ($hasBlocked): ?>
        <button class="btn" type="button" disabled>先把上面的问题处理掉</button>
        <span class="note">改完刷新本页重新检查</span>
      <?php else: ?>
        <button class="btn" type="submit">开始安装</button>
        <span class="note">装完把 install 目录删掉</span>
      <?php endif; ?>
    </div>
  </form>

<?php endif; ?>
</div>

<div class="page-foot">FulkLink 福链 · 安装向导 v<?= h(FL_INSTALL_VERSION) ?> · 客服 QQ 3432511759</div>
</body>
</html>
