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
    is_mobile TINYINT(1) DEFAULT 0,
    is_bot TINYINT(1) DEFAULT 0,
    is_unique TINYINT(1) DEFAULT 0,
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_site_time (site_id, occurred_at),
    INDEX idx_site_bot (site_id, is_bot, occurred_at),
    INDEX idx_site_mobile (site_id, is_mobile, occurred_at),
    INDEX idx_site_host (site_id, canonical_host, occurred_at),
    INDEX idx_site_ip (site_id, ip_hash, occurred_at),
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
