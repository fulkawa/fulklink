<?php
/**
 * 数据库结构升级。
 *
 * 客户覆盖升级时，老库不会自动长出新列，所以每次请求先比一个版本号文件，
 * 版本没变就只读一个小文件、不碰数据库；版本变了才逐个补列。
 * 补列前先看列在不在，重复执行不会报错。
 */
declare(strict_types=1);

namespace App;

final class Migrator
{
    /** 加一次结构变更就把这个数字加一 */
    public const VERSION = 8;

    private static function versionFile(): string
    {
        return BASE_PATH . '/data/.schema-ver';
    }

    public static function ensure(): void
    {
        $file = self::versionFile();
        $current = is_file($file) ? (int) trim((string) @file_get_contents($file)) : 0;
        if ($current >= self::VERSION) {
            return;
        }
        self::run($current);
        if (is_dir(dirname($file)) && is_writable(dirname($file))) {
            @file_put_contents($file, (string) self::VERSION);
        }
    }

    private static function run(int $from): void
    {
        // 2：官网下单要收邮箱和开票信息，老库补两列
        if ($from < 2 && self::hasTable('orders')) {
            self::addColumn('orders', 'email',
                "ALTER TABLE `orders` ADD COLUMN `email` VARCHAR(191) NOT NULL DEFAULT '' COMMENT '收授权码的邮箱' AFTER `contact`");
            self::addColumn('orders', 'remark',
                "ALTER TABLE `orders` ADD COLUMN `remark` VARCHAR(191) NOT NULL DEFAULT '' COMMENT '开票信息或留言' AFTER `email`");
        }

        // 3：账号体系上线，邮箱要允许空（手机号注册），授权要记录校验次数与绑定域名
        if ($from < 3) {
            if (self::hasTable('users')) {
                // email 从 NOT NULL 改成可空：手机号注册时这一列是 NULL，
                // 唯一索引对 NULL 不生效，多个手机号账号才能共存
                if (self::columnNullable('users', 'email') === false) {
                    self::tryExec(
                        "ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(191) NULL DEFAULT NULL COMMENT '邮箱注册填这里，手机注册留 NULL'",
                        'users.email 改为可空'
                    );
                }
                self::addColumn('users', 'phone',
                    "ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(32) NULL DEFAULT NULL COMMENT '手机注册填这里' AFTER `email`");
                self::addIndex('users', 'uk_phone', 'ALTER TABLE `users` ADD UNIQUE KEY `uk_phone` (`phone`)');
            }
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `verification_codes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target`     VARCHAR(191) NOT NULL,
  `channel`    VARCHAR(8)   NOT NULL,
  `purpose`    VARCHAR(24)  NOT NULL,
  `code_hash`  CHAR(64)     NOT NULL,
  `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target`, `purpose`, `created_at`),
  KEY `idx_ip` (`ip`, `created_at`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='一次性验证码'
SQL);
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `license_domains` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_id` INT UNSIGNED NOT NULL,
  `host`       VARCHAR(191) NOT NULL,
  `bound_at`   DATETIME NOT NULL,
  `last_seen_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license_host` (`license_id`, `host`),
  KEY `idx_host` (`host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='授权绑定域名'
SQL);
            if (self::hasTable('licenses')) {
                self::addColumn('licenses', 'verify_count',
                    "ALTER TABLE `licenses` ADD COLUMN `verify_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `source`");
                self::addColumn('licenses', 'last_verify_at',
                    'ALTER TABLE `licenses` ADD COLUMN `last_verify_at` DATETIME NULL AFTER `verify_count`');
                self::addColumn('licenses', 'activated_at',
                    'ALTER TABLE `licenses` ADD COLUMN `activated_at` DATETIME NULL AFTER `last_verify_at`');
                self::addColumn('licenses', 'note',
                    "ALTER TABLE `licenses` ADD COLUMN `note` VARCHAR(255) NOT NULL DEFAULT '' AFTER `activated_at`");
            }
        }

        // 4：云端能力上线。官网同时当云端用，客户站走 client 模式连回来
        if ($from < 4) {
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `cloud_assets` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`         VARCHAR(24)  NOT NULL,
  `version`      VARCHAR(32)  NOT NULL,
  `sha256`       CHAR(64)     NOT NULL,
  `bytes`        INT UNSIGNED NOT NULL DEFAULT 0,
  `items`        INT UNSIGNED NOT NULL DEFAULT 0,
  `payload`      MEDIUMTEXT   NULL,
  `active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `published_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_kind_ver` (`kind`, `version`),
  KEY `idx_kind_active` (`kind`, `active`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='云端下发的资源快照'
SQL);
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `cloud_geo_blocks` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_start`   BIGINT UNSIGNED NOT NULL,
  `ip_end`     BIGINT UNSIGNED NOT NULL,
  `country`    VARCHAR(32) NOT NULL DEFAULT '中国',
  `province`   VARCHAR(32) NOT NULL DEFAULT '',
  `city`       VARCHAR(32) NOT NULL DEFAULT '',
  `isp`        VARCHAR(32) NOT NULL DEFAULT '',
  `src`        VARCHAR(16) NOT NULL DEFAULT 'manual',
  `created_at` DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_start` (`ip_start`),
  KEY `idx_end` (`ip_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IP 归属地库'
SQL);
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `cloud_geo_unknown` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`        VARCHAR(45)  NOT NULL,
  `hits`      INT UNSIGNED NOT NULL DEFAULT 1,
  `status`    VARCHAR(16)  NOT NULL DEFAULT 'open',
  `result`    VARCHAR(128) NOT NULL DEFAULT '',
  `first_at`  DATETIME     NOT NULL,
  `last_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip` (`ip`),
  KEY `idx_status_hits` (`status`, `hits`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='待补齐的未知 IP'
SQL);
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `cloud_blocklist` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `host`       VARCHAR(191) NOT NULL,
  `kind`       VARCHAR(24)  NOT NULL DEFAULT 'phishing',
  `weight`     TINYINT UNSIGNED NOT NULL DEFAULT 50,
  `note`       VARCHAR(191) NOT NULL DEFAULT '',
  `src`        VARCHAR(16)  NOT NULL DEFAULT 'manual',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_host` (`host`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='恶意域名库'
SQL);
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `cloud_heartbeats` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_key`  VARCHAR(64)  NOT NULL,
  `domain`       VARCHAR(191) NOT NULL DEFAULT '',
  `version`      VARCHAR(24)  NOT NULL DEFAULT '',
  `php`          VARCHAR(16)  NOT NULL DEFAULT '',
  `links`        INT UNSIGNED NOT NULL DEFAULT 0,
  `clicks_today` INT UNSIGNED NOT NULL DEFAULT 0,
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `meta`         VARCHAR(500) NOT NULL DEFAULT '',
  `created_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_key_time` (`license_key`, `created_at`),
  KEY `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户站心跳'
SQL);
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `cloud_releases` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`      VARCHAR(24)  NOT NULL,
  `channel`      VARCHAR(16)  NOT NULL DEFAULT 'stable',
  `plan_min`     VARCHAR(32)  NOT NULL DEFAULT 'personal_monthly',
  `url`          VARCHAR(500) NOT NULL DEFAULT '',
  `sha256`       CHAR(64)     NOT NULL DEFAULT '',
  `bytes`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `min_php`      VARCHAR(8)   NOT NULL DEFAULT '8.0',
  `notes`        TEXT         NULL,
  `active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `published_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ver_ch` (`version`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='版本发布记录'
SQL);
        }

        // 5：短链业务上线。链接要能分组、记创建人；域名要能验证归属；多成员与开放 API 各一张表
        if ($from < 5) {
            if (self::hasTable('links')) {
                self::addColumn('links', 'group_name',
                    "ALTER TABLE `links` ADD COLUMN `group_name` VARCHAR(48) NOT NULL DEFAULT '' COMMENT '分组名，空=未分组' AFTER `title`");
                self::addColumn('links', 'note',
                    "ALTER TABLE `links` ADD COLUMN `note` VARCHAR(191) NOT NULL DEFAULT '' COMMENT '备注，只给自己看' AFTER `group_name`");
                self::addColumn('links', 'created_by',
                    "ALTER TABLE `links` ADD COLUMN `created_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '实际创建人，团队协作时区分' AFTER `user_id`");
                self::addIndex('links', 'idx_user_status', 'ALTER TABLE `links` ADD INDEX `idx_user_status` (`user_id`, `status`)');
            }

            if (self::hasTable('user_domains')) {
                self::addColumn('user_domains', 'verify_token',
                    "ALTER TABLE `user_domains` ADD COLUMN `verify_token` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'TXT 验证用的随机串' AFTER `host`");
                self::addColumn('user_domains', 'verified_at',
                    "ALTER TABLE `user_domains` ADD COLUMN `verified_at` DATETIME NULL DEFAULT NULL AFTER `verified`");
                self::addColumn('user_domains', 'last_check_at',
                    "ALTER TABLE `user_domains` ADD COLUMN `last_check_at` DATETIME NULL DEFAULT NULL AFTER `verified_at`");
            }

            if (self::hasTable('clicks')) {
                self::addColumn('clicks', 'source',
                    "ALTER TABLE `clicks` ADD COLUMN `source` VARCHAR(24) NOT NULL DEFAULT '' COMMENT '来源渠道，建索引前先冗余一列' AFTER `referer`");
                self::addIndex('clicks', 'idx_link_day', 'ALTER TABLE `clicks` ADD INDEX `idx_link_day` (`link_id`, `day`)');
                self::addIndex('clicks', 'idx_link_ip', 'ALTER TABLE `clicks` ADD INDEX `idx_link_ip` (`link_id`, `ip_hash`)');
            }

            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `team_members` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id`   INT UNSIGNED NOT NULL COMMENT '工作区所有者',
  `user_id`    INT UNSIGNED NOT NULL COMMENT '被拉进来的成员',
  `role`       VARCHAR(16)  NOT NULL DEFAULT 'member' COMMENT 'member|viewer',
  `status`     VARCHAR(16)  NOT NULL DEFAULT 'active' COMMENT 'active|disabled',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_user` (`owner_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='团队成员'
SQL);
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `name`         VARCHAR(64)  NOT NULL DEFAULT '',
  `prefix`       VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '展示与查找用',
  `key_hash`     CHAR(64)     NOT NULL COMMENT '只存 sha256，拿到明文只有一次',
  `last_used_at` DATETIME     NULL DEFAULT NULL,
  `calls`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `revoked`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hash` (`key_hash`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='开放 API 密钥'
SQL);
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `link_audit` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `actor_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `action`     VARCHAR(32)  NOT NULL,
  `link_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `detail`     VARCHAR(500) NOT NULL DEFAULT '',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_link` (`link_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='链接操作审计'
SQL);
        }

        // 6：版本按 edition 分开登记（开源版与商业版可同号），订单记下载次数
        if ($from < 6) {
            if (self::hasTable('cloud_releases')) {
                self::addColumn('cloud_releases', 'edition',
                    "ALTER TABLE `cloud_releases` ADD COLUMN `edition` VARCHAR(16) NOT NULL DEFAULT 'commercial' COMMENT 'open|commercial' AFTER `version`");
                self::addColumn('cloud_releases', 'file',
                    "ALTER TABLE `cloud_releases` ADD COLUMN `file` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'data/releases 下的文件名' AFTER `url`");
                self::dropIndex('cloud_releases', 'uk_ver_ch');
                self::addIndex('cloud_releases', 'uk_ver_ed_ch',
                    "ALTER TABLE `cloud_releases` ADD UNIQUE KEY `uk_ver_ed_ch` (`version`, `channel`, `edition`)");
            }

            if (self::hasTable('orders')) {
                self::addColumn('orders', 'download_count',
                    "ALTER TABLE `orders` ADD COLUMN `download_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已下载次数' AFTER `issue_tries`");
                self::addColumn('orders', 'download_at',
                    "ALTER TABLE `orders` ADD COLUMN `download_at` DATETIME NULL DEFAULT NULL COMMENT '最近一次下载' AFTER `download_count`");
            }
        }

        // 7：官方构建指纹白名单。客户站上报安装包指纹，这里对不上的不发能力
        if ($from < 7 && !self::hasTable('cloud_builds')) {
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `cloud_builds` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root`       VARCHAR(64)  NOT NULL COMMENT '构建时算的整体指纹',
  `build`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '批次，形如 1.0.0-20261108-2100',
  `edition`    VARCHAR(16)  NOT NULL DEFAULT 'commercial',
  `version`    VARCHAR(32)  NOT NULL DEFAULT '',
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_root` (`root`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='官方构建指纹'
SQL);
        }

        // 8：下载水印溯源。每个专属包一条，指纹与包哈希都能反查到订单
        if ($from < 8) {
            self::runSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `download_traces` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`    VARCHAR(32)  NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0,
  `license_key` VARCHAR(64)  NOT NULL DEFAULT '',
  `email`       VARCHAR(191) NOT NULL DEFAULT '',
  `version`     VARCHAR(24)  NOT NULL DEFAULT '',
  `seq`         INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '同一订单的第几次下载',
  `fingerprint` VARCHAR(24)  NOT NULL COMMENT '包内水印，20 位大写十六进制',
  `build`       VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '构建批次（含 fp8 后缀）',
  `bytes`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sha256`      CHAR(64)     NOT NULL DEFAULT '' COMMENT '成品包哈希，逐字节定人用',
  `file`        VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'data/wm 下的文件名',
  `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
  `ua`          VARCHAR(191) NOT NULL DEFAULT '',
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fp` (`fingerprint`),
  KEY `idx_order` (`order_no`, `created_at`),
  KEY `idx_sha` (`sha256`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='下载水印溯源'
SQL);
            if (self::hasTable('cloud_heartbeats')) {
                self::addColumn('cloud_heartbeats', 'fp',
                    "ALTER TABLE `cloud_heartbeats` ADD COLUMN `fp` VARCHAR(24) NOT NULL DEFAULT '' COMMENT '安装包指纹，空=水印被删或非官方包' AFTER `domain`");
            }
        }
    }

    /** 整段 DDL 拆成单条执行，出错只记日志不中断 */
    private static function runSql(string $sql): void
    {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $one) {
            try {
                Db::pdo()->exec($one);
            } catch (\Throwable $e) {
                error_log('[migrate] DDL 失败：' . $e->getMessage());
            }
        }
    }

    private static function tryExec(string $sql, string $label): void
    {
        try {
            Db::pdo()->exec($sql);
            Db::log('schema.change', $label);
        } catch (\Throwable $e) {
            error_log('[migrate] ' . $label . ' 失败：' . $e->getMessage());
        }
    }

    private static function addIndex(string $table, string $index, string $sql): void
    {
        try {
            $exists = (bool) Db::val(
                'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, $index]
            );
            if ($exists) {
                return;
            }
            Db::pdo()->exec($sql);
            Db::log('schema.change', '补充索引 ' . $table . '.' . $index);
        } catch (\Throwable $e) {
            error_log('[migrate] 索引 ' . $table . '.' . $index . ' 失败：' . $e->getMessage());
        }
    }

    /** 唯一键要换形状时先拆旧的。只删我们自己加过的名字 */
    private static function dropIndex(string $table, string $index): void
    {
        try {
            $exists = (bool) Db::val(
                'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, $index]
            );
            if (!$exists) {
                return;
            }
            Db::pdo()->exec('ALTER TABLE `' . $table . '` DROP INDEX `' . $index . '`');
            Db::log('schema.change', '移除索引 ' . $table . '.' . $index);
        } catch (\Throwable $e) {
            error_log('[migrate] 移除索引 ' . $table . '.' . $index . ' 失败：' . $e->getMessage());
        }
    }

    /** true=可空 false=NOT NULL null=列不存在 */
    private static function columnNullable(string $table, string $column): ?bool
    {
        try {
            $row = Db::one(
                'SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            );
        } catch (\Throwable $e) {
            return null;
        }
        return $row === null ? null : (strtoupper((string) $row['IS_NULLABLE']) === 'YES');
    }

    private static function hasTable(string $table): bool
    {
        try {
            return (bool) Db::val(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function columns(string $table): array
    {
        try {
            $rows = Db::all('SHOW COLUMNS FROM `' . $table . '`');
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(static fn ($r) => (string) $r['Field'], $rows);
    }

    private static function addColumn(string $table, string $column, string $sql): void
    {
        if (in_array($column, self::columns($table), true)) {
            return;
        }
        try {
            Db::pdo()->exec($sql);
            Db::log('schema.change', '补充字段 ' . $table . '.' . $column);
        } catch (\Throwable $e) {
            error_log('[migrate] ' . $table . '.' . $column . ' 失败：' . $e->getMessage());
        }
    }
}
