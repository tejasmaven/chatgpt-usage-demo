-- Safely migrate existing pilot installations to support separate standard/admin keys.

ALTER TABLE settings
    ADD COLUMN IF NOT EXISTS standard_api_key VARCHAR(255) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS admin_api_key VARCHAR(255) NULL AFTER standard_api_key;

-- Preserve existing data from the legacy single key column.
UPDATE settings
SET standard_api_key = COALESCE(standard_api_key, openai_api_key)
WHERE openai_api_key IS NOT NULL;

ALTER TABLE settings
    MODIFY COLUMN standard_api_key VARCHAR(255) NULL,
    MODIFY COLUMN admin_api_key VARCHAR(255) NULL;

ALTER TABLE usage_cache
    ADD COLUMN IF NOT EXISTS last_sync_status VARCHAR(30) NULL AFTER raw_json,
    ADD COLUMN IF NOT EXISTS last_sync_error TEXT NULL AFTER last_sync_status,
    ADD COLUMN IF NOT EXISTS last_http_code INT NULL AFTER last_sync_error,
    ADD COLUMN IF NOT EXISTS last_successful_sync_at DATETIME NULL AFTER last_http_code;
