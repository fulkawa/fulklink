<?php
declare(strict_types=1);

namespace App;

/** 签名只授权程序更新；客户数据、数据库结构和客户身份不在在线更新范围内。 */
final class Updater
{
    public const DEFAULT_SOURCE = 'https://lp.fulkteam.cn/update.json';
    private const MAX_BYTES = 8388608;
    private const MAX_TOTAL = 50331648;
    private const MAX_FILES = 2000;
    private const VERSION_PATTERN = '/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?\z/D';
    private const ROOT_FILES = ['VERSION', 'MANIFEST.json', 'CHANGELOG.md', 'README.md', 'LICENSE'];
    private const REQUIRED = ['VERSION', 'MANIFEST.json', 'public/index.php', 'public/install.php', 'install/index.php', 'app/bootstrap.php', 'app/helpers.php', 'app/routes.php', 'app/Updater.php', 'app/Migrator.php', 'app/Settings.php', 'app/Db.php', 'config/config.php', 'database/schema.sql'];

    public static function edition(): string
    {
        $file = BASE_PATH . '/MANIFEST.json';
        self::assertTarget('MANIFEST.json');
        if (!is_file($file)) {
            return 'commercial';
        }
        $manifest = self::jsonFile($file);
        if (!in_array($manifest['edition'] ?? null, ['open', 'commercial'], true)) {
            throw new \RuntimeException('本机 MANIFEST.edition 无效，请人工检查。');
        }
        return $manifest['edition'];
    }

    public static function source(): string
    {
        if (Settings::get('update.source_disabled', '0') === '1') {
            return '';
        }
        return trim(Settings::get('update.source', self::edition() === 'open' ? self::DEFAULT_SOURCE : ''));
    }

    public static function pushEnabled(): bool
    {
        return Settings::get('update.push_enabled', '0') === '1' && self::pushKey() !== '';
    }

    public static function pushKey(): string
    {
        return trim(Settings::get('update.push_key', ''));
    }

    public static function currentVersion(): string
    {
        self::assertTarget('VERSION');
        $value = @file_get_contents(BASE_PATH . '/VERSION');
        if ($value === false || !self::version(trim($value))) {
            throw new \RuntimeException('本机 VERSION 缺失或无效，不能在线更新。');
        }
        return trim($value);
    }

    private static function version($value): bool
    {
        return is_string($value) && strlen($value) <= 100 && preg_match(self::VERSION_PATTERN, $value) === 1;
    }

    private static function sha($value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-fA-F]{64}\z/D', $value) === 1;
    }

    private static function dir(string $sub = ''): string
    {
        $rel = 'data/updates' . ($sub === '' ? '' : '/' . $sub);
        self::assertTarget($rel);
        $dir = BASE_PATH . '/' . $rel;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('更新目录不可写：' . $rel);
        }
        return $dir;
    }

    public static function latest(bool $force = false): array
    {
        try {
            $url = self::source();
            if ($url === '') {
                throw new \RuntimeException('未配置更新源或已关闭拉取更新。');
            }
            $file = self::dir() . '/latest-' . hash('sha256', self::edition() . "\n" . $url) . '.json';
            if (!$force && is_file($file) && time() - (int) filemtime($file) < 21600) {
                return self::shape(self::jsonFile($file), true);
            }
            $raw = json_decode(self::https($url, 1048576), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($raw)) {
                throw new \RuntimeException('更新清单不是 JSON 对象。');
            }
            $data = self::parse($raw);
            $result = self::shape($data, false);
            self::atomic($file, self::json($data));
            return $result;
        } catch (\Throwable $e) {
            return ['ok' => false, 'data' => [], 'cached' => false, 'error' => $e->getMessage()];
        }
    }

    private static function parse(array $raw): array
    {
        if (isset($raw['version'], $raw['url'])) {
            return $raw;
        }
        if (isset($raw['tag_name']) && is_string($raw['tag_name'])) {
            foreach (($raw['assets'] ?? []) as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = $asset['name'] ?? '';
                if (!is_string($name) || !preg_match('/\Afulklink-' . self::edition() . '-[^\/]+\.zip\z/D', $name)) {
                    continue;
                }
                // 仅 ZIP 资产自身的 digest 可信，.sha256 附件的 digest 是附件的哈希。
                $digest = $asset['digest'] ?? '';
                return [
                    'version' => ltrim($raw['tag_name'], 'vV'), 'edition' => self::edition(),
                    'url' => $asset['browser_download_url'] ?? '',
                    'sha256' => is_string($digest) && str_starts_with($digest, 'sha256:') ? substr($digest, 7) : '',
                    'bytes' => $asset['size'] ?? 0, 'min_php' => '8.0',
                    'notes' => $raw['body'] ?? '', 'published_at' => $raw['published_at'] ?? '',
                ];
            }
        }
        throw new \RuntimeException('清单没有找到本机版本类型对应的更新包。');
    }

    private static function checkMeta(array $meta): void
    {
        if (!self::version($meta['version'] ?? null) || !self::sha($meta['sha256'] ?? null)
            || !is_int($meta['bytes'] ?? null) || $meta['bytes'] <= 0 || $meta['bytes'] > self::MAX_BYTES
            || ($meta['edition'] ?? null) !== self::edition() || !empty($meta['force'])) {
            throw new \RuntimeException('清单版本、edition、SHA256 或字节数无效；不支持强制更新。');
        }
    }

    private static function shape(array $data, bool $cached): array
    {
        self::checkMeta($data);
        if (!is_string($data['url'] ?? null)) {
            throw new \RuntimeException('清单缺少下载地址。');
        }
        self::urlParts($data['url']);
        foreach (['min_php' => '8.0', 'notes' => '', 'published_at' => ''] as $key => $default) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $default;
            }
            if (!is_string($data[$key])) {
                throw new \RuntimeException('清单字段类型无效：' . $key);
            }
        }
        if (!preg_match('/\A\d+\.\d+(?:\.\d+)?\z/D', $data['min_php'])) {
            throw new \RuntimeException('清单 min_php 格式无效。');
        }
        $cur = self::currentVersion();
        $data['current'] = $cur;
        $data['newer'] = version_compare($data['version'], $cur, '>');
        $data['php_ok'] = version_compare(PHP_VERSION, (string) ($data['min_php'] ?? '8.0'), '>=');
        $data['zip_ok'] = class_exists('ZipArchive');
        $data['writable'] = is_writable(BASE_PATH . '/app') && is_writable(self::dir());
        return ['ok' => true, 'cached' => $cached, 'error' => '', 'data' => $data];
    }

    private static function urlParts(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])
            || isset($p['user']) || isset($p['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || (isset($p['port']) && $p['port'] !== 443)) {
            throw new \RuntimeException('更新地址仅支持无账号信息的 HTTPS（443 端口）。');
        }
        return $p;
    }

    /** 手动逐跳校验并固定 DNS 结果，防止重定向/重绑定进入内网。 */
    private static function https(string $url, int $limit): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP 未安装 cURL 扩展。');
        }
        $deadline = microtime(true) + 90;
        for ($hop = 0; $hop <= 5; $hop++) {
            $p = self::urlParts($url);
            $host = trim($p['host'], '[]');
            $ips = [];
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $ips[] = $host;
            } else {
                foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ip']) || isset($record['ipv6'])) {
                        $ips[] = $record['ip'] ?? $record['ipv6'];
                    }
                }
            }
            if ($ips === []) {
                throw new \RuntimeException('更新地址 DNS 解析失败。');
            }
            foreach ($ips as $ip) {
                $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
                if (str_contains($ip, ':')) {
                    $packed = inet_pton($ip);
                    $public = $public && $packed !== false && (ord($packed[0]) & 0xe0) === 0x20;
                } else {
                    $octets = array_map('intval', explode('.', $ip));
                    $public = $public && $octets[0] > 0 && $octets[0] < 224
                        && !($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127)
                        && !($octets[0] === 198 && in_array($octets[1], [18, 19], true))
                        && !($octets[0] === 192 && $octets[1] === 0 && in_array($octets[2], [0, 2], true))
                        && !($octets[0] === 198 && $octets[1] === 51 && $octets[2] === 100)
                        && !($octets[0] === 203 && $octets[1] === 0 && $octets[2] === 113);
                }
                if (!$public) {
                    throw new \RuntimeException('更新地址不能指向私有或保留地址。');
                }
            }
            $body = '';
            $location = '';
            $remaining = (int) ceil($deadline - microtime(true));
            if ($remaining <= 0) {
                throw new \RuntimeException('更新下载超时。');
            }
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('无法初始化下载。');
            }
            $ip = $ips[0];
            $options = [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => min(10, $remaining),
                CURLOPT_TIMEOUT => $remaining, CURLOPT_USERAGENT => 'FulkLink-Updater/' . self::currentVersion(),
                CURLOPT_HTTPHEADER => ['Accept: application/json, application/zip'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
                    if (strlen($body) + strlen($chunk) > $limit) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                    if (stripos($line, 'Location:') === 0) {
                        $location = trim(substr($line, 9));
                    }
                    return strlen($line);
                },
            ];
            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                $options[CURLOPT_RESOLVE] = [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
            }
            curl_setopt_array($ch, $options);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($ok === false) {
                throw new \RuntimeException('HTTPS 下载失败或超过大小上限：' . $error);
            }
            if (in_array($code, [301, 302, 303, 307, 308], true) && $location !== '' && $hop < 5) {
                if (str_starts_with($location, '//')) {
                    $url = 'https:' . $location;
                } elseif (str_starts_with($location, '/')) {
                    $url = 'https://' . $p['host'] . $location;
                } elseif (parse_url($location, PHP_URL_SCHEME) === null) {
                    $path = $p['path'] ?? '/';
                    $url = 'https://' . $p['host'] . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
                } else {
                    $url = $location;
                }
                continue;
            }
            if ($code !== 200 || $body === '') {
                throw new \RuntimeException('HTTPS 下载返回 HTTP ' . $code . '。');
            }
            return $body;
        }
        throw new \RuntimeException('HTTPS 重定向次数超过上限。');
    }

    public static function download(string $url, int $expectBytes = 0): array
    {
        try {
            if ($expectBytes <= 0 || $expectBytes > self::MAX_BYTES) {
                throw new \RuntimeException('下载必须提供有效的包字节数。');
            }
            $body = self::https($url, $expectBytes);
            if (strlen($body) !== $expectBytes) {
                throw new \RuntimeException('下载文件长度与清单不一致。');
            }
            $file = self::dir('tmp') . '/pkg-' . bin2hex(random_bytes(12)) . '.zip';
            self::atomic($file, $body);
            return ['ok' => true, 'file' => $file, 'error' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'file' => '', 'error' => $e->getMessage()];
        }
    }

    /** 持久状态是恢复依据，绝不从 glob 猜测“最近备份”。 */
    public static function status(): array
    {
        $file = self::dir() . '/state.json';
        return is_file($file) ? self::jsonFile($file) : ['status' => 'idle'];
    }

    private static function state(array $state): void
    {
        $state['at'] = date('c');
        self::atomic(self::dir() . '/state.json', self::json($state));
    }

    private static function lock()
    {
        $path = self::dir() . '/install.lock';
        self::assertTarget('data/updates/install.lock');
        $fp = @fopen($path, 'c');
        if ($fp === false) {
            throw new \RuntimeException('无法创建安装互斥锁。');
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            throw new \RuntimeException('另一个安装或回滚正在进行。');
        }
        return $fp;
    }

    private static function runtimeLock()
    {
        if (isset($GLOBALS['fulklink_runtime_lock']) && is_resource($GLOBALS['fulklink_runtime_lock'])) {
            flock($GLOBALS['fulklink_runtime_lock'], LOCK_UN);
            fclose($GLOBALS['fulklink_runtime_lock']);
            unset($GLOBALS['fulklink_runtime_lock']);
        }
        self::assertTarget('data/updates/runtime.lock');
        $fp = @fopen(self::dir() . '/runtime.lock', 'c');
        if ($fp === false) {
            throw new \RuntimeException('无法创建运行期互斥锁。');
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            throw new \RuntimeException('还有其它请求正在运行，请稍后重试更新。');
        }
        return $fp;
    }

    private static function unlock($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function finishRuntime($fp): void
    {
        if (!is_resource($fp)) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            self::unlock($fp);
            return;
        }
        // 返回控制器后仍可能自动加载类/视图；保留读锁直到请求结束。
        // 降级失败时保留原 EX 锁，而不是留下无锁的混合版本窗口。
        @flock($fp, LOCK_SH);
        $GLOBALS['fulklink_runtime_lock'] = $fp;
    }

    public static function install(string $zipFile, array $meta = []): array
    {
        $lock = $runtime = null;
        $backup = '';
        $started = false;
        $written = 0;
        try {
            $lock = self::lock();
            $previous = self::status();
            if (!in_array($previous['status'] ?? '', ['idle', 'installed', 'rolled_back'], true)) {
                throw new \RuntimeException('上次更新未完成（' . ($previous['status'] ?? '未知') . '），请先回滚恢复，禁止继续安装。');
            }
            self::checkMeta($meta);
            if (!version_compare($meta['version'], self::currentVersion(), '>')) {
                throw new \RuntimeException('只允许安装严格高于当前版本的更新，不支持重装或降级。');
            }
            if (!is_file($zipFile) || is_link($zipFile) || filesize($zipFile) !== $meta['bytes']
                || !hash_equals(strtolower($meta['sha256']), (string) hash_file('sha256', $zipFile))) {
                throw new \RuntimeException('更新包长度或 SHA256 校验失败。');
            }
            $entries = self::package($zipFile, $meta);
            $runtime = self::runtimeLock();
            // 等待锁期间不能信任先前的磁盘版本和路径检查。
            if (!version_compare($meta['version'], self::currentVersion(), '>')) {
                throw new \RuntimeException('磁盘版本已经变更，请重新检查更新。');
            }
            foreach (array_keys($entries) as $rel) {
                self::assertTarget($rel);
            }
            $before = self::currentVersion();
            $backup = self::backup($entries, $before, $meta['version']);
            $journal = ['status' => 'applying', 'backup' => basename($backup), 'before' => $before, 'after' => $meta['version'], 'files' => count($entries)];
            self::state($journal);
            $started = true;
            foreach (self::ordered($entries) as $rel => $content) {
                self::writeProgram($rel, $content);
                $written++;
            }
            $journal['status'] = 'installed';
            self::state($journal);
            $started = false;
            $warning = self::record('update.install', '更新到 ' . $meta['version'], basename($backup), $meta['version']);
            return ['ok' => true, 'message' => '已更新到 ' . $meta['version'] . '，覆盖 ' . $written . ' 个文件。未执行数据库迁移。' . $warning, 'files' => $written, 'backup' => basename($backup)];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($started) {
                try {
                    self::state(['status' => 'rolling_back', 'backup' => basename($backup), 'error' => $message]);
                    $restored = self::restore($backup);
                    self::state(['status' => 'rolled_back', 'backup' => basename($backup), 'before' => $restored['before'], 'after' => $restored['after'], 'error' => $message]);
                    $message .= '；文件已自动回滚，数据库未变更。';
                } catch (\Throwable $restoreError) {
                    $message .= '；自动恢复未完成：' . $restoreError->getMessage() . '。请重试回滚或人工从已验证备份恢复。';
                    try {
                        self::state(['status' => 'recovery_required', 'backup' => basename($backup), 'error' => $message]);
                    } catch (\Throwable $stateError) {
                        $message .= ' 状态写入也失败：' . $stateError->getMessage();
                    }
                }
            }
            return ['ok' => false, 'message' => $message, 'files' => $written, 'backup' => $backup === '' ? '' : basename($backup)];
        } finally {
            self::finishRuntime($runtime);
            self::unlock($lock);
        }
    }

    private static function package(string $file, array $meta): array
    {
        $all = self::readZip($file);
        foreach (self::REQUIRED as $required) {
            if (!isset($all[$required])) {
                throw new \RuntimeException('不是完整更新包，缺少 ' . $required);
            }
        }
        $manifest = json_decode($all['MANIFEST.json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['product'] ?? null) !== 'fulklink'
            || ($manifest['edition'] ?? null) !== self::edition() || ($manifest['edition'] ?? null) !== $meta['edition']
            || ($manifest['version'] ?? null) !== $meta['version'] || trim($all['VERSION']) !== $meta['version']
            || !is_array($manifest['files'] ?? null)) {
            throw new \RuntimeException('包内 VERSION/MANIFEST/product/edition 与更新清单不一致。');
        }
        if (!is_string($manifest['php_min'] ?? null) || version_compare(PHP_VERSION, $manifest['php_min'], '<')) {
            throw new \RuntimeException('当前 PHP 版本不满足包的要求。');
        }
        $listed = [];
        foreach ($manifest['files'] as $item) {
            if (!is_array($item) || !is_string($item['path'] ?? null) || $item['path'] === 'MANIFEST.json'
                || isset($listed[$item['path']]) || !array_key_exists($item['path'], $all)
                || !is_int($item['bytes'] ?? null) || !self::sha($item['sha256'] ?? null)) {
                throw new \RuntimeException('MANIFEST.files 存在无效、重复或缺失条目。');
            }
            $rel = $item['path'];
            if (strlen($all[$rel]) !== $item['bytes'] || !hash_equals(strtolower($item['sha256']), hash('sha256', $all[$rel]))) {
                throw new \RuntimeException('包内文件长度或哈希错误：' . $rel);
            }
            $listed[$rel] = true;
        }
        $entries = [];
        foreach ($all as $rel => $content) {
            if ($rel !== 'MANIFEST.json' && !isset($listed[$rel])) {
                throw new \RuntimeException('包内文件未列入 MANIFEST：' . $rel);
            }
            if (self::ignored($rel)) {
                continue;
            }
            if (self::safePath($rel) === null) {
                throw new \RuntimeException('更新包包含不允许安装的路径：' . $rel);
            }
            self::assertTarget($rel);
            $entries[$rel] = $content;
        }
        $oldMigrator = @hash_file('sha256', BASE_PATH . '/app/Migrator.php');
        if ($oldMigrator === false || !hash_equals($oldMigrator, hash('sha256', $entries['app/Migrator.php']))) {
            throw new \RuntimeException('数据库迁移代码发生变化，必须先走人工数据库迁移流程，不能在线覆盖。');
        }
        foreach ($entries as $rel => $content) {
            if (str_starts_with($rel, 'database/') && (!is_file(BASE_PATH . '/' . $rel)
                || !hash_equals((string) hash_file('sha256', BASE_PATH . '/' . $rel), hash('sha256', $content)))) {
                throw new \RuntimeException('数据库定义发生变化，需人工数据库迁移：' . $rel);
            }
        }
        // 通用升级包不能清除客户身份；重建落盘清单使保留文件仍可校验。
        $local = is_file(BASE_PATH . '/MANIFEST.json') ? self::jsonFile(BASE_PATH . '/MANIFEST.json') : [];
        unset($manifest['watermark']);
        if (isset($local['watermark'])) {
            $manifest['watermark'] = $local['watermark'];
        }
        $fileList = [];
        foreach ($manifest['files'] as $item) {
            $rel = $item['path'];
            if (self::ignored($rel)) {
                self::assertTarget($rel);
                if (!is_file(BASE_PATH . '/' . $rel)) {
                    continue;
                }
                $item['bytes'] = filesize(BASE_PATH . '/' . $rel);
                $item['sha256'] = hash_file('sha256', BASE_PATH . '/' . $rel);
                if ($item['bytes'] === false || $item['sha256'] === false) {
                    throw new \RuntimeException('无法读取保留文件：' . $rel);
                }
            }
            $fileList[$rel] = $item;
        }
        foreach (['app/Watermark.php', 'PURCHASE-INFO.txt'] as $rel) {
            self::assertTarget($rel);
            if (is_file(BASE_PATH . '/' . $rel)) {
                $fileList[$rel] = ['path' => $rel, 'bytes' => filesize(BASE_PATH . '/' . $rel), 'sha256' => hash_file('sha256', BASE_PATH . '/' . $rel)];
            }
        }
        ksort($fileList, SORT_STRING);
        $lines = '';
        $bytes = 0;
        foreach ($fileList as $item) {
            $lines .= $item['path'] . ':' . $item['sha256'] . "\n";
            $bytes += $item['bytes'];
        }
        $manifest['files'] = array_values($fileList);
        $manifest['total_files'] = count($fileList);
        $manifest['total_bytes'] = $bytes;
        $manifest['root_sha256'] = hash('sha256', $lines);
        $entries['MANIFEST.json'] = self::json($manifest);
        return $entries;
    }

    private static function readZip(string $file, bool $backup = false): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP 未安装 zip 扩展。');
        }
        if (filesize($file) > ($backup ? self::MAX_TOTAL + self::MAX_BYTES : self::MAX_BYTES)) {
            throw new \RuntimeException('ZIP 大小超过上限。');
        }
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('ZIP 无效或已损坏。');
        }
        try {
            if ($zip->numFiles > self::MAX_FILES + ($backup ? 1 : 0)) {
                throw new \RuntimeException('ZIP 条目超过允许上限。');
            }
            $stats = $seen = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || !is_string($stat['name'] ?? null)) {
                    throw new \RuntimeException('ZIP 条目读取失败。');
                }
                $name = $stat['name'];
                $key = str_ends_with($name, '/') ? substr($name, 0, -1) : $name;
                if (!self::validPath($key) || isset($seen[strtolower($key)])) {
                    throw new \RuntimeException('ZIP 包含非法或重复路径：' . $name);
                }
                $seen[strtolower($key)] = true;
                $opsys = $attr = 0;
                if (!$zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                    throw new \RuntimeException('无法检查 ZIP 文件类型。');
                }
                $type = ($attr >> 16) & 0170000;
                if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                    throw new \RuntimeException('ZIP 禁止软链和特殊文件：' . $name);
                }
                $size = $stat['size'] ?? -1;
                if (!is_int($size) || $size < 0 || $size > self::MAX_BYTES || ($total += $size) > self::MAX_TOTAL
                    || (($stat['encryption_method'] ?? 0) !== 0)) {
                    throw new \RuntimeException('ZIP 文件大小、展开总量或加密方式不允许。');
                }
                if (str_ends_with($name, '/')) {
                    if ($size !== 0) {
                        throw new \RuntimeException('ZIP 目录不能包含数据。');
                    }
                    continue;
                }
                if ($type === 0040000) {
                    throw new \RuntimeException('ZIP 目录标记不一致。');
                }
                $stats[] = [$i, $name, $size];
            }
            $fileNames = [];
            foreach ($stats as [$index, $name, $size]) {
                $fileNames[strtolower($name)] = true;
            }
            foreach (array_keys($seen) as $name) {
                $parent = dirname($name);
                while ($parent !== '.') {
                    if (isset($fileNames[$parent])) {
                        throw new \RuntimeException('ZIP 文件与目录路径冲突：' . $name);
                    }
                    $parent = dirname($parent);
                }
            }
            $out = [];
            foreach ($stats as [$index, $name, $size]) {
                $body = $zip->getFromIndex($index, $size + 1);
                if ($body === false || strlen($body) !== $size) {
                    throw new \RuntimeException('ZIP 解压长度不一致：' . $name);
                }
                $out[$name] = $body;
            }
            return $out;
        } finally {
            if (!$zip->close()) {
                throw new \RuntimeException('ZIP 关闭或完整性检查失败。');
            }
        }
    }

    private static function validPath(string $path): bool
    {
        if ($path === '' || strlen($path) > 500 || preg_match('/[\x00-\x1f\x7f:\\\\]/', $path) || $path[0] === '/') {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[. ]\z/D', $part)
                || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|\z)/i', $part)) {
                return false;
            }
        }
        return true;
    }

    private static function ignored(string $rel): bool
    {
        // 在线升级不能重新放回站长已删除的安装器，也不修改站点身份与用户数据。
        return in_array($rel, ['.env.example', 'data/.gitkeep', 'app/Watermark.php', 'PURCHASE-INFO.txt', 'public/install.php'], true)
            || str_starts_with($rel, 'docs/') || str_starts_with($rel, 'install/');
    }

    private static function safePath(string $rel): ?string
    {
        // safePath 定义受管程序范围；在线安装阶段另用 ignored() 跳过安装器。
        // 这样旧版产生的已验证备份仍能恢复原有安装器，而更新不会重新安装它。
        $legacyInstaller = $rel === 'public/install.php' || str_starts_with($rel, 'install/');
        if (!self::validPath($rel) || (self::ignored($rel) && !$legacyInstaller)) {
            return null;
        }
        foreach (explode('/', strtolower($rel)) as $part) {
            if (str_starts_with($part, '.env') || in_array($part, ['watermark.php', 'license.json', 'license.key', 'license.dat', 'license.pem', 'purchase-info.txt'], true)) {
                return null;
            }
        }
        if (in_array($rel, self::ROOT_FILES, true)) {
            return $rel;
        }
        return preg_match('#\A(?:app|public|config|install|database)/.+\z#D', $rel) ? $rel : null;
    }

    private static function assertTarget(string $rel): void
    {
        if (!self::validPath($rel)) {
            throw new \RuntimeException('非法目标路径。');
        }
        $path = rtrim(BASE_PATH, '/\\');
        if (is_link($path)) {
            throw new \RuntimeException('程序根目录不能是软链。');
        }
        $parts = explode('/', $rel);
        foreach ($parts as $i => $part) {
            $path .= '/' . $part;
            clearstatcache(true, $path);
            if (is_link($path) || (file_exists($path) && $i < count($parts) - 1 && !is_dir($path))) {
                throw new \RuntimeException('目标路径包含软链或非目录：' . $rel);
            }
        }
    }

    private static function ordered(array $entries): array
    {
        $tail = [];
        foreach (['VERSION', 'MANIFEST.json'] as $name) {
            if (array_key_exists($name, $entries)) {
                $tail[$name] = $entries[$name];
                unset($entries[$name]);
            }
        }
        ksort($entries, SORT_STRING);
        return $entries + $tail;
    }

    private static function backup(array $entries, string $before, string $after): string
    {
        $file = self::dir('backups') . '/backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('无法创建备份。');
        }
        $meta = ['product' => 'fulklink-backup', 'before' => $before, 'after' => $after, 'files' => [], 'newPaths' => []];
        $total = 0;
        try {
            foreach ($entries as $rel => $unused) {
                self::assertTarget($rel);
                $abs = BASE_PATH . '/' . $rel;
                if (!file_exists($abs)) {
                    $meta['newPaths'][] = $rel;
                    continue;
                }
                if (!is_file($abs)) {
                    throw new \RuntimeException('目标不是普通文件：' . $rel);
                }
                $size = filesize($abs);
                $sha = hash_file('sha256', $abs);
                if ($size === false || $size > self::MAX_BYTES || ($total += $size) > self::MAX_TOTAL - 1048576 || $sha === false || !$zip->addFile($abs, $rel)) {
                    throw new \RuntimeException('备份读取失败或超过大小上限：' . $rel);
                }
                $meta['files'][] = ['path' => $rel, 'bytes' => $size, 'sha256' => $sha];
            }
            if (!$zip->addFromString('__backup.json', self::json($meta))) {
                throw new \RuntimeException('备份元数据写入失败。');
            }
        } catch (\Throwable $e) {
            $zip->close();
            throw $e;
        }
        if (!$zip->close()) {
            throw new \RuntimeException('备份 ZIP 保存失败，尚未覆盖程序。');
        }
        self::verifiedBackup($file);
        return $file;
    }

    private static function verifiedBackup(string $file): array
    {
        if (!preg_match('/\Abackup-\d{8}-\d{6}-[a-f0-9]{16}\.zip\z/D', basename($file))) {
            throw new \RuntimeException('备份名称非法。');
        }
        self::assertTarget('data/updates/backups/' . basename($file));
        $entries = self::readZip($file, true);
        $meta = json_decode($entries['__backup.json'] ?? '', true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($meta) || ($meta['product'] ?? null) !== 'fulklink-backup' || !self::version($meta['before'] ?? null)
            || !self::version($meta['after'] ?? null) || !is_array($meta['files'] ?? null) || !is_array($meta['newPaths'] ?? null)) {
            throw new \RuntimeException('备份元数据无效。');
        }
        unset($entries['__backup.json']);
        $seen = [];
        foreach ($meta['files'] as $item) {
            $rel = is_array($item) ? ($item['path'] ?? null) : null;
            if (!is_string($rel) || self::safePath($rel) === null || isset($seen[$rel]) || !array_key_exists($rel, $entries)
                || !is_int($item['bytes'] ?? null) || strlen($entries[$rel]) !== $item['bytes'] || !self::sha($item['sha256'] ?? null)
                || !hash_equals(strtolower($item['sha256']), hash('sha256', $entries[$rel]))) {
                throw new \RuntimeException('备份文件校验失败。');
            }
            self::assertTarget($rel);
            $seen[$rel] = true;
        }
        if (count($seen) !== count($entries)) {
            throw new \RuntimeException('备份存在未登记文件。');
        }
        foreach ($meta['newPaths'] as $rel) {
            if (!is_string($rel) || self::safePath($rel) === null || isset($seen[$rel])) {
                throw new \RuntimeException('备份新增路径记录无效。');
            }
            self::assertTarget($rel);
            $seen[$rel] = true;
        }
        return [$meta, $entries];
    }

    private static function restore(string $file): array
    {
        [$meta, $entries] = self::verifiedBackup($file);
        $tail = [];
        foreach (self::ordered($entries) as $rel => $content) {
            if (in_array($rel, ['VERSION', 'MANIFEST.json'], true)) {
                $tail[$rel] = $content;
            } else {
                self::writeProgram($rel, $content);
            }
        }
        foreach ($meta['newPaths'] as $rel) {
            self::assertTarget($rel);
            $abs = BASE_PATH . '/' . $rel;
            if (file_exists($abs)) {
                if (!is_file($abs)) {
                    throw new \RuntimeException('无法移除新增文件：' . $rel);
                }
                self::invalidate($abs);
                if (!@unlink($abs)) {
                    throw new \RuntimeException('删除新增文件失败：' . $rel);
                }
                clearstatcache(true, $abs);
            }
        }
        foreach ($tail as $rel => $content) {
            self::writeProgram($rel, $content);
        }
        return $meta;
    }

    public static function rollback(): array
    {
        $lock = $runtime = null;
        try {
            $lock = self::lock();
            $state = self::status();
            if (!in_array($state['status'] ?? '', ['installed', 'applying', 'rolling_back', 'recovery_required'], true)
                || !is_string($state['backup'] ?? null) || basename($state['backup']) !== $state['backup']) {
                throw new \RuntimeException('没有可恢复的已验证更新记录；已回滚的操作不能再次回滚。');
            }
            $file = self::dir('backups') . '/' . $state['backup'];
            self::verifiedBackup($file);
            $runtime = self::runtimeLock();
            $state['status'] = 'rolling_back';
            self::state($state);
            $meta = self::restore($file);
            $state['status'] = 'rolled_back';
            $state['before'] = $meta['before'];
            $state['after'] = $meta['after'];
            unset($state['error']);
            self::state($state);
            $warning = self::record('update.rollback', '恢复到 ' . $meta['before'], $state['backup'], $meta['before']);
            return ['ok' => true, 'message' => '程序文件已恢复到 ' . $meta['before'] . '，本次新增文件已移除；此操作不回滚数据库。' . $warning];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '回滚未完成：' . $e->getMessage() . '。原备份保留；如已开始恢复，请修复问题后再次回滚。'];
        } finally {
            self::finishRuntime($runtime);
            self::unlock($lock);
        }
    }

    public static function backups(): array
    {
        $out = [];
        foreach (glob(self::dir('backups') . '/backup-*.zip') ?: [] as $file) {
            if (!is_link($file) && is_file($file)) {
                $out[] = ['name' => basename($file), 'size' => (int) filesize($file), 'at' => date('Y-m-d H:i:s', (int) filemtime($file))];
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['name'], $a['name']));
        return array_slice($out, 0, 8);
    }

    private static function writeProgram(string $rel, string $content): void
    {
        if (self::safePath($rel) === null) {
            throw new \RuntimeException('拒绝写入非程序文件。');
        }
        self::assertTarget($rel);
        $abs = BASE_PATH . '/' . $rel;
        self::atomic($abs, $content);
        self::invalidate($abs);
    }

    private static function invalidate(string $file): void
    {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php' || !function_exists('opcache_get_status')) {
            return;
        }
        $enabled = ini_get(PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable');
        if (!filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        if (!function_exists('opcache_invalidate') || !@opcache_invalidate($file, true)) {
            throw new \RuntimeException('OPcache 缓存刷新失败：' . basename($file) . '，需要人工刷新 PHP 缓存后恢复。');
        }
    }

    /** 同目录临时文件加 rename；不采用先删除旧文件的非原子降级。 */
    private static function atomic(string $file, string $content): void
    {
        $root = rtrim(str_replace('\\', '/', BASE_PATH), '/') . '/';
        $normalized = str_replace('\\', '/', $file);
        if (!str_starts_with($normalized, $root)) {
            throw new \RuntimeException('写入超出程序目录。');
        }
        self::assertTarget(substr($normalized, strlen($root)));
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建目标目录。');
        }
        $tmp = $dir . '/.update-' . bin2hex(random_bytes(12)) . '.tmp';
        $fp = @fopen($tmp, 'xb');
        if ($fp === false) {
            throw new \RuntimeException('无法创建同目录临时文件。');
        }
        try {
            $length = strlen($content);
            $offset = 0;
            while ($offset < $length) {
                $n = fwrite($fp, substr($content, $offset));
                if ($n === false || $n === 0) {
                    throw new \RuntimeException('写入文件不完整。');
                }
                $offset += $n;
            }
            if (!fflush($fp) || (function_exists('fsync') && !fsync($fp))) {
                throw new \RuntimeException('文件同步失败。');
            }
            if (!fclose($fp)) {
                $fp = null;
                throw new \RuntimeException('临时文件关闭失败。');
            }
            $fp = null;
            $mode = is_file($file) ? (fileperms($file) & 0777) : 0644;
            if (!@chmod($tmp, $mode) || !@rename($tmp, $file)) {
                throw new \RuntimeException('原子替换失败：' . basename($file));
            }
            clearstatcache(true, $file);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function jsonFile(string $file): array
    {
        $root = rtrim(str_replace('\\', '/', BASE_PATH), '/') . '/';
        $path = str_replace('\\', '/', $file);
        if (!str_starts_with($path, $root)) {
            throw new \RuntimeException('JSON 路径越界。');
        }
        self::assertTarget(substr($path, strlen($root)));
        $raw = @file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('JSON 文件无法读取或过大：' . basename($file));
        }
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('JSON 文件格式无效：' . basename($file));
        }
        return $data;
    }

    private static function record(string $event, string $message, string $backup, string $version): string
    {
        $warnings = [];
        try {
            Settings::set('update.last_version', $version);
            Settings::set('update.last_at', date('Y-m-d H:i:s'));
            Settings::set('update.last_backup', $backup);
            Settings::flush();
        } catch (\Throwable $e) {
            $warnings[] = '设置记录失败：' . $e->getMessage();
        }
        try {
            Db::log($event, $message, ['backup' => $backup]);
        } catch (\Throwable $e) {
            $warnings[] = '审计日志失败：' . $e->getMessage();
        }
        return $warnings === [] ? '' : ' 文件操作已完成，但' . implode('；', $warnings) . '。';
    }

    public static function verifyPush(array $in): string
    {
        try {
            if (!self::pushEnabled()) {
                throw new \RuntimeException('这个站点没有开启云端推送。');
            }
            $now = time();
            $ts = $in['ts'] ?? null;
            $nonce = $in['nonce'] ?? null;
            $edition = $in['edition'] ?? null;
            $version = $in['version'] ?? null;
            $sha = $in['sha256'] ?? null;
            $sig = $in['sig'] ?? null;
            if (!is_int($ts) || $ts < $now - 300 || $ts > $now + 300 || !is_string($nonce)
                || !preg_match('/\A[0-9a-f]{32}\z/D', $nonce) || !in_array($edition, ['open', 'commercial'], true)
                || !self::version($version) || !self::sha($sha) || !self::sha($sig) || !empty($in['force'])) {
                throw new \RuntimeException('推送参数格式或时间窗无效；不支持强制更新。');
            }
            $expected = hash_hmac('sha256', "v2\n$ts\n$nonce\n$edition\n$version\n$sha", self::pushKey());
            if (!hash_equals($expected, strtolower($sig))) {
                throw new \RuntimeException('推送 v2 签名无效。');
            }
            $package = $in['package'] ?? null;
            if (!is_string($package) || strlen($package) > 4 * (int) ceil(self::MAX_BYTES / 3)) {
                throw new \RuntimeException('推送包过大或缺失。');
            }
            $raw = base64_decode($package, true);
            if ($raw === false || $raw === '' || strlen($raw) > self::MAX_BYTES || !hash_equals(strtolower($sha), hash('sha256', $raw))) {
                throw new \RuntimeException('推送包 base64、长度或 SHA256 校验失败。');
            }
            if (isset($in['bytes']) && (!is_int($in['bytes']) || $in['bytes'] !== strlen($raw))) {
                throw new \RuntimeException('推送包声明长度不一致。');
            }
            self::consumeNonce($nonce, $ts + 300);
            return '';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    private static function consumeNonce(string $nonce, int $expires): void
    {
        self::assertTarget('data/updates/nonces.lock');
        $lock = @fopen(self::dir() . '/nonces.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('无法创建防重放锁，拒绝推送。');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('无法锁定防重放记录，拒绝推送。');
            }
            $file = self::dir() . '/nonces.json';
            $list = is_file($file) ? self::jsonFile($file) : [];
            $now = time();
            foreach ($list as $key => $until) {
                if (!is_string($key) || !preg_match('/\A[0-9a-f]{32}\z/D', $key) || !is_int($until)) {
                    throw new \RuntimeException('防重放记录损坏，拒绝推送。');
                }
                if ($until < $now) {
                    unset($list[$key]);
                }
            }
            if (isset($list[$nonce])) {
                throw new \RuntimeException('这个 nonce 已使用，拒绝重放。');
            }
            $list[$nonce] = $expires;
            self::atomic($file, self::json($list));
        } finally {
            self::unlock($lock);
        }
    }

    public static function pushFile(string $base64): string
    {
        try {
            if (strlen($base64) > 4 * (int) ceil(self::MAX_BYTES / 3)) {
                return '';
            }
            $raw = base64_decode($base64, true);
            if ($raw === false || $raw === '' || strlen($raw) > self::MAX_BYTES) {
                return '';
            }
            $file = self::dir('tmp') . '/push-' . bin2hex(random_bytes(12)) . '.zip';
            self::atomic($file, $raw);
            if (filesize($file) !== strlen($raw)) {
                return '';
            }
            return $file;
        } catch (\Throwable $e) {
            return '';
        }
    }
}
