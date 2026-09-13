SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET NAMES utf8mb4 */;

-- 1. 站点基础表
CREATE TABLE IF NOT EXISTS `sites` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL DEFAULT '0',
  `name` varchar(255) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `tracking_id` varchar(32) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 2. 站点域名绑定表
CREATE TABLE IF NOT EXISTS `site_domains` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` int UNSIGNED NOT NULL,
  `domain` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` tinyint(1) DEFAULT '1' COMMENT '1=正常, 0=异常(可能被阻断)',
  `last_check_at` timestamp NULL DEFAULT NULL COMMENT '最后检测时间',
  `mute_until` timestamp NULL DEFAULT NULL COMMENT '静默提醒至该时间',
  PRIMARY KEY (`id`),
  KEY `idx_site_domain` (`site_id`,`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 3. 站点被阻断域名表
CREATE TABLE IF NOT EXISTS `site_blocked_domains` (
  `site_id` int UNSIGNED NOT NULL,
  `domain` varchar(255) NOT NULL,
  `log_date` date NOT NULL,
  `pv` bigint UNSIGNED NOT NULL DEFAULT '0',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`site_id`,`domain`,`log_date`),
  KEY `idx_site_date` (`site_id`,`log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4. 站点访客标识表 (哈希分区)
CREATE TABLE IF NOT EXISTS `site_visitor_audience` (
  `site_id` int UNSIGNED NOT NULL,
  `visitor_id` varchar(128) NOT NULL,
  `first_seen` datetime NOT NULL,
  `last_seen_date` date NOT NULL,
  PRIMARY KEY (`site_id`,`visitor_id`),
  KEY `idx_last_seen_date` (`last_seen_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY HASH (`site_id`) PARTITIONS 64;

-- 5. 分享页管理表
CREATE TABLE IF NOT EXISTS `share_pages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL DEFAULT '0',
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `site_ids` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_share_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 6. 系统设置表
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 7. 管理员/用户表
CREATE TABLE IF NOT EXISTS `users` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `nickname` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 8. 原始 PV 记录表 (哈希分区)
CREATE TABLE IF NOT EXISTS `pageviews` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` int UNSIGNED NOT NULL,
  `occurred_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `path` varchar(2048) NOT NULL,
  `referrer` varchar(2048) DEFAULT NULL,
  `user_agent` varchar(1024) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `ip_hash` char(64) DEFAULT NULL,
  `session_id` varchar(64) DEFAULT NULL,
  `visitor_id` varchar(128) DEFAULT NULL,
  `duration_seconds` int DEFAULT '0',
  `page_count` int DEFAULT '1',
  `keyword` varchar(255) DEFAULT NULL,
  `is_mobile` tinyint(1) DEFAULT '0',
  `is_unique` tinyint(1) DEFAULT '0',
  `is_proxy_risk` tinyint(1) DEFAULT '0',
  `country_name` varchar(128) DEFAULT NULL,
  `region_name` varchar(128) DEFAULT NULL,
  `city_name` varchar(128) DEFAULT NULL,
  `isp_domain` varchar(128) DEFAULT NULL,
  `country_code` varchar(16) DEFAULT NULL,
  `host` varchar(255) DEFAULT NULL,
  `canonical_host` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`,`site_id`),
  KEY `idx_site_time` (`site_id`,`occurred_at`),
  KEY `idx_site_session` (`site_id`,`session_id`),
  KEY `idx_site_ip` (`site_id`,`ip_hash`,`occurred_at`),
  KEY `idx_site_host` (`site_id`,`canonical_host`,`occurred_at`),
  KEY `idx_site_mobile` (`site_id`,`is_mobile`,`occurred_at`),
  KEY `idx_site_ref` (`site_id`,`referrer`(120),`occurred_at`),
  KEY `idx_site_time_session` (`site_id`,`occurred_at`,`session_id`,`id`),
  KEY `idx_site_visitor` (`site_id`,`visitor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY HASH (`site_id`) PARTITIONS 64;

-- 9. 会话基础表 (哈希分区)
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` int UNSIGNED NOT NULL,
  `session_id` varchar(64) NOT NULL,
  `visitor_id` varchar(128) DEFAULT NULL,
  `start_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_unique` tinyint(1) DEFAULT '0',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(1024) DEFAULT NULL,
  `entry_path` varchar(2048) DEFAULT NULL,
  `last_path` varchar(2048) DEFAULT NULL,
  `referrer` varchar(2048) DEFAULT NULL,
  `keyword` varchar(255) DEFAULT NULL,
  `engine` varchar(64) DEFAULT NULL,
  `country_name` varchar(128) DEFAULT NULL,
  `region_name` varchar(128) DEFAULT NULL,
  `city_name` varchar(128) DEFAULT NULL,
  `duration_seconds` int DEFAULT '0',
  `page_count` int DEFAULT '1',
  PRIMARY KEY (`id`,`site_id`),
  UNIQUE KEY `uniq_site_session` (`site_id`,`session_id`),
  KEY `idx_site_updated` (`site_id`,`updated_at`),
  KEY `idx_site_start` (`site_id`,`start_time`),
  KEY `idx_site_visitor` (`site_id`,`visitor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY HASH (`site_id`) PARTITIONS 64;

-- 10. 爬虫日志聚合表
CREATE TABLE IF NOT EXISTS `pageview_bot_logs` (
  `site_id` int UNSIGNED NOT NULL,
  `bucket_start` datetime NOT NULL,
  `domain` varchar(255) NOT NULL DEFAULT '',
  `path` varchar(512) NOT NULL DEFAULT '',
  `referrer` text,
  `user_agent` text,
  `ip_address` varchar(64) NOT NULL DEFAULT '',
  `engine` varchar(64) NOT NULL DEFAULT '',
  `occurred_at` datetime NOT NULL,
  PRIMARY KEY (`site_id`,`bucket_start`,`occurred_at`,`ip_address`,`path`(255)),
  KEY `idx_bot_time` (`bucket_start`),
  KEY `idx_bot_site_time` (`site_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 11. 基础时段聚合表 (哈希分区)
CREATE TABLE IF NOT EXISTS `pageview_rollups` (
  `site_id` int UNSIGNED NOT NULL,
  `bucket_start` datetime NOT NULL,
  `pv` bigint UNSIGNED NOT NULL DEFAULT '0',
  `uv` bigint UNSIGNED NOT NULL DEFAULT '0',
  `ip_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  `session_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  `duration_sum` bigint UNSIGNED NOT NULL DEFAULT '0',
  `page_sum` bigint UNSIGNED NOT NULL DEFAULT '0',
  `bounce_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (`site_id`,`bucket_start`),
  KEY `idx_bucket_time` (`bucket_start`),
  KEY `idx_cover_totals` (`site_id`,`bucket_start`,`pv`,`uv`,`ip_count`,`session_count`,`duration_sum`,`page_sum`,`bounce_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY HASH (`site_id`) PARTITIONS 64;

-- 12. 多维分析聚合表 (哈希分区)
CREATE TABLE IF NOT EXISTS `pageview_dimension_rollups` (
  `site_id` int UNSIGNED NOT NULL,
  `bucket_start` datetime NOT NULL,
  `dimension_type` varchar(64) NOT NULL,
  `dimension_value` varchar(255) NOT NULL,
  `pv` bigint UNSIGNED NOT NULL DEFAULT '0',
  `uv` bigint UNSIGNED NOT NULL DEFAULT '0',
  `new_uv` bigint UNSIGNED NOT NULL DEFAULT '0',
  `ip_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  `session_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  `duration_sum` bigint UNSIGNED NOT NULL DEFAULT '0',
  `page_sum` bigint UNSIGNED NOT NULL DEFAULT '0',
  `bounce_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (`site_id`,`bucket_start`,`dimension_type`,`dimension_value`),
  KEY `idx_cover_query` (`site_id`,`dimension_type`,`bucket_start`,`dimension_value`,`pv`,`uv`,`new_uv`,`ip_count`,`session_count`,`duration_sum`,`page_sum`,`bounce_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY HASH (`site_id`) PARTITIONS 64;

-- 13. 受访页面聚合表 (哈希分区)
CREATE TABLE IF NOT EXISTS `pageview_page_rollups` (
  `site_id` int UNSIGNED NOT NULL,
  `bucket_start` datetime NOT NULL,
  `path` varchar(512) NOT NULL,
  `pv` bigint UNSIGNED NOT NULL DEFAULT '0',
  `uv` bigint UNSIGNED NOT NULL DEFAULT '0',
  `ip_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  `session_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  `duration_sum` bigint UNSIGNED NOT NULL DEFAULT '0',
  `page_sum` bigint UNSIGNED NOT NULL DEFAULT '0',
  `bounce_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (`site_id`,`bucket_start`,`path`),
  KEY `idx_query_perf` (`site_id`,`bucket_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY HASH (`site_id`) PARTITIONS 64;

-- 14. 入口页面聚合表 (哈希分区)
CREATE TABLE IF NOT EXISTS `pageview_entry_rollups` (
  `site_id` int UNSIGNED NOT NULL,
  `bucket_start` datetime NOT NULL,
  `path` varchar(512) NOT NULL,
  `pv` bigint UNSIGNED NOT NULL DEFAULT '0',
  `uv` bigint UNSIGNED NOT NULL DEFAULT '0',
  `ip_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  `session_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  `duration_sum` bigint UNSIGNED NOT NULL DEFAULT '0',
  `page_sum` bigint UNSIGNED NOT NULL DEFAULT '0',
  `bounce_count` bigint UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (`site_id`,`bucket_start`,`path`),
  KEY `idx_query_perf` (`site_id`,`bucket_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY HASH (`site_id`) PARTITIONS 64;

-- 15. 聚合任务控制表
CREATE TABLE IF NOT EXISTS `rollup_jobs` (
  `site_id` int UNSIGNED NOT NULL,
  `last_rolled_at` datetime NOT NULL,
  PRIMARY KEY (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

COMMIT;
