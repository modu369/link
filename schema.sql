CREATE TABLE IF NOT EXISTS sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    tracking_id VARCHAR(32) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_domains (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_site_domain (site_id, domain),
    INDEX idx_site_domain (site_id, domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_blocked_domains (
    site_id INT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL,
    log_date DATE NOT NULL,
    pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (site_id, domain, log_date),
    INDEX idx_site_date (site_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS share_pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    site_ids TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 原始事件表（仅保留必要字段）
CREATE TABLE IF NOT EXISTS pageviews (
    id BIGINT UNSIGNED AUTO_INCREMENT,
    site_id INT UNSIGNED NOT NULL,
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    path VARCHAR(2048) NOT NULL,
    referrer VARCHAR(2048),
    user_agent VARCHAR(1024),
    ip_address VARCHAR(45),
    ip_hash CHAR(64),
    session_id VARCHAR(64),
    duration_seconds INT DEFAULT 0,
    page_count INT DEFAULT 1,
    keyword VARCHAR(255),
    is_mobile TINYINT(1) DEFAULT 0,
    is_unique TINYINT(1) DEFAULT 0,
    is_proxy_risk TINYINT(1) DEFAULT 0,
    country_name VARCHAR(128),
    region_name VARCHAR(128),
    city_name VARCHAR(128),
    isp_domain VARCHAR(128),
    country_code VARCHAR(16),
    host VARCHAR(255),
    canonical_host VARCHAR(255),
    PRIMARY KEY (id, site_id),
    INDEX idx_site_time (site_id, occurred_at),
    INDEX idx_site_session (site_id, session_id),
    INDEX idx_site_ip (site_id, ip_hash, occurred_at)
    INDEX idx_site_time_session (site_id, occurred_at, session_id, id);
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
/*!50100 PARTITION BY HASH (site_id) PARTITIONS 64 */;

CREATE TABLE IF NOT EXISTS site_ip_audience (
    site_id INT UNSIGNED NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    first_seen DATETIME NOT NULL,
    last_seen_date DATE NOT NULL,
    PRIMARY KEY (site_id, ip_hash),
    INDEX idx_last_seen_date (last_seen_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
/*!50100 PARTITION BY HASH (site_id) PARTITIONS 64 */;

CREATE TABLE IF NOT EXISTS pageview_rollups (
    site_id INT UNSIGNED NOT NULL,
    bucket_start DATETIME NOT NULL,
    pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uv BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ip_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    session_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    page_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bounce_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (site_id, bucket_start),
    INDEX idx_bucket_time (bucket_start)
    INDEX idx_cover_totals (site_id, bucket_start, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count);
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
/*!50100 PARTITION BY HASH (site_id) PARTITIONS 64 */;

CREATE TABLE IF NOT EXISTS pageview_dimension_rollups (
    site_id INT UNSIGNED NOT NULL,
    bucket_start DATETIME NOT NULL,
    dimension_type VARCHAR(64) NOT NULL,
    dimension_value VARCHAR(255) NOT NULL,
    pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uv BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ip_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    session_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    page_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bounce_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (site_id, bucket_start, dimension_type, dimension_value),
    INDEX idx_dimension_type (dimension_type, dimension_value),
    INDEX idx_dimension_time (bucket_start),
    INDEX idx_query_perf (site_id, dimension_type, bucket_start),
    INDEX idx_share_perf (dimension_type, bucket_start, site_id)
    INDEX idx_cover_query (site_id, dimension_type, bucket_start, dimension_value, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count);
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
/*!50100 PARTITION BY HASH (site_id) PARTITIONS 64 */;

CREATE TABLE IF NOT EXISTS pageview_page_rollups (
    site_id INT UNSIGNED NOT NULL,
    bucket_start DATETIME NOT NULL,
    path VARCHAR(512) NOT NULL,
    pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uv BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ip_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    session_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    page_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bounce_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (site_id, bucket_start, path),
    INDEX idx_page_time (bucket_start),
    INDEX idx_query_perf (site_id, bucket_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
/*!50100 PARTITION BY HASH (site_id) PARTITIONS 64 */;

CREATE TABLE IF NOT EXISTS pageview_entry_rollups (
    site_id INT UNSIGNED NOT NULL,
    bucket_start DATETIME NOT NULL,
    path VARCHAR(512) NOT NULL,
    pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uv BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ip_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    session_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    page_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bounce_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (site_id, bucket_start, path),
    INDEX idx_entry_time (bucket_start),
    INDEX idx_query_perf (site_id, bucket_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
/*!50100 PARTITION BY HASH (site_id) PARTITIONS 64 */;

CREATE TABLE IF NOT EXISTS pageview_bot_logs (
    site_id INT UNSIGNED NOT NULL,
    bucket_start DATETIME NOT NULL,
    domain VARCHAR(255) NOT NULL DEFAULT '',
    path VARCHAR(512) NOT NULL DEFAULT '',
    referrer TEXT NULL,
    user_agent TEXT NULL,
    ip_address VARCHAR(64) NOT NULL DEFAULT '',
    engine VARCHAR(64) NOT NULL DEFAULT '',
    occurred_at DATETIME NOT NULL,
    PRIMARY KEY (site_id, bucket_start, occurred_at, ip_address, path(255)),
    INDEX idx_bot_time (bucket_start),
    INDEX idx_bot_site_time (site_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
/*!50100 PARTITION BY HASH (site_id) PARTITIONS 64 */;

CREATE TABLE IF NOT EXISTS rollup_jobs (
    site_id INT UNSIGNED NOT NULL PRIMARY KEY,
    last_rolled_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;