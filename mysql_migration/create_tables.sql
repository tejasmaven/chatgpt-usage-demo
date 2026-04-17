CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    standard_api_key VARCHAR(255) NULL,
    admin_api_key VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usage_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usage_date DATE NOT NULL,
    interval_type VARCHAR(20) NOT NULL DEFAULT 'daily',
    total_requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_text_input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_text_output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_cached_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_audio_input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_audio_output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_images BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_cost_usd DECIMAL(15,6) NULL DEFAULT NULL,
    raw_json LONGTEXT NULL,
    last_sync_status VARCHAR(30) NULL,
    last_sync_error TEXT NULL,
    last_http_code INT NULL,
    last_successful_sync_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_usage_date_interval (usage_date, interval_type),
    INDEX idx_interval_date (interval_type, usage_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
