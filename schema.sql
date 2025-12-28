CREATE TABLE IF NOT EXISTS sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    tracking_id VARCHAR(32) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pageviews (
    site_id INT UNSIGNED NOT NULL,
    path TEXT,
    referrer TEXT,
    user_agent TEXT,
    ip_hash CHAR(64),
    session_id VARCHAR(64),
    is_mobile TINYINT(1) DEFAULT 0,
    is_bot TINYINT(1) DEFAULT 0,
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_site_time (site_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
PARTITION BY HASH(site_id);

CREATE TABLE IF NOT EXISTS pageview_rollups (
    site_id INT UNSIGNED NOT NULL,
    bucket_start TIMESTAMP NOT NULL,
    pageviews INT UNSIGNED DEFAULT 0,
    unique_visitors INT UNSIGNED DEFAULT 0,
    unique_ips INT UNSIGNED DEFAULT 0,
    sessions INT UNSIGNED DEFAULT 0,
    PRIMARY KEY (site_id, bucket_start),
    CONSTRAINT fk_pageview_rollups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pageview_dimension_rollups (
    site_id INT UNSIGNED NOT NULL,
    bucket_start TIMESTAMP NOT NULL,
    dimension_type VARCHAR(64) NOT NULL,
    dimension_value VARCHAR(255) NOT NULL,
    pageviews INT UNSIGNED DEFAULT 0,
    unique_visitors INT UNSIGNED DEFAULT 0,
    unique_ips INT UNSIGNED DEFAULT 0,
    sessions INT UNSIGNED DEFAULT 0,
    PRIMARY KEY (site_id, bucket_start, dimension_type, dimension_value),
    CONSTRAINT fk_pageview_dimension_rollups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pageview_page_rollups (
    site_id INT UNSIGNED NOT NULL,
    bucket_start TIMESTAMP NOT NULL,
    path TEXT NOT NULL,
    pageviews INT UNSIGNED DEFAULT 0,
    unique_visitors INT UNSIGNED DEFAULT 0,
    unique_ips INT UNSIGNED DEFAULT 0,
    sessions INT UNSIGNED DEFAULT 0,
    PRIMARY KEY (site_id, bucket_start, path(255)),
    CONSTRAINT fk_pageview_page_rollups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pageview_entry_rollups (
    site_id INT UNSIGNED NOT NULL,
    bucket_start TIMESTAMP NOT NULL,
    path TEXT NOT NULL,
    pageviews INT UNSIGNED DEFAULT 0,
    unique_visitors INT UNSIGNED DEFAULT 0,
    unique_ips INT UNSIGNED DEFAULT 0,
    sessions INT UNSIGNED DEFAULT 0,
    PRIMARY KEY (site_id, bucket_start, path(255)),
    CONSTRAINT fk_pageview_entry_rollups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_ip_audience (
    site_id INT UNSIGNED NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    first_visit_at TIMESTAMP NOT NULL,
    last_visit_date DATE NOT NULL,
    PRIMARY KEY (site_id, ip_hash),
    CONSTRAINT fk_site_ip_audience_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
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
