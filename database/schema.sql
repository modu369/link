CREATE TABLE IF NOT EXISTS market_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(128) NOT NULL,
    market_id VARCHAR(128) NOT NULL,
    open_time VARCHAR(64) DEFAULT NULL,
    close_time VARCHAR(64) DEFAULT NULL,
    opening_price DECIMAL(18,8) DEFAULT NULL,
    current_price DECIMAL(18,8) DEFAULT NULL,
    up_price DECIMAL(18,8) DEFAULT NULL,
    down_price DECIMAL(18,8) DEFAULT NULL,
    captured_at VARCHAR(64) NOT NULL
);

CREATE TABLE IF NOT EXISTS trade_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_id VARCHAR(128) NOT NULL,
    side VARCHAR(16) NOT NULL,
    outcome VARCHAR(16) NOT NULL,
    size DECIMAL(18,8) NOT NULL,
    price DECIMAL(18,8) NOT NULL,
    api_status INT NOT NULL,
    api_response LONGTEXT NOT NULL,
    created_at VARCHAR(64) NOT NULL
);
