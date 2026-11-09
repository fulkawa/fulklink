<?php
/** 云端更新后台与经 HMAC 验签的推送接收端。 */
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Request;
use App\Response;
use App\Settings;
use App\Updater;
use App\View;

final class UpdateController
{
    private const MAX_BODY = 12 * 1024 * 1024;

    public function home(array $params = []): Response
    {
        if (($r = $this->guard()) !== null) {
            return $r;
        }
        $req = new Request();
        $msg = $req->query('msg', '');
        $err = $req->query('err', '');
        return $this->page(['text' => is_string($msg) ? $msg : '', 'key' => ''], is_string($err) ? $err : '');
    }

    private function page(array $notice = ['text' => '', 'key' => ''], string $error = ''): Response
    {
        try {
            $latest = Updater::latest(false);
        } catch (\Throwable $e) {
            $latest = ['ok' => false, 'cached' => false, 'error' => '清单读取失败，请检查更新源和服务器环境。', 'data' => []];
        }
        $backups = [];
        try {
            $backups = Updater::backups();
            $status = Updater::status();
        } catch (\Throwable $e) {
            $status = ['status' => 'unknown', 'error' => '无法读取更新记录或备份，请检查更新目录权限和 state.json，勿直接重装。'];
        }
        return Response::html(View::render('admin/update', [
            'title' => '程序更新',
            'nav' => '',
            'active' => 'update',
            'cur' => Updater::currentVersion(),
            'edition' => Updater::edition(),
            'source' => Updater::source(),
            'pushOn' => Updater::pushEnabled(),
            'hasPushKey' => Updater::pushKey() !== '',
            'pushUrl' => url('/api/update/push'),
            'latest' => $latest,
            'backups' => $backups,
            'updateStatus' => $status,
            'canSend' => \App\Cloud\Mode::isCloud() && class_exists('App\\UpdatePublisher'),
            'lastAt' => Settings::get('update.last_at', ''),
            'notice' => $notice,
            'error' => $error,
        ]))->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function check(array $params = []): Response
    {
        if (($r = $this->guardPost()) !== null) {
            return $r;
        }
        try {
            $res = Updater::latest(true);
            if (!$res['ok']) {
                return $this->back('检查失败：' . $res['error'], true);
            }
            $d = $res['data'];
            return $this->back($d['newer']
                ? '发现新版本 ' . $d['version'] . '（当前 ' . $d['current'] . '）。'
                : '当前没有更高版本的更新。');
        } catch (\Throwable $e) {
            return $this->back('检查失败：' . $e->getMessage(), true);
        }
    }

    public function install(array $params = []): Response
    {
        if (($r = $this->guardPost()) !== null) {
            return $r;
        }
        $file = '';
        try {
            $res = Updater::latest(true);
            if (!$res['ok']) {
                return $this->back('取不到更新清单：' . $res['error'], true);
            }
            $d = $res['data'];
            if (!$d['newer']) {
                return $this->back('更新版本必须高于当前版本。', true);
            }
            if ($d['edition'] !== Updater::edition()) {
                return $this->back('更新包版本类型与本站不一致，拒绝安装。', true);
            }
            if (!$d['php_ok']) {
                return $this->back('此版本要求 PHP ' . $d['min_php'] . '，当前为 ' . PHP_VERSION . '。', true);
            }
            if (!$d['zip_ok'] || !$d['writable']) {
                return $this->back('环境不满足：需要 ZIP 扩展、程序目录与 data/updates/ 可写。', true);
            }
            if (!preg_match('/\A[a-f0-9]{64}\z/i', (string) $d['sha256'])) {
                return $this->back('清单缺少合法 SHA-256，拒绝安装。', true);
            }
            $dl = Updater::download((string) $d['url'], (int) $d['bytes']);
            if (!$dl['ok']) {
                return $this->back('下载失败：' . $dl['error'], true);
            }
            $file = $dl['file'];
            $out = Updater::install($file, $d);
            return $this->back($out['message'], !$out['ok']);
        } catch (\Throwable $e) {
            return $this->back('安装失败：' . $e->getMessage(), true);
        } finally {
            if ($file !== '') {
                @unlink($file);
            }
        }
    }

    public function rollback(array $params = []): Response
    {
        if (($r = $this->guardPost()) !== null) {
            return $r;
        }
        try {
            $out = Updater::rollback();
            return $this->back($out['message'], !$out['ok']);
        } catch (\Throwable $e) {
            return $this->back('回滚失败：' . $e->getMessage(), true);
        }
    }

    public function config(array $params = []): Response
    {
        if (($r = $this->guardPost()) !== null) {
            return $r;
        }
        $req = new Request();
        $source = $req->post('source', '');
        if (!is_string($source)) {
            return $this->back('更新源地址格式无效。', true);
        }
        $source = trim($source);
        $act = $req->post('key_action', 'keep');
        $enabled = $req->post('push_enabled', '0') === '1';
        if ($source !== '' && (strlen($source) > 300 || !filter_var($source, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($source, PHP_URL_SCHEME)) !== 'https'
            || parse_url($source, PHP_URL_USER) !== null || parse_url($source, PHP_URL_PASS) !== null)) {
            return $this->back('更新源必须是有效的 HTTPS 地址，不含账号密码且不超过 300 字符；留空表示离线。', true);
        }
        if (!in_array($act, ['keep', 'new', 'clear'], true)) {
            return $this->back('密钥操作无效。', true);
        }
        try {
            $newKey = $act === 'new' ? bin2hex(random_bytes(32)) : '';
            if ($act === 'clear') {
                $enabled = false;
            } elseif ($enabled && $newKey === '' && Updater::pushKey() === '') {
                return $this->back('尚未生成推送密钥，不能开启推送。请选择生成新密钥后保存。', true);
            }
            Db::tx(static function () use ($source, $act, $enabled, $newKey): void {
                Settings::set('update.source_disabled', $source === '' ? '1' : '0');
                Settings::set('update.source', $source);
                if ($act !== 'keep') {
                    Settings::set('update.push_key', $newKey);
                }
                Settings::set('update.push_enabled', $enabled ? '1' : '0');
            });
            Settings::flush();
            Db::log('update.config', '更新了更新源与推送配置', ['by' => Auth::id(), 'key_action' => $act, 'push_enabled' => $enabled]);
            if ($newKey !== '') {
                return $this->page(['text' => '设置已保存。新密钥仅在本次响应展示，请立即安全保存；旧密钥已失效。', 'key' => $newKey]);
            }
            return $this->back($act === 'clear' ? '密钥已清空，推送已关闭。' : '设置已保存。');
        } catch (\Throwable $e) {
            Settings::flush();
            return $this->back('设置保存失败：' . $e->getMessage(), true);
        }
    }

    /** sig = HMAC-SHA256("v2\n{ts}\n{nonce}\n{edition}\n{version}\n{sha256}", push_key)。 */
    public function push(array $params = []): Response
    {
        if (!Updater::pushEnabled()) {
            return Response::json(['ok' => false, 'error' => '本站未开启云端推送。'], 403);
        }
        $length = $_SERVER['CONTENT_LENGTH'] ?? '';
        if ($length !== '' && (!is_scalar($length) || !ctype_digit((string) $length))) {
            return Response::json(['ok' => false, 'error' => '请求长度无效。'], 400);
        }
        if ($length !== '' && (float) $length > self::MAX_BODY) {
            return Response::json(['ok' => false, 'error' => '请求体超过 12 MiB 限制。'], 413);
        }
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            return Response::json(['ok' => false, 'error' => '无法读取请求体。'], 400);
        }
        try {
            $body = stream_get_contents($stream, self::MAX_BODY + 1);
        } finally {
            fclose($stream);
        }
        if ($body === false) {
            return Response::json(['ok' => false, 'error' => '无法读取请求体。'], 400);
        }
        if (strlen($body) > self::MAX_BODY) {
            return Response::json(['ok' => false, 'error' => '请求体超过 12 MiB 限制。'], 413);
        }
        try {
            $obj = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return Response::json(['ok' => false, 'error' => '请求体必须是合法的 JSON 对象。'], 400);
        }
        if (!$obj instanceof \stdClass) {
            return Response::json(['ok' => false, 'error' => '请求体必须是 JSON 对象。'], 400);
        }
        $in = (array) $obj;
        if (array_key_exists('force', $in)) {
            return Response::json(['ok' => false, 'error' => '推送不支持 force；版本必须递增。'], 400);
        }
        foreach (['nonce', 'edition', 'version', 'sha256', 'package', 'sig'] as $key) {
            if (!isset($in[$key]) || !is_string($in[$key])) {
                return Response::json(['ok' => false, 'error' => '缺少或无效的字段：' . $key], 400);
            }
        }
        if (!isset($in['ts']) || !is_int($in['ts'])) {
            return Response::json(['ok' => false, 'error' => '时间戳无效。'], 400);
        }
        $file = '';
        try {
            $err = Updater::verifyPush($in);
            if ($err !== '') {
                return Response::json(['ok' => false, 'error' => $err], 401);
            }
            $version = $in['version'];
            $cur = Updater::currentVersion();
            if ($in['edition'] !== Updater::edition()) {
                return Response::json(['ok' => false, 'error' => '更新包版本类型与本站不一致。'], 409);
            }
            if (version_compare($version, $cur, '<=')) {
                return Response::json(['ok' => false, 'error' => '推送版本不高于当前版本。', 'version' => $cur], 409);
            }
            $file = Updater::pushFile($in['package']);
            if ($file === '') {
                return Response::json(['ok' => false, 'error' => '更新包无效或无法写入临时目录。'], 400);
            }
            $out = Updater::install($file, [
                'edition' => $in['edition'],
                'version' => $version,
                'sha256' => strtolower($in['sha256']),
                'bytes' => (int) filesize($file),
            ]);
            Db::log('update.push', ($out['ok'] ? '推送更新成功 ' : '推送更新失败 ') . $version . '：' . $out['message'],
                ['ip' => (new Request())->ip()], $out['ok'] ? 'info' : 'warn');
            return Response::json([
                'ok' => $out['ok'],
                'message' => $out['message'],
                'version' => Updater::currentVersion(),
                'files' => $out['files'],
                'backup' => $out['backup'],
            ], $out['ok'] ? 200 : 409);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => '更新处理失败，请管理员检查更新目录权限和服务器日志。'], 500);
        } finally {
            if ($file !== '') {
                @unlink($file);
            }
        }
    }

    /** 发行方逐站发包；不持久化客户密钥，不支持广播。 */
    public function sendPackage(array $params = []): Response
    {
        if (($r = $this->guardPost()) !== null) {
            return $r;
        }
        $publisher = 'App\\UpdatePublisher';
        if (!\App\Cloud\Mode::isCloud() || !class_exists($publisher)) {
            return Response::text('此入口仅供云端发行方使用。', 403);
        }
        $req = new Request();
        $target = $req->post('target', '');
        $key = $req->post('target_key', '');
        if (!is_string($target) || !is_string($key) || trim($target) === '' || trim($key) === ''
            || strlen($target) > 300 || strlen($key) > 256) {
            return $this->back('请填写目标站点 HTTPS 根地址与该站推送密钥。', true);
        }
        $upload = $_FILES['package'] ?? null;
        if (!is_array($upload) || !isset($upload['error']) || !is_int($upload['error'])) {
            return $this->back('请选择官方构建的 ZIP 更新包。', true);
        }
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            return $this->back('更新包上传失败（错误码 ' . $upload['error'] . '），请检查 PHP 上传大小限制。', true);
        }
        $file = $upload['tmp_name'] ?? null;
        if (!is_string($file) || !is_uploaded_file($file)) {
            return $this->back('更新包不是有效的上传文件。', true);
        }
        $size = filesize($file);
        if ($size === false || $size <= 0 || $size > 8 * 1024 * 1024) {
            return $this->back('ZIP 更新包必须大于 0 且不超过 8 MiB。', true);
        }
        try {
            $out = $publisher::push(trim($target), $file, trim($key));
            $message = ($out['ok'] ? '目标站更新完成：' : '推送未完成：')
                . str_replace(trim($key), '[已隐藏密钥]', (string) $out['message']);
            return $out['ok'] ? $this->page(['text' => $message, 'key' => '']) : $this->page(['text' => '', 'key' => ''], $message);
        } catch (\Throwable $e) {
            return $this->back('推送处理异常，请先在目标站核对版本与更新状态，再决定是否重试。', true);
        } finally {
            unset($key);
        }
    }

    private function guardPost(): ?Response
    {
        if (($r = $this->guard()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!$req->isPost()) {
            return Response::text('仅支持 POST。', 405)->withHeader('Allow', 'POST');
        }
        $csrf = $req->post('_csrf');
        if (!is_string($csrf) || !Auth::checkCsrf($csrf)) {
            return Response::text('页面已过期或 CSRF 校验失败，请返回更新页面重试。', 403);
        }
        return null;
    }

    private function guard(): ?Response
    {
        $user = Auth::user();
        if ($user === null) {
            return Response::redirect(path('/login') . '?next=' . rawurlencode('/admin/update'), 302);
        }
        if ((string) $user['role'] !== 'admin') {
            return new Response(View::render('error', [
                'title' => '没有权限', 'code' => 403, 'reason' => '这个页面只给管理员用。',
            ]), 403);
        }
        return null;
    }

    private function back(string $message, bool $isError = false): Response
    {
        return Response::redirect(path('/admin/update') . '?' . ($isError ? 'err' : 'msg') . '=' . rawurlencode($message), 303)
            ->withHeader('Cache-Control', 'no-store');
    }
}
