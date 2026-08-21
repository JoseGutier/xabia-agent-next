-- DTP 1:1 hardening: addon uniqueness + webhook business idempotency + legacy compatibility view.

SET @has_uk_license_addon := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'xabia_addon_activations'
      AND index_name = 'uk_license_addon'
);
SET @sql_add_uk_license_addon := IF(
    @has_uk_license_addon = 0,
    'ALTER TABLE xabia_addon_activations ADD UNIQUE KEY uk_license_addon (license_key, addon_slug)',
    'SELECT 1'
);
PREPARE stmt_add_uk_license_addon FROM @sql_add_uk_license_addon;
EXECUTE stmt_add_uk_license_addon;
DEALLOCATE PREPARE stmt_add_uk_license_addon;

CREATE TABLE IF NOT EXISTS xabia_webhook_business_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(32) NOT NULL,
    event_key VARCHAR(191) NOT NULL,
    webhook_id VARCHAR(128) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_provider_event (provider, event_key),
    KEY idx_webhook_id (webhook_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW xabia_license_addons AS
SELECT
    id,
    license_key,
    addon_slug,
    product_id,
    client_url,
    status,
    expiry_date,
    source,
    created_at,
    updated_at
FROM xabia_addon_activations;
