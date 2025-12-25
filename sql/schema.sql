CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    api_key VARCHAR(255) NOT NULL,
    api_secret VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'offline',
    last_login_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    active_minutes_before_close INT NOT NULL DEFAULT 5,
    buy_shares INT NOT NULL DEFAULT 1,
    up_min INT NOT NULL DEFAULT 90,
    up_max INT NOT NULL DEFAULT 98,
    up_buy_delta DECIMAL(12,2) NOT NULL DEFAULT 60,
    up_sell_stop INT NOT NULL DEFAULT 80,
    down_min INT NOT NULL DEFAULT 90,
    down_max INT NOT NULL DEFAULT 98,
    down_buy_delta DECIMAL(12,2) NOT NULL DEFAULT 60,
    down_sell_stop INT NOT NULL DEFAULT 80,
    sell_on_low_volatility TINYINT(1) NOT NULL DEFAULT 0,
    volatility_threshold DECIMAL(12,2) NOT NULL DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rounds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    external_key VARCHAR(120) NOT NULL,
    open_time DATETIME NOT NULL,
    close_time DATETIME NOT NULL,
    open_price DECIMAL(14,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY rounds_external_key_unique (external_key)
);

CREATE TABLE IF NOT EXISTS trades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    round_id INT NOT NULL,
    side VARCHAR(10) NOT NULL,
    action VARCHAR(10) NOT NULL,
    price DECIMAL(14,2) NOT NULL,
    shares INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE
);
