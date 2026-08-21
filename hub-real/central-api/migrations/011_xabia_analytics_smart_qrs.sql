-- Hub: analítica agregada + registro de Smart QR por licencia (opcional para informes multi-sitio).
-- Ejecutar en la BD del Hub (misma que xabia_licenses). Ajustar si ya existen tablas homónimas.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS xabia_analytics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_id BIGINT UNSIGNED NOT NULL,
    project_id VARCHAR(191) NOT NULL,
    source VARCHAR(191) NOT NULL COMMENT 'web | qr:<id> | tunnel:<ente>',
    tokens_used INT UNSIGNED NOT NULL DEFAULT 0,
    rag_source VARCHAR(64) NULL COMMENT 'csv, sql, mec, woo, multi, etc.',
    event_type VARCHAR(32) NOT NULL DEFAULT 'message' COMMENT 'conversation_start, message, feedback',
    meta_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_license_project_time (license_id, project_id, created_at),
    CONSTRAINT fk_xabia_analytics_license
        FOREIGN KEY (license_id) REFERENCES xabia_licenses (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xabia_smart_qrs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_id BIGINT UNSIGNED NOT NULL,
    project_id VARCHAR(191) NOT NULL,
    qr_id VARCHAR(191) NOT NULL,
    label VARCHAR(255) NULL,
    ente_slug VARCHAR(191) NULL,
    meta_json JSON NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_license_project_qr (license_id, project_id, qr_id),
    CONSTRAINT fk_xabia_smart_qrs_license
        FOREIGN KEY (license_id) REFERENCES xabia_licenses (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
