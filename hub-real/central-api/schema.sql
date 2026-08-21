-- Xabia Central Hub — licencias y consumo de tokens (modelo Digixop Translator PRO)
-- MySQL 8.0+ / MariaDB 10.5+ recomendado.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS xabia_licenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_key VARCHAR(255) NOT NULL COMMENT 'Clave pública que envía el plugin (X-Xabia-License)',
    client_domain VARCHAR(255) NOT NULL COMMENT 'Host autorizado, ej. cliente.com (sin protocolo)',
    plan_type VARCHAR(64) NOT NULL DEFAULT 'standard',
    expiry_date DATETIME NULL DEFAULT NULL COMMENT 'NULL = sin caducidad programada',
    status ENUM('active', 'suspended', 'expired') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_license_key_domain (license_key, client_domain),
    KEY idx_client_domain (client_domain),
    KEY idx_status_expiry (status, expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xabia_wallets (
    license_id BIGINT UNSIGNED NOT NULL,
    tokens_remaining BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tokens_used_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (license_id),
    CONSTRAINT fk_xabia_wallet_license
        FOREIGN KEY (license_id) REFERENCES xabia_licenses (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xabia_usage_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_id BIGINT UNSIGNED NOT NULL,
    activity_type VARCHAR(64) NOT NULL COMMENT 'chat, embedding_train, proxy_openai, etc.',
    tokens_count INT UNSIGNED NOT NULL DEFAULT 0,
    `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_url VARCHAR(512) NULL DEFAULT NULL COMMENT 'X-Xabia-Source o site_url del reporte',
    project_id VARCHAR(191) NULL DEFAULT NULL,
    meta_json JSON NULL,
    PRIMARY KEY (id),
    KEY idx_license_time (license_id, `timestamp`),
    CONSTRAINT fk_xabia_usage_license
        FOREIGN KEY (license_id) REFERENCES xabia_licenses (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xabia_addon_activations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_key VARCHAR(255) NOT NULL,
    addon_slug VARCHAR(80) NOT NULL,
    product_id VARCHAR(191) NOT NULL DEFAULT '',
    client_url VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('active', 'inactive', 'expired') NOT NULL DEFAULT 'active',
    source VARCHAR(80) NOT NULL DEFAULT 'polar',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_license_addon (license_key(120), addon_slug),
    KEY idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Datos de ejemplo (opcional): descomentar y sustituir dominio/clave
-- INSERT INTO xabia_licenses (license_key, client_domain, plan_type, expiry_date, status)
-- VALUES ('xabia-demo-xxxxxxxx', 'midominio.com', 'standard', NULL, 'active');
-- INSERT INTO xabia_wallets (license_id, tokens_remaining, tokens_used_total)
-- SELECT id, 1000000, 0 FROM xabia_licenses WHERE license_key = 'xabia-demo-xxxxxxxx';
