-- ============================================================
-- FulkLink 福链 · MySQL 5.7+ 数据库结构
-- 字符集 utf8mb4 / 排序 utf8mb4_unicode_ci（5.7 不支持 0900_ai_ci）
-- 时间统一 DATETIME，PHP 侧格式 Y-m-d H:i:s，连接时区 +08:00
-- ============================================================

SET NAMES utf8mb4;

-- ---------- 用户 ----------
CREATE TABLE IF NOT EXISTS `users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`           VARCHAR(191) NULL DEFAULT NULL COMMENT '邮箱注册填这里，手机注册留 NULL',
  `phone`           VARCHAR(32)  NULL DEFAULT NULL COMMENT '手机注册填这里，邮箱注册留 NULL',
  `password_hash`   VARCHAR(191) NOT NULL,
  `name`            VARCHAR(64)  NOT NULL DEFAULT '',
  `role`            VARCHAR(16)  NOT NULL DEFAULT 'user' COMMENT 'user|admin',
  `plan_code`       VARCHAR(32)  NOT NULL DEFAULT 'trial',
  `plan_expires_at` DATETIME     NULL,
  `identity_level`  VARCHAR(16)  NOT NULL DEFAULT 'none' COMMENT 'none|personal|enterprise',
  `real_name`       VARCHAR(64)  NOT NULL DEFAULT '',
  `certified_at`    DATETIME     NULL,
  `status`          VARCHAR(16)  NOT NULL DEFAULT 'active' COMMENT 'active|disabled',
  `last_login_at`   DATETIME     NULL,
  `last_login_ip`   VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  UNIQUE KEY `uk_phone` (`phone`),
  KEY `idx_identity` (`identity_level`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账号';

-- ---------- 会话 ----------
CREATE TABLE IF NOT EXISTS `sessions` (
  `sid`        VARCHAR(64) NOT NULL,
  `user_id`    INT UNSIGNED NULL,
  `role`       VARCHAR(16) NOT NULL DEFAULT 'guest',
  `ip`         VARCHAR(45) NOT NULL DEFAULT '',
  `ua`         VARCHAR(255) NOT NULL DEFAULT '',
  `csrf_token` VARCHAR(64) NOT NULL DEFAULT '',
  `expires_at` DATETIME    NOT NULL,
  `created_at` DATETIME    NOT NULL,
  PRIMARY KEY (`sid`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录会话';

-- ---------- 短链 ----------
CREATE TABLE IF NOT EXISTS `links` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `created_by`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '实际创建人，团队协作时区分',
  `slug`          VARCHAR(48)  NOT NULL,
  `domain`        VARCHAR(100) NOT NULL DEFAULT '',
  `target_url`    VARCHAR(2048) NOT NULL,
  `title`         VARCHAR(191) NOT NULL DEFAULT '',
  `group_name`    VARCHAR(48)  NOT NULL DEFAULT '' COMMENT '分组名，空=未分组',
  `note`          VARCHAR(191) NOT NULL DEFAULT '' COMMENT '备注，只给自己看',
  `status`        VARCHAR(16)  NOT NULL DEFAULT 'active' COMMENT 'active|disabled',
  `password_hash` VARCHAR(191) NOT NULL DEFAULT '',
  `expires_at`    DATETIME     NULL,
  `max_clicks`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=不限',
  `click_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain_slug` (`domain`, `slug`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='短链';

-- ---------- 点击记录 ----------
CREATE TABLE IF NOT EXISTS `clicks` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `link_id`  INT UNSIGNED NOT NULL,
  `ts`       DATETIME NOT NULL,
  `day`      DATE     NOT NULL,
  `ip_hash`  CHAR(32) NOT NULL DEFAULT '',
  `ua`       VARCHAR(512) NOT NULL DEFAULT '',
  `referer`  VARCHAR(512) NOT NULL DEFAULT '',
  `source`   VARCHAR(24)  NOT NULL DEFAULT '' COMMENT '来源渠道，建索引前先冗余一列',
  `device`   VARCHAR(16)  NOT NULL DEFAULT 'unknown' COMMENT 'desktop|mobile|tablet|bot',
  `os`       VARCHAR(32)  NOT NULL DEFAULT '',
  `browser`  VARCHAR(32)  NOT NULL DEFAULT '',
  `region`   VARCHAR(64)  NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_link_ts` (`link_id`, `ts`),
  KEY `idx_link_day` (`link_id`, `day`),
  KEY `idx_link_ip` (`link_id`, `ip_hash`),
  KEY `idx_day` (`day`),
  CONSTRAINT `fk_clicks_link` FOREIGN KEY (`link_id`) REFERENCES `links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='逐次跳转记录';

-- ---------- 客户自有域名 ----------
CREATE TABLE IF NOT EXISTS `user_domains` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `host`         VARCHAR(191) NOT NULL,
  `verify_token` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'TXT 验证用的随机串',
  `verified`     TINYINT(1)   NOT NULL DEFAULT 0,
  `verified_at`  DATETIME     NULL DEFAULT NULL,
  `last_check_at` DATETIME    NULL DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_host` (`host`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户绑定域名';

-- ---------- 团队成员 ----------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='团队成员';

-- ---------- 开放 API 密钥 ----------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='开放 API 密钥';

-- ---------- 链接操作审计 ----------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='链接操作审计';

-- ---------- 订单 ----------
CREATE TABLE IF NOT EXISTS `orders` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`         VARCHAR(32)  NOT NULL,
  `user_id`          INT UNSIGNED NULL,
  `plan_code`        VARCHAR(32)  NOT NULL,
  `amount_cents`     INT UNSIGNED NOT NULL DEFAULT 0,
  `status`           VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending|paid|issued|failed',
  `license_key`      VARCHAR(64)  NOT NULL DEFAULT '',
  `contact`          VARCHAR(191) NOT NULL DEFAULT '' COMMENT '姓名/手机/微信，售后找得到人',
  `email`            VARCHAR(191) NOT NULL DEFAULT '' COMMENT '收授权码的邮箱',
  `remark`           VARCHAR(191) NOT NULL DEFAULT '' COMMENT '开票信息或留言',
  `bind_domain`      VARCHAR(191) NOT NULL DEFAULT '',
  `issue_error`      VARCHAR(512) NOT NULL DEFAULT '',
  `issue_tries`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `identity_level`   VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '下单时认证等级快照',
  `pay_channel`      VARCHAR(16)  NOT NULL DEFAULT '' COMMENT 'wechat|alipay|epay|offline',
  `trade_no`         VARCHAR(64)  NOT NULL DEFAULT '',
  `pay_out_no`       VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '易支付按通道加后缀的对外单号',
  `paid_amount`      INT UNSIGNED NOT NULL DEFAULT 0,
  `pay_qr`           VARCHAR(512) NOT NULL DEFAULT '',
  `pay_url`          VARCHAR(512) NOT NULL DEFAULT '',
  `renew_license_key` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '续费：延长这张码，不新发',
  `upgrade_from`     VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '升级：原版本',
  `created_at`       DATETIME NOT NULL,
  `paid_at`          DATETIME NULL,
  `notified_at`      DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_status_created` (`status`, `created_at`),
  KEY `idx_out_no` (`pay_out_no`),
  KEY `idx_license` (`license_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单';

-- ---------- 授权码 ----------
CREATE TABLE IF NOT EXISTS `licenses` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NULL,
  `user_id`      INT UNSIGNED NOT NULL DEFAULT 0,
  `license_key`  VARCHAR(64)  NOT NULL,
  `plan_code`    VARCHAR(32)  NOT NULL,
  `status`       VARCHAR(16)  NOT NULL DEFAULT 'active' COMMENT 'active|expired|revoked',
  `expires_at`   DATETIME     NULL COMMENT 'NULL=永久',
  `domain`       VARCHAR(191) NOT NULL DEFAULT '',
  `max_domains`  TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '0=不限',
  `source`       VARCHAR(16)  NOT NULL DEFAULT 'local' COMMENT 'local|vendor|mock',
  `verify_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户站点校验次数',
  `last_verify_at` DATETIME   NULL,
  `activated_at` DATETIME     NULL COMMENT '客户首次成功校验并绑定域名的时间',
  `note`         VARCHAR(255) NOT NULL DEFAULT '',
  `raw`          MEDIUMTEXT   NULL,
  `created_at`   DATETIME     NOT NULL,
  `last_sync_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license` (`license_key`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='授权码';

-- ---------- 事件日志 ----------
CREATE TABLE IF NOT EXISTS `events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level`      VARCHAR(8)   NOT NULL DEFAULT 'info' COMMENT 'info|warn|error',
  `kind`       VARCHAR(48)  NOT NULL,
  `message`    VARCHAR(1024) NOT NULL DEFAULT '',
  `meta`       TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_kind_created` (`kind`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='事件日志';

-- ---------- 配置（密钥列加密存储）----------
CREATE TABLE IF NOT EXISTS `settings` (
  `k`          VARCHAR(64) NOT NULL,
  `v`          MEDIUMTEXT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台配置';

-- ---------- 实名 / 企业认证 ----------
CREATE TABLE IF NOT EXISTS `certifications` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `kind`           VARCHAR(16)  NOT NULL COMMENT 'personal|enterprise',
  `status`         VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending|review|approved|rejected|expired',
  `method`         VARCHAR(16)  NOT NULL DEFAULT 'manual' COMMENT 'alipay_face|manual',
  `real_name`      VARCHAR(64)  NOT NULL DEFAULT '',
  `cert_no_enc`    VARCHAR(512) NOT NULL DEFAULT '' COMMENT 'AES-256-GCM 密文',
  `cert_no_mask`   VARCHAR(32)  NOT NULL DEFAULT '',
  `certify_id`     VARCHAR(64)  NOT NULL DEFAULT '',
  `outer_order_no` VARCHAR(64)  NOT NULL DEFAULT '',
  `company_name`   VARCHAR(191) NOT NULL DEFAULT '',
  `credit_code`    VARCHAR(32)  NOT NULL DEFAULT '',
  `legal_name`     VARCHAR(64)  NOT NULL DEFAULT '',
  `license_no`     VARCHAR(64)  NOT NULL DEFAULT '',
  `contact`        VARCHAR(191) NOT NULL DEFAULT '',
  `note`           VARCHAR(512) NOT NULL DEFAULT '',
  `review_note`    VARCHAR(512) NOT NULL DEFAULT '',
  `reviewer_id`    INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL,
  `updated_at`     DATETIME NOT NULL,
  `verified_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `kind`, `created_at`),
  KEY `idx_status` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='实名与企业认证申请';

-- ---------- 版本发布（更新包）----------
CREATE TABLE IF NOT EXISTS `releases` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_code`  VARCHAR(32)  NOT NULL,
  `version`    VARCHAR(32)  NOT NULL,
  `file_path`  VARCHAR(255) NOT NULL,
  `file_size`  INT UNSIGNED NOT NULL DEFAULT 0,
  `sha256`     CHAR(64)     NOT NULL DEFAULT '',
  `changelog`  TEXT NULL,
  `is_current` TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_plan_version` (`plan_code`, `version`),
  KEY `idx_current` (`plan_code`, `is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='各版本发行包';

-- ---------- 验证码（注册/登录/改密共用，只存 HMAC 摘要）----------
CREATE TABLE IF NOT EXISTS `verification_codes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target`     VARCHAR(191) NOT NULL COMMENT '邮箱或手机号，小写归一',
  `channel`    VARCHAR(8)   NOT NULL COMMENT 'email|sms',
  `purpose`    VARCHAR(24)  NOT NULL COMMENT 'register|login|reset|bind',
  `code_hash`  CHAR(64)     NOT NULL COMMENT 'HMAC-SHA256，不存明文',
  `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target`, `purpose`, `created_at`),
  KEY `idx_ip` (`ip`, `created_at`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='一次性验证码';

-- ---------- 授权绑定的域名（一张码可绑多个）----------
CREATE TABLE IF NOT EXISTS `license_domains` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_id` INT UNSIGNED NOT NULL,
  `host`       VARCHAR(191) NOT NULL COMMENT '小写，不含端口',
  `bound_at`   DATETIME NOT NULL,
  `last_seen_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license_host` (`license_id`, `host`),
  KEY `idx_host` (`host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='授权绑定域名';

-- ---------- 云端：资源包 ----------
-- geo / blocklist / templates 都以「全量快照 + 版本号 + sha256」发布，
-- 客户端比对版本，不同就整包替换。不做增量，增量在数据量小的时候更容易出错。
CREATE TABLE IF NOT EXISTS `cloud_assets` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`         VARCHAR(24)  NOT NULL COMMENT 'geo|blocklist|templates|benchmark',
  `version`      VARCHAR(32)  NOT NULL COMMENT '快照版本，客户端用它比对',
  `sha256`       CHAR(64)     NOT NULL,
  `bytes`        INT UNSIGNED NOT NULL DEFAULT 0,
  `items`        INT UNSIGNED NOT NULL DEFAULT 0,
  `payload`      MEDIUMTEXT   NULL,
  `active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `published_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_kind_ver` (`kind`, `version`),
  KEY `idx_kind_active` (`kind`, `active`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='云端下发的资源快照';

-- ---------- 云端：IP 归属地库 ----------
-- 用整数区间存，BIGINT UNSIGNED 容纳 IPv4 的 32 位无符号值。
-- 查询走 ip_start <= X ORDER BY ip_start DESC LIMIT 1，靠 idx_start 命中。
CREATE TABLE IF NOT EXISTS `cloud_geo_blocks` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_start`   BIGINT UNSIGNED NOT NULL,
  `ip_end`     BIGINT UNSIGNED NOT NULL,
  `country`    VARCHAR(32) NOT NULL DEFAULT '中国',
  `province`   VARCHAR(32) NOT NULL DEFAULT '',
  `city`       VARCHAR(32) NOT NULL DEFAULT '',
  `isp`        VARCHAR(32) NOT NULL DEFAULT '',
  `src`        VARCHAR(16) NOT NULL DEFAULT 'manual' COMMENT 'manual|import|resolved',
  `created_at` DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_start` (`ip_start`),
  KEY `idx_end` (`ip_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IP 归属地库';

-- ---------- 云端：未知 IP 样本 ----------
-- 客户站查不到的 IP 上报到这里，补齐后进主库、发布新快照。
-- 这是「数据飞轮」：用得越多库越全，盗版拿不到增量永远停在出厂版本。
CREATE TABLE IF NOT EXISTS `cloud_geo_unknown` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`        VARCHAR(45)  NOT NULL,
  `hits`      INT UNSIGNED NOT NULL DEFAULT 1,
  `status`    VARCHAR(16)  NOT NULL DEFAULT 'open' COMMENT 'open|resolved|ignored',
  `result`    VARCHAR(128) NOT NULL DEFAULT '',
  `first_at`  DATETIME     NOT NULL,
  `last_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip` (`ip`),
  KEY `idx_status_hits` (`status`, `hits`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='待补齐的未知 IP';

-- ---------- 云端：恶意域名黑名单 ----------
CREATE TABLE IF NOT EXISTS `cloud_blocklist` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `host`       VARCHAR(191) NOT NULL,
  `kind`       VARCHAR(24)  NOT NULL DEFAULT 'phishing' COMMENT 'phishing|spam|malware|adult|abuse',
  `weight`     TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT '0-100，命中分',
  `note`       VARCHAR(191) NOT NULL DEFAULT '',
  `src`        VARCHAR(16)  NOT NULL DEFAULT 'manual',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_host` (`host`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='恶意域名库';

-- ---------- 云端：心跳 ----------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户站心跳';

-- ---------- 云端：版本发布 ----------
CREATE TABLE IF NOT EXISTS `cloud_releases` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`      VARCHAR(24)  NOT NULL,
  `channel`      VARCHAR(16)  NOT NULL DEFAULT 'stable' COMMENT 'stable|beta',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='版本发布记录';

-- ---------- 迁移记录 ----------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version`    VARCHAR(32) NOT NULL,
  `applied_at` DATETIME    NOT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据库版本';
