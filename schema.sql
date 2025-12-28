CREATE TABLE IF NOT EXISTS sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    tracking_id VARCHAR(32) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pageviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    host VARCHAR(255),
    canonical_host VARCHAR(255),
    path TEXT,
    referrer TEXT,
    user_agent TEXT,
    ip_address VARCHAR(45),
    ip_hash CHAR(64),
    session_id VARCHAR(64),
    duration_seconds INT DEFAULT 0,
    page_count INT DEFAULT 1,
    keyword VARCHAR(255),
    country_name VARCHAR(128),
    region_name VARCHAR(128),
    city_name VARCHAR(128),
    isp_domain VARCHAR(128),
    country_code VARCHAR(8),
    continent_code VARCHAR(8),
    is_mobile TINYINT(1) DEFAULT 0,
    is_bot TINYINT(1) DEFAULT 0,
    is_unique TINYINT(1) DEFAULT 0,
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_site_time (site_id, occurred_at),
    INDEX idx_site_bot (site_id, is_bot, occurred_at),
    INDEX idx_site_mobile (site_id, is_mobile, occurred_at),
    INDEX idx_site_host (site_id, canonical_host, occurred_at),
    INDEX idx_site_ip (site_id, ip_hash, occurred_at),
    INDEX idx_site_country (site_id, country_name, occurred_at),
    INDEX idx_site_region (site_id, region_name, occurred_at),
    INDEX idx_site_isp (site_id, isp_domain, occurred_at),
    INDEX idx_site_session (site_id, session_id),
    CONSTRAINT fk_pageviews_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_domains (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_site_domain (site_id, domain),
    CONSTRAINT fk_site_domains_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS share_pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    site_ids TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pageview_rollups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    bucket_start DATETIME NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    uniques INT UNSIGNED NOT NULL DEFAULT 0,
    ips INT UNSIGNED NOT NULL DEFAULT 0,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    avg_duration DECIMAL(10, 2) NOT NULL DEFAULT 0,
    avg_pages DECIMAL(10, 2) NOT NULL DEFAULT 0,
    bounce_rate DECIMAL(6, 4) NOT NULL DEFAULT 0,
    INDEX idx_rollup_site_time (site_id, bucket_start),
    CONSTRAINT fk_pageview_rollups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pageview_dimension_rollups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    bucket_start DATETIME NOT NULL,
    dimension VARCHAR(64) NOT NULL,
    value VARCHAR(255) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    ips INT UNSIGNED NOT NULL DEFAULT 0,
    uniques INT UNSIGNED NOT NULL DEFAULT 0,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_dimension_site_time (site_id, bucket_start),
    INDEX idx_dimension_key (dimension, value),
    CONSTRAINT fk_dimension_rollups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pageview_page_rollups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    bucket_start DATETIME NOT NULL,
    path TEXT NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    ips INT UNSIGNED NOT NULL DEFAULT 0,
    uniques INT UNSIGNED NOT NULL DEFAULT 0,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    avg_pages DECIMAL(10, 2) NOT NULL DEFAULT 0,
    avg_duration DECIMAL(10, 2) NOT NULL DEFAULT 0,
    bounce_rate DECIMAL(6, 4) NOT NULL DEFAULT 0,
    INDEX idx_page_rollup_site_time (site_id, bucket_start),
    CONSTRAINT fk_page_rollups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pageview_entry_rollups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    bucket_start DATETIME NOT NULL,
    path TEXT NOT NULL,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    ips INT UNSIGNED NOT NULL DEFAULT 0,
    uniques INT UNSIGNED NOT NULL DEFAULT 0,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    avg_pages DECIMAL(10, 2) NOT NULL DEFAULT 0,
    avg_duration DECIMAL(10, 2) NOT NULL DEFAULT 0,
    bounce_rate DECIMAL(6, 4) NOT NULL DEFAULT 0,
    INDEX idx_entry_rollup_site_time (site_id, bucket_start),
    CONSTRAINT fk_entry_rollups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
